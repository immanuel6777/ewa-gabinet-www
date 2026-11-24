<?php
// Mail z bonem (PDF w załączniku) przez Mailjet Send API v3.1 (backend bonów, etap 2: 2026-11-21).
// Wywoływany PO odpowiedzi 200 dla Easytools (webhook.php → fastcgi_finish_request), żeby sklep nie czekał na PDF i wysyłkę.
// Zasady:
//  - tylko bony 'valid' z mail_wyslany_at = NULL (wstrzymane 'held' czekają na zatwierdzenie, bez maila),
//  - jedna wysyłka naraz na zamówienie (blokada MySQL GET_LOCK) – ponowiony webhook w trakcie wysyłki nie wyśle drugi raz,
//    a ponowiony PO nieudanej wysyłce ją dokończy,
//  - quantity N → N PDF-ów, po 10 w jednym mailu (limit Mailjet ok. 15 MB na wiadomość, PDF ok. 0,5 MB),
//  - PDF-y tylko w pamięci, nigdzie nie zapisywane; nieudana wysyłka → powiadomienie admina przez mail().
// mail_tryb = 'plik' (testy): zamiast Mailjet zapis treści wysyłki (bez PDF-ów, same nazwy i rozmiary) do pliku obok konfigu.

declare(strict_types=1);
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/smtp.php';

const BON_PDF_NA_MAIL = 10;

function bon_blokada(PDO $pdo, string $nazwa): bool {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return true; // SQLite (testy): jeden proces
    $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $st->execute([$nazwa]);
    return (int)$st->fetchColumn() === 1;
}
function bon_zwolnij(PDO $pdo, string $nazwa): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$nazwa]);
}

/** Treść maila dla listy bonów jednego zamówienia: [temat, tekst, html]. */
function bon_mail_tresc(array $k, array $bony): array {
    $kontakt = bon_kontakt($k);
    $miesiace = bon_miesiace_tekst((int)($k['waznosc_miesiecy'] ?? 6));
    $n = count($bony);
    $temat = $n === 1 ? 'Twój bon podarunkowy – Gabinet EWA' : "Twoje bony podarunkowe ($n) – Gabinet EWA";
    $lista = [];
    foreach ($bony as $b) {
        $lista[] = [
            'kod' => (string)$b['code'],
            'nazwa' => bon_nazwa($k, $b), // etykieta wg rodzaju bonu (2026-11-24), kwota nadal z nominału
            'kwota' => bon_kwota_tekst((int)bon_grosze($b['initial_pln'])),
            'do' => bon_data_tekst(substr((string)$b['expires_at'], 0, 10)),
            'dla' => (string)($b['dla'] ?? ''),
        ];
    }
    $wstep = $n === 1
        ? 'Dziękujemy za zakup bonu podarunkowego. Bon jest w załączniku jako plik PDF – można go wydrukować albo przesłać dalej.'
        : "Dziękujemy za zakup bonów podarunkowych. Każdy z $n bonów jest w załączniku jako osobny plik PDF – można je wydrukować albo przesłać dalej.";
    $zasady = [
        'Bon jest na zabiegi podologiczne w Gabinecie EWA, ' . $kontakt['ulica'] . ' w Pabianicach.',
        "Niewykorzystana kwota zostaje na bonie na kolejną wizytę. Bon jest ważny $miesiace od dnia zakupu.",
        'Termin wizyty można zarezerwować przez Booksy albo telefonicznie: ' . $kontakt['telefon'] . '. Przy rezerwacji wystarczy podać numer bonu.',
    ];
    // telefon pomocy przy bonach (Bartek 2026-11-24, wpis 09:40): numer Bartka z konfigu, puste = bez linii; wizyty dalej przez gabinet
    $pomoc = trim((string)($k['telefon_pomoc_bony'] ?? ''));
    if ($pomoc !== '') {
        $godz = trim((string)($k['telefon_pomoc_bony_godziny'] ?? ''));
        $zasady[] = 'Pytania o bon lub kłopot z plikiem? Zadzwoń: ' . $pomoc . ($godz !== '' ? " ($godz)" : '') . ' albo odpisz na tego maila.';
    }
    // bon z API Easytools nie ma Dla/Od (API ich nie podaje, Bartek 2026-11-23): na PDF zostają puste linie do wpisania ręcznie
    $puste = array_filter($bony, static fn($b) => trim((string)($b['dla'] ?? '')) === '' || trim((string)($b['od'] ?? '')) === '');
    if ($puste) array_unshift($zasady, ($n === 1 ? 'Na bonie są' : 'Na bonach są') . ' puste linie „Dla” i „Od” – można je wpisać ręcznie po wydrukowaniu.');
    // pełne zasady bonu: regulamin § 14 (BON_REGULAMIN w pdf.php)
    $regulamin = 'Pełne zasady bonu: ' . BON_REGULAMIN;

    $tekst = "Dzień dobry,\n\n$wstep\n\n";
    foreach ($lista as $l) $tekst .= "{$l['nazwa']} o wartości {$l['kwota']}, numer {$l['kod']}, ważny do {$l['do']}" . ($l['dla'] !== '' ? ", dla: {$l['dla']}" : '') . "\n";
    $tekst .= "\n" . implode("\n", $zasady) . "\n" . $regulamin . "\n\nPozdrawiamy serdecznie\nEWA Gabinet podologiczny\nhttps://www.ewa-gabinet.pl\n";

    $e = static fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $wiersze = '';
    foreach ($lista as $l) {
        $wiersze .= '<tr><td style="padding:6px 12px 6px 0;font-weight:bold">' . $e($l['kod']) . '</td><td style="padding:6px 12px 6px 0">' . $e($l['nazwa'] . ' o wartości ' . $l['kwota'])
            . '</td><td style="padding:6px 0">ważny do ' . $e($l['do']) . ($l['dla'] !== '' ? ', dla: ' . $e($l['dla']) : '') . '</td></tr>';
    }
    $html = '<!doctype html><html lang="pl"><body style="margin:0;background:#FBF7F4;font-family:Lato,Arial,sans-serif;color:#17504B;font-size:17px;line-height:1.55">'
        . '<div style="max-width:560px;margin:0 auto;padding:28px 22px;background:#ffffff">'
        . '<p style="font-size:24px;font-weight:300;margin:0 0 16px">Bon podarunkowy</p>'
        . '<p>Dzień dobry,</p><p>' . $e($wstep) . '</p>'
        . '<table style="border-collapse:collapse;margin:8px 0 16px">' . $wiersze . '</table>'
        . '<ul style="padding-left:20px;color:#3E6863">' . implode('', array_map(static fn($z) => '<li>' . $e($z) . '</li>', $zasady)) . '</ul>'
        . '<p style="color:#3E6863">Pełne zasady bonu: <a href="' . $e(BON_REGULAMIN) . '" style="color:#17504B">regulamin bonów</a></p>'
        . '<p>Pozdrawiamy serdecznie<br>EWA Gabinet podologiczny<br><a href="https://www.ewa-gabinet.pl" style="color:#17504B">www.ewa-gabinet.pl</a></p>'
        . '</div></body></html>';
    return [$temat, $tekst, $html];
}

/**
 * Wysyła jedną wiadomość, przy zerwanym połączeniu ponawia (2026-11-23: losowe SSL_ERROR_SYSCALL z OVH do api.mailjet.com).
 * Ponawia TYLKO, gdy połączenie padło przed wysłaniem treści (DNS, TCP, TLS) – wtedy Mailjet na pewno nic nie dostał,
 * więc nie ma ryzyka podwójnego maila. Błąd HTTP albo zerwanie w trakcie wysyłki = bez ponawiania.
 * Zwraca ['blad' => ?string, 'http' => int, 'status' => ?string, 'id' => lista MessageID, 'proby' => int] (bez treści i adresu).
 */
function bon_mailjet_wyslij(array $k, string $do, string $temat, string $tekst, string $html, array $zalaczniki): array {
    $przerwy = (array)($k['mail_ponow_przerwy_s'] ?? [1, 3]); // przerwy przed 2. i 3. próbą
    $wynik = ['blad' => null, 'http' => 0, 'status' => null, 'id' => [], 'proby' => 0];
    $zerwania = [];
    for ($proba = 0; $proba <= count($przerwy); $proba++) {
        if ($proba > 0 && (float)$przerwy[$proba - 1] > 0) usleep((int)(1e6 * (float)$przerwy[$proba - 1]));
        $wynik = ['proby' => $proba + 1] + bon_mailjet_proba($k, $do, $temat, $tekst, $html, $zalaczniki);
        if (empty($wynik['ponow'])) break;
        $zerwania[] = (string)$wynik['blad'];
    }
    if ($zerwania) bon_mailjet_zerwanie($k, 'wysylka', $wynik['proby'], count($zerwania), $zerwania, !empty($wynik['ponow']));
    // 'polaczenie' = ostatnia próba padła przed wysłaniem treści (Mailjet nic nie dostał) – tylko wtedy wolno użyć zapasu SMTP
    $wynik['polaczenie'] = !empty($wynik['ponow']);
    unset($wynik['ponow']);
    return $wynik;
}

/**
 * Zerwanie połączenia z API Mailjeta (2026-11-23, wpis doradcy 22:15): jedno zdarzenie `mailjet-zerwanie` na operację,
 * łatwe do policzenia (częstotliwość, długość okien). Gdy padły WSZYSTKIE próby – od razu krótka sonda:
 * TCP 443 api.mailjet.com, HTTPS api.mailjet.com, SMTP in-v3.mailjet.com:587 (do TLS), HTTPS www.google.com.
 * Limit 3 s na krok (najwyżej ok. 12 s). Wysyłka i kontrola idą zawsze PO odpowiedzi (fastcgi_finish_request) albo
 * w cronie, więc sonda nie wydłuża czekania klienta – dlatego działa na obu drogach.
 */
function bon_mailjet_zerwanie(array $k, string $operacja, int $proby, int $zerwanych, array $bledy, bool $wszystkie): void {
    $dane = ['operacja' => $operacja, 'proby' => $proby, 'zerwane' => $zerwanych, 'wszystkie' => $wszystkie,
        'blad' => mb_substr((string)end($bledy), 0, 160)];
    if ($wszystkie) $dane['sonda'] = bon_mailjet_sonda($k);
    bon_zdarzenie($k, 'mailjet-zerwanie', $dane);
}

function bon_mailjet_sonda(array $k): array {
    if ($k['mail_tryb'] === 'plik') return ['pominieta' => 'tryb plik'];
    require_once __DIR__ . '/diagnostyka-sieci.php';
    $skrot = static fn(array $w) => array_intersect_key($w, array_flip(['ok', 'http', 'ms', 'tcp_ms', 'tls_ms', 'blad_nr', 'blad', 'etap']));
    return [
        'tcp_443_api' => $skrot(bon_siec_tcp('api.mailjet.com', 443, 3)),
        'https_api' => $skrot(bon_siec_https('https://api.mailjet.com/v3/REST/sender', [CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 3])),
        'smtp_587' => $skrot(bon_siec_smtp('in-v3.mailjet.com', 587, 3)),
        'https_google' => $skrot(bon_siec_https('https://www.google.com', [CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 3])),
    ];
}

/** Jedna próba wysyłki. 'ponow' => true tylko przy błędzie połączenia sprzed wysłania treści. */
function bon_mailjet_proba(array $k, string $do, string $temat, string $tekst, string $html, array $zalaczniki): array {
    $zly = static fn(string $blad, bool $ponow = false, int $http = 0) => ['blad' => $blad, 'http' => $http, 'status' => null, 'id' => [], 'ponow' => $ponow];
    $w = bon_mail_wiadomosc($k, $do, $temat, $tekst, $html, $zalaczniki);
    if ($w === null) return $zly('zły mail_nadawca w konfigu (wzór: Nazwa <adres@domena>)');
    $wiadomosc = [
        'From' => ['Email' => $w['od'], 'Name' => $w['od_nazwa']],
        'To' => [['Email' => $w['do']]],
        'Subject' => $w['temat'],
        'TextPart' => $w['tekst'],
        'HTMLPart' => $w['html'],
        'Attachments' => array_map(static fn($z) => ['ContentType' => 'application/pdf', 'Filename' => $z['nazwa'], 'Base64Content' => base64_encode($z['dane'])], $w['zalaczniki']),
        // bez śledzenia otwarć i kliknięć (2026-11-23): mail z Mailjeta = mail z zapasu SMTP (bez piksela i przepisanych linków);
        // kontrola dostarczenia zamyka zapis już na statusie „sent”
        'TrackOpens' => 'disabled',
        'TrackClicks' => 'disabled',
    ];
    if ($w['odpowiedz'] !== '') $wiadomosc['ReplyTo'] = ['Email' => $w['odpowiedz']];

    if ($k['mail_tryb'] === 'plik') {
        $zapis = $wiadomosc;
        $zapis['Attachments'] = array_map(static fn($z) => ['Filename' => $z['nazwa'], 'bajtow' => strlen($z['dane']), 'pdf' => str_starts_with($z['dane'], '%PDF-')], $zalaczniki);
        // testy: zerwane połączenie (przed wysłaniem treści) w kolejnych próbach – licznik w $GLOBALS ustawia test
        if (($GLOBALS['bon_test_zerwania'] ?? 0) > 0) {
            $GLOBALS['bon_test_zerwania']--;
            return $zly('Mailjet: symulowane SSL_ERROR_SYSCALL', true);
        }
        file_put_contents($k['_katalog'] . '/bony-mailjet.jsonl', json_encode($zapis, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        if ($k['mailjet_test_blad'] ?? false) return $zly('symulowany błąd Mailjet', false, 400);
        return ['blad' => null, 'http' => 200, 'status' => 'success', 'id' => [random_int(100000, 999999)], 'ponow' => false];
    }

    $klucz = (string)($k['mailjet_api_key'] ?? '');
    $sekret = (string)($k['mailjet_api_secret'] ?? '');
    if ($klucz === '' || $sekret === '' || str_starts_with($klucz, 'WKLEJ')) return $zly('brak kluczy Mailjet w konfigu');
    $ch = curl_init('https://api.mailjet.com/v3.1/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $klucz . ':' . $sekret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['Messages' => [$wiadomosc]], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    $odp = curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $blad = curl_error($ch);
    $nr = curl_errno($ch);
    $pretransfer = (float)curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME);
    curl_close($ch);
    if ($odp === false) {
        // 6 DNS, 7 TCP, 35 TLS (SSL_ERROR_SYSCALL), 28 timeout przed końcem połączenia – treść nie poszła, ponowienie bezpieczne
        $przed = in_array($nr, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR], true)
            || ($nr === CURLE_OPERATION_TIMEDOUT && $pretransfer == 0.0);
        return $zly('Mailjet: ' . $blad, $przed);
    }
    $j = json_decode((string)$odp, true);
    $status = isset($j['Messages'][0]['Status']) ? (string)$j['Messages'][0]['Status'] : null;
    $id = [];
    foreach ((array)($j['Messages'][0]['To'] ?? []) as $do1) if (isset($do1['MessageID'])) $id[] = $do1['MessageID'];
    if ($kod !== 200 || $status !== 'success') {
        // treść błędu Mailjeta bez adresów e-mail (bon_zdarzenie i tak je maskuje, ale powiadomienie idzie mailem)
        $opis = preg_replace('/[^\s@"]+@[^\s@"]+/', '***', mb_substr((string)$odp, 0, 500)) ?? '';
        return ['blad' => "Mailjet HTTP $kod: $opis", 'http' => $kod, 'status' => $status, 'id' => $id, 'ponow' => false];
    }
    return ['blad' => null, 'http' => $kod, 'status' => $status, 'id' => $id, 'ponow' => false];
}

/**
 * Znacznik „powiadomiono o nieudanej wysyłce” dla zamówienia (plik w konfig/bony-mail-bledy/, bez zmian w bazie).
 * $blad = true: zwraca true, gdy powiadomienie należy wysłać (pierwszy błąd albo minęła doba), i zapisuje czas.
 * $blad = false (wysyłka udana): usuwa znacznik.
 */
function bon_powiadomienie_bledu_czas(array $k, string $zamowienie, bool $blad, int $coIleS = 86400): bool {
    if ((string)($k['_katalog'] ?? '') === '') return $blad;
    $katalog = $k['_katalog'] . '/bony-mail-bledy';
    $plik = $katalog . '/' . md5($zamowienie) . '.txt';
    if (!$blad) { if (is_file($plik)) @unlink($plik); return false; }
    $ostatnio = is_file($plik) ? (int)@file_get_contents($plik) : 0;
    if ($ostatnio > 0 && time() - $ostatnio < $coIleS) return false;
    if (is_dir($katalog) || @mkdir($katalog, 0700, true)) @file_put_contents($plik, (string)time(), LOCK_EX);
    return true;
}

/**
 * Wysyła PDF-y niewysłanych, ważnych bonów zamówienia. Bezpieczne do wielokrotnego wywołania.
 * Zwraca ['wyslane' => liczba bonów, 'blad' => ?string, 'pominieto' => ?string].
 */
function bon_wyslij_zamowienie(array $k, PDO $pdo, string $zamowienie): array {
    $tab = bon_tabela($k, 'vouchers');
    // skrót, bo GET_LOCK przyjmuje najwyżej 64 znaki, a order_id z Easytools może mieć do 64
    $blokada = 'bon_mail_' . md5($tab . '|' . $zamowienie);
    if (!bon_blokada($pdo, $blokada)) return ['zamowienie' => $zamowienie, 'wyslane' => 0, 'blad' => null, 'pominieto' => 'wysyłka w toku'];
    try {
        $st = $pdo->prepare("SELECT * FROM $tab WHERE easytools_order_id = ? AND status = 'valid' AND mail_wyslany_at IS NULL ORDER BY nr_w_zamowieniu");
        $st->execute([$zamowienie]);
        $bony = $st->fetchAll();
        if (!$bony) return ['zamowienie' => $zamowienie, 'wyslane' => 0, 'blad' => null, 'pominieto' => 'nic do wysłania'];

        // bony tego zamówienia wysłane we wcześniejszej próbie (np. 1. paczka przeszła, 2. nie) – powiadomienie o sprzedaży i tak idzie
        $wczesniej = (int)$pdo->query("SELECT COUNT(*) FROM $tab WHERE easytools_order_id = " . $pdo->quote($zamowienie) . ' AND mail_wyslany_at IS NOT NULL')->fetchColumn();
        $wyslane = 0;
        $blad = null;
        $mailjet = [];
        $oznacz = $pdo->prepare("UPDATE $tab SET mail_wyslany_at = ? WHERE id = ? AND mail_wyslany_at IS NULL");
        foreach (array_chunk($bony, BON_PDF_NA_MAIL) as $paczka) {
            [$temat, $tekst, $html] = bon_mail_tresc($k, $paczka);
            $zalaczniki = array_map(static fn($b) => ['nazwa' => 'bon-' . $b['code'] . '.pdf', 'dane' => bon_pdf($b, $k)], $paczka);
            $do = (string)$paczka[0]['buyer_email'];
            $wymus = bon_zapas_wymuszony($k);
            $odp = $wymus
                ? ['blad' => 'test zapasu (mail_zapas_test_do)', 'http' => 0, 'status' => null, 'id' => [], 'proby' => 0, 'polaczenie' => true]
                : bon_mailjet_wyslij($k, $do, $temat, $tekst, $html, $zalaczniki);
            $wpis = ['droga' => 'mailjet', 'http' => $odp['http'], 'status' => $odp['status'], 'id' => $odp['id'], 'proby' => $odp['proby']];
            $mozliwe = false;
            // zapas SMTP OVH (22:20): tylko gdy Mailjet nic nie dostał (błąd połączenia przed treścią); błąd HTTP = bez zapasu
            if ($odp['blad'] !== null && !empty($odp['polaczenie']) && bon_zapas_wlaczony($k)) {
                $w = bon_mail_wiadomosc($k, $do, $temat, $tekst, $html, $zalaczniki);
                $s = $w === null ? ['blad' => 'SMTP: zły mail_nadawca', 'kod' => 0, 'odp' => '', 'mozliwe_wyslane' => false] : bon_smtp_wyslij($k, $w);
                $wpis = ['droga' => 'smtp-ovh', 'mailjet_blad' => mb_substr((string)$odp['blad'], 0, 160), 'smtp_kod' => $s['kod'], 'smtp_odp' => $s['odp']];
                if ($wymus) $wpis['test_zapasu'] = true;
                $mozliwe = $s['mozliwe_wyslane'];
                $odp['blad'] = $s['blad'] === null ? null : $odp['blad'] . ' | zapas ' . $s['blad'];
            }
            unset($zalaczniki);
            $mailjet[] = $wpis;
            $blad = $odp['blad'];
            if ($mozliwe) {
                // SMTP zerwał się po przesłaniu treści: serwer mógł mail przyjąć. Bez automatycznej ponownej wysyłki (bez dubli):
                // bon oznaczony jako wysłany, gabinet dostaje jedno powiadomienie do sprawdzenia u kupującego.
                $teraz = bon_teraz()->format('Y-m-d H:i:s');
                foreach ($paczka as $b) {
                    $oznacz->execute([$teraz, $b['id']]);
                    bon_historia($k, $pdo, (int)$b['id'], 'mail', 'PDF wysłany zapasowo (SMTP OVH) – dostarczenie niepewne', 'system');
                }
                bon_zdarzenie($k, 'mail-niepewny', ['zamowienie' => $zamowienie, 'kody' => array_map('bon_kod_maska', array_column($paczka, 'code')), 'blad' => $blad]);
                bon_powiadom($k, 'Bon: mail MOŻE nie dotrzeć – zamówienie ' . $zamowienie, "Zapasowa wysyłka (SMTP OVH) zerwała się po przesłaniu treści – serwer mógł mail przyjąć.\n"
                    . 'Bony: ' . implode(', ', array_column($paczka, 'code')) . "\nBłąd: $blad\n"
                    . "Wysyłka NIE będzie ponawiana automatycznie (żeby kupujący nie dostał dwóch maili). Sprawdź u kupującego; w razie potrzeby wyślij bon ponownie.");
                $blad = null;
                $wyslane += count($paczka);
                continue;
            }
            if ($blad !== null) break;
            $teraz = bon_teraz()->format('Y-m-d H:i:s');
            foreach ($paczka as $b) {
                $oznacz->execute([$teraz, $b['id']]);
                bon_historia($k, $pdo, (int)$b['id'], 'mail', 'PDF wysłany do kupującego' . ($wpis['droga'] === 'smtp-ovh' ? ' (zapasowo przez SMTP OVH)' : ''), 'system');
                $wyslane++;
            }
            // tryb „plik” (testy, też test-ovh.php na produkcji): bez zapisu do kontroli – zmyślone MessageID nie mogą trafić do crona;
            // droga SMTP: bez kontroli po MessageID (zapis „przyjęte przez serwer OVH” w zdarzeniu mail)
            if ($k['mail_tryb'] !== 'plik' && $wpis['droga'] === 'mailjet') bon_mail_zapisz_wysylke($k, $zamowienie, array_column($paczka, 'code'), $odp['id']);
        }

        $kody = implode(', ', array_column($bony, 'code'));
        if ($blad !== null) {
            error_log("bony: wysyłka zamówienia $zamowienie nieudana: $blad");
            // cron ponawia wysyłkę co godzinę – powiadomienie przy pierwszej nieudanej próbie, potem najwyżej raz na dobę (2026-11-23)
            if (bon_powiadomienie_bledu_czas($k, $zamowienie, true)) {
                bon_powiadom($k, 'Bon: NIE wysłano maila – zamówienie ' . $zamowienie, "Kupujący nie dostał PDF-a z bonem.\nBłąd: $blad\n"
                    . "Bony: {$kody}\nWysłane w tej próbie: $wyslane\n"
                    . "Synchronizacja (cron co godzinę) sama ponawia wysyłkę. Kolejne przypomnienie o tym zamówieniu najwcześniej za 24 h.");
            }
        } else {
            bon_powiadomienie_bledu_czas($k, $zamowienie, false);
            $suma = array_sum(array_map(static fn($b) => (int)bon_grosze($b['initial_pln']), $bony));
            bon_powiadom($k, 'Sprzedano bon – ' . bon_kwota_tekst($suma), "Zamówienie Easytools: $zamowienie\nBony: $kody\n"
                . 'Kupujący dostał PDF mailem (' . (string)$bony[0]['buyer_email'] . ").\n"
                . (in_array('smtp-ovh', array_column($mailjet, 'droga'), true) ? "Droga: zapasowa (SMTP OVH) – Mailjet był nieosiągalny.\n" : '')
                . ($wczesniej ? "Dokończona wysyłka: $wczesniej bon(y) z tego zamówienia poszły we wcześniejszej próbie.\n" : ''));
        }
        return ['zamowienie' => $zamowienie, 'wyslane' => $wyslane, 'blad' => $blad, 'pominieto' => null, 'mailjet' => $mailjet];
    } finally {
        bon_zwolnij($pdo, $blokada);
    }
}

// --- kontrola dostarczenia (2026-11-23): Mailjet odpowiada „success” także wtedy, gdy wiadomość potem zatrzyma
// (np. niezweryfikowany nadawca 23.11 o 18:00), więc cron sprawdza status po MessageID. Zapis w plikach obok konfigu
// (konfig/bony-mail-wysylki/), bez zmian w bazie. Pliki starsze niż 30 dni są usuwane.

const BON_MAIL_DOSTARCZONO = ['sent', 'opened', 'clicked'];
const BON_MAIL_PROBLEM = ['bounce', 'hardbounced', 'softbounced', 'spam', 'unsub', 'blocked'];

function bon_mail_katalog_wysylek(array $k): ?string {
    if ((string)($k['_katalog'] ?? '') === '') return null;
    $katalog = $k['_katalog'] . '/bony-mail-wysylki';
    return is_dir($katalog) || @mkdir($katalog, 0700, true) ? $katalog : null;
}

/** Zapis jednej wysyłki do kontroli dostarczenia: zamówienie, kody bonów, MessageID (bez adresu i treści). */
function bon_mail_zapisz_wysylke(array $k, string $zamowienie, array $kody, array $id): void {
    $katalog = bon_mail_katalog_wysylek($k);
    if ($katalog === null || !$id) return;
    $wpis = ['zamowienie' => $zamowienie, 'kody' => array_values($kody), 'id' => array_values($id), 'wyslano' => time(),
        'status' => null, 'sprawdzono' => null, 'powiadomiono' => false];
    @file_put_contents($katalog . '/' . md5($zamowienie . '|' . implode(',', $id)) . '.json', json_encode($wpis, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Status wiadomości z Mailjeta: GET /v3/REST/message/{id}.
 * Zwraca ['status' => małymi literami albo null (jeszcze nie widać / błąd), 'http' => kod, 'blad' => opis albo null]
 * – http i blad trafiają do dziennika (mail-kontrola), żeby „czeka” dało się wyjaśnić. Bez treści odpowiedzi (adresy).
 */
function bon_mailjet_status(array $k, int|string $id): array {
    // ponawianie jak przy wysyłce (1 s, 3 s), ale przy KAŻDYM błędzie połączenia – GET niczego nie wysyła
    $przerwy = (array)($k['mail_ponow_przerwy_s'] ?? [1, 3]);
    $zerwania = [];
    for ($proba = 0; $proba <= count($przerwy); $proba++) {
        if ($proba > 0 && (float)$przerwy[$proba - 1] > 0) usleep((int)(1e6 * (float)$przerwy[$proba - 1]));
        $wynik = ['proby' => $proba + 1] + bon_mailjet_status_proba($k, $id);
        if (empty($wynik['zerwane'])) break;
        $zerwania[] = (string)$wynik['blad'];
    }
    if ($zerwania) bon_mailjet_zerwanie($k, 'status', $wynik['proby'], count($zerwania), $zerwania, !empty($wynik['zerwane']));
    unset($wynik['zerwane']);
    return $wynik;
}

/**
 * Czy nadawca z mail_nadawca jest zatwierdzony w Mailjet (konto z kluczem z konfigu) – do log.php (2026-11-24, wpis 12:10:
 * niezatwierdzony nadawca = Mailjet odrzuca mail, a zapas SMTP tego nie ratuje, bo to nie błąd połączenia; tak było 23.11).
 * Sprawdza adres i całą domenę („*@domena”). Bez adresu w wyniku (log.php nie pokazuje adresów). 'zatwierdzony' null = nie wiadomo.
 */
function bon_mailjet_nadawca(array $k): array {
    if (($k['mail_tryb'] ?? '') === 'plik') return ['zatwierdzony' => null, 'pominieto' => 'tryb plik'];
    $w = bon_mail_wiadomosc($k, 'x@example.com', '', '', '', []);
    $adres = (string)($w['od'] ?? '');
    if ($adres === '') return ['zatwierdzony' => null, 'blad' => 'zły mail_nadawca'];
    $wynik = ['zatwierdzony' => false, 'adres' => null, 'domena' => null];
    foreach (['adres' => $adres, 'domena' => '*' . substr($adres, (int)strrpos($adres, '@'))] as $co => $email) {
        $ch = curl_init('https://api.mailjet.com/v3/REST/sender?' . http_build_query(['Email' => $email]));
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => (string)($k['mailjet_api_key'] ?? '') . ':' . (string)($k['mailjet_api_secret'] ?? ''),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6,
        ]);
        $odp = curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $blad = $odp === false ? 'cURL ' . curl_errno($ch) . ': ' . curl_error($ch) : null;
        curl_close($ch);
        if ($blad !== null || $kod !== 200) { $wynik[$co] = $blad ?? "HTTP $kod"; continue; }
        $dane = (array)(json_decode((string)$odp, true)['Data'] ?? []);
        $wynik[$co] = $dane ? (string)($dane[0]['Status'] ?? '?') : 'brak na liście nadawców';
        if (in_array('Active', array_column($dane, 'Status'), true)) $wynik['zatwierdzony'] = true;
    }
    if (!$wynik['zatwierdzony'] && (str_starts_with((string)$wynik['adres'], 'cURL') || str_starts_with((string)$wynik['adres'], 'HTTP'))) $wynik['zatwierdzony'] = null;
    return $wynik;
}

/** Jedno zapytanie o status. 'zerwane' => true = błąd połączenia (cURL), Mailjet nic nie odpowiedział. */
function bon_mailjet_status_proba(array $k, int|string $id): array {
    if ($k['mail_tryb'] === 'plik') {
        if (($GLOBALS['bon_test_zerwania_status'] ?? 0) > 0) {
            $GLOBALS['bon_test_zerwania_status']--;
            return ['status' => null, 'http' => 0, 'blad' => 'cURL 35: symulowane SSL_ERROR_SYSCALL', 'zerwane' => true];
        }
        $http = (int)($k['mailjet_test_status_http'] ?? 200);
        return ['status' => $http === 200 ? (isset($k['mailjet_test_status']) ? (string)$k['mailjet_test_status'] : 'sent') : null,
            'http' => $http, 'blad' => $http === 200 ? null : 'HTTP ' . $http];
    }
    $ch = curl_init('https://api.mailjet.com/v3/REST/message/' . rawurlencode((string)$id));
    curl_setopt_array($ch, [
        CURLOPT_USERPWD => (string)($k['mailjet_api_key'] ?? '') . ':' . (string)($k['mailjet_api_secret'] ?? ''),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $odp = curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $bladCurl = $odp === false ? 'cURL ' . curl_errno($ch) . ': ' . curl_error($ch) : null;
    curl_close($ch);
    if ($bladCurl !== null) return ['status' => null, 'http' => $kod, 'blad' => $bladCurl, 'zerwane' => true];
    $j = json_decode((string)$odp, true);
    if ($kod !== 200) {
        $opis = is_array($j) ? (string)($j['ErrorMessage'] ?? $j['ErrorInfo'] ?? '') : '';
        return ['status' => null, 'http' => $kod, 'blad' => trim('HTTP ' . $kod . ' ' . mb_substr($opis, 0, 200))];
    }
    $s = is_array($j) ? ($j['Data'][0]['Status'] ?? null) : null;
    return ['status' => is_string($s) ? strtolower($s) : null, 'http' => $kod,
        'blad' => is_string($s) ? null : 'brak pola Status (Count: ' . (is_array($j) ? (string)($j['Count'] ?? '?') : 'nie JSON') . ')'];
}

/**
 * Kontrola dostarczenia (cron): wysyłki sprzed co najmniej 10 min bez ostatecznego statusu.
 * - wszystkie wiadomości sent/opened/clicked → zamknięte;
 * - którakolwiek bounce/spam/blocked… → zdarzenie + JEDNO powiadomienie gabinetu;
 * - po 24 h Mailjet nadal podaje status niedostarczenia (queued, deferred…) → zdarzenie + jedno powiadomienie „niepotwierdzone”;
 * - zapytania nie przechodzą (zerwanie, błąd HTTP) → czeka; po 72 h jedno powiadomienie „nie da się sprawdzić”, kontrola trwa dalej.
 * Zwraca podsumowanie do dziennika.
 */
function bon_mail_kontrola(array $k, int $teraz = 0, int $minWiekS = 600, ?PDO $pdo = null): array {
    $teraz = $teraz ?: time();
    $katalog = bon_mail_katalog_wysylek($k);
    $wynik = ['sprawdzono' => 0, 'dostarczone' => 0, 'problem' => 0, 'czeka' => 0];
    if ($katalog === null) return $wynik;
    foreach (glob($katalog . '/*.json') ?: [] as $plik) {
        $w = json_decode((string)@file_get_contents($plik), true);
        if (!is_array($w)) continue;
        $wiek = $teraz - (int)($w['wyslano'] ?? 0);
        if ($wiek > 30 * 86400) { @unlink($plik); continue; }
        if (($w['status'] ?? null) !== null || $wiek < $minWiekS) continue;

        $odpowiedzi = [];
        foreach ((array)($w['id'] ?? []) as $id) $odpowiedzi[] = ['id' => $id] + bon_mailjet_status($k, $id);
        $statusy = array_map(static fn($o) => $o['status'], $odpowiedzi);
        $wynik['sprawdzono']++;
        $wynik['mailjet'][] = ['zamowienie' => $w['zamowienie'] ?? null, 'odpowiedzi' => $odpowiedzi];
        $w['odpowiedzi'] = $odpowiedzi;
        $w['sprawdzono'] = $teraz;
        $kody = implode(', ', (array)($w['kody'] ?? []));
        $zle = array_values(array_filter($statusy, static fn($s) => in_array($s, BON_MAIL_PROBLEM, true)));
        if ($zle) {
            $w['status'] = 'problem: ' . implode(',', array_unique($zle));
            $wynik['problem']++;
            bon_zdarzenie($k, 'mail-dostarczenie', ['zamowienie' => $w['zamowienie'], 'status' => $w['status'], 'id' => $w['id']]);
            if (!$w['powiadomiono']) {
                // TA-11 (2026-11-24): kupujący i odbity adres z bazy (plik kontroli trzyma tylko kody i MessageID), wpis „mail-odbity”
                // w historii każdego bonu = znacznik w panelu do udanej ponownej wysyłki. Powiadomienie idzie do gabinetu (mail_powiadomienie).
                $kto = '';
                if ($pdo && !empty($w['kody'])) {
                    try {
                        $kodyL = array_values((array)$w['kody']);
                        $st = $pdo->prepare('SELECT id, buyer_email, buyer_name FROM ' . bon_tabela($k, 'vouchers') . ' WHERE code IN (' . implode(',', array_fill(0, count($kodyL), '?')) . ')');
                        $st->execute($kodyL);
                        $wiersze = $st->fetchAll();
                        foreach ($wiersze as $b) bon_historia($k, $pdo, (int)$b['id'], 'mail-odbity', 'Mailjet: ' . implode(', ', array_unique($zle)) . '; adres: ' . $b['buyer_email'], 'system');
                        if ($wiersze) $kto = 'Kupujący: ' . ($wiersze[0]['buyer_name'] ?: '(bez imienia)') . "\nOdbity adres: " . $wiersze[0]['buyer_email'] . "\n";
                    } catch (Throwable $e) {
                        bon_zdarzenie($k, 'blad', ['plik' => 'kontrola (odbicie)'] + bon_opis_bledu($e));
                    }
                }
                bon_powiadom($k, 'Bon: mail NIE dotarł – zamówienie ' . $w['zamowienie'], "Mailjet przyjął mail z bonem, ale go nie dostarczył.\n"
                    . 'Status: ' . implode(', ', array_unique($zle)) . "\nBony: $kody\n" . $kto
                    . "Panel bonów → otwórz bon → „Wyślij ponownie” (można poprawić adres po potwierdzeniu z kupującym).");
                $w['powiadomiono'] = true;
            }
        } elseif ($statusy && count(array_filter($statusy, static fn($s) => in_array($s, BON_MAIL_DOSTARCZONO, true))) === count($statusy)) {
            $w['status'] = 'dostarczono';
            $wynik['dostarczone']++;
        } else {
            $wynik['czeka']++;
            // „niepotwierdzone” tylko, gdy Mailjet ODPOWIEDZIAŁ statusem dla każdej wiadomości (queued, deferred…).
            // Gdy zapytanie się nie udało (zerwanie, błąd HTTP), to nie jest informacja o mailu: kontrola czeka dalej,
            // a po 72 h idzie jedno powiadomienie „nie da się sprawdzić” (wpis doradcy 2026-11-23 22:15).
            $odpowiedzial = $statusy && !in_array(null, $statusy, true);
            if (!$odpowiedzial && $wiek >= 3 * 86400 && empty($w['powiadomiono_brak_odpowiedzi'])) {
                bon_zdarzenie($k, 'mail-dostarczenie', ['zamowienie' => $w['zamowienie'], 'status' => 'nie da się sprawdzić (72 h)', 'id' => $w['id'],
                    'blad' => (string)($odpowiedzi[0]['blad'] ?? '')]);
                bon_powiadom($k, 'Bon: nie da się sprawdzić dostarczenia – zamówienie ' . $w['zamowienie'], "Od 72 h serwer nie może zapytać Mailjeta o status maila z bonem.\n"
                    . 'Ostatni błąd: ' . (string)($odpowiedzi[0]['blad'] ?? '?') . "\nBony: $kody\n"
                    . "To nie znaczy, że mail nie doszedł. Sprawdź w Mailjecie (Statystyki → wiadomość) albo u kupującego.");
                $w['powiadomiono_brak_odpowiedzi'] = true;
            }
            if ($odpowiedzial && $wiek >= 86400) {
                $w['status'] = 'niepotwierdzone: ' . implode(',', array_map(static fn($s) => $s ?? 'brak', $statusy));
                bon_zdarzenie($k, 'mail-dostarczenie', ['zamowienie' => $w['zamowienie'], 'status' => $w['status'], 'id' => $w['id']]);
                if (!$w['powiadomiono']) {
                    bon_powiadom($k, 'Bon: brak potwierdzenia dostarczenia – zamówienie ' . $w['zamowienie'], "Po 24 h Mailjet nadal nie potwierdza dostarczenia maila z bonem.\n"
                        . 'Status: ' . $w['status'] . "\nBony: $kody\nSprawdź w Mailjecie i u kupującego.");
                    $w['powiadomiono'] = true;
                }
            }
        }
        @file_put_contents($plik, json_encode($w, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $wynik;
}
