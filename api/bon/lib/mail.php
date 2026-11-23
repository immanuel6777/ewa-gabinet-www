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
    // bon z API Easytools nie ma Dla/Od (API ich nie podaje, Bartek 2026-11-23): na PDF zostają puste linie do wpisania ręcznie
    $puste = array_filter($bony, static fn($b) => trim((string)($b['dla'] ?? '')) === '' || trim((string)($b['od'] ?? '')) === '');
    if ($puste) array_unshift($zasady, ($n === 1 ? 'Na bonie są' : 'Na bonach są') . ' puste linie „Dla” i „Od” – można je wpisać ręcznie po wydrukowaniu.');
    // pełne zasady bonu: regulamin § 14 (BON_REGULAMIN w pdf.php)
    $regulamin = 'Pełne zasady bonu: ' . BON_REGULAMIN;

    $tekst = "Dzień dobry,\n\n$wstep\n\n";
    foreach ($lista as $l) $tekst .= "Bon {$l['kod']}: {$l['kwota']}, ważny do {$l['do']}" . ($l['dla'] !== '' ? ", dla: {$l['dla']}" : '') . "\n";
    $tekst .= "\n" . implode("\n", $zasady) . "\n" . $regulamin . "\n\nPozdrawiamy serdecznie\nEWA Gabinet podologiczny\nhttps://www.ewa-gabinet.pl\n";

    $e = static fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $wiersze = '';
    foreach ($lista as $l) {
        $wiersze .= '<tr><td style="padding:6px 12px 6px 0;font-weight:bold">' . $e($l['kod']) . '</td><td style="padding:6px 12px 6px 0">' . $e($l['kwota'])
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
    for ($proba = 0; $proba <= count($przerwy); $proba++) {
        if ($proba > 0 && (float)$przerwy[$proba - 1] > 0) usleep((int)(1e6 * (float)$przerwy[$proba - 1]));
        $wynik = ['proby' => $proba + 1] + bon_mailjet_proba($k, $do, $temat, $tekst, $html, $zalaczniki);
        if (empty($wynik['ponow'])) break;
    }
    unset($wynik['ponow']);
    return $wynik;
}

/** Jedna próba wysyłki. 'ponow' => true tylko przy błędzie połączenia sprzed wysłania treści. */
function bon_mailjet_proba(array $k, string $do, string $temat, string $tekst, string $html, array $zalaczniki): array {
    $zly = static fn(string $blad, bool $ponow = false, int $http = 0) => ['blad' => $blad, 'http' => $http, 'status' => null, 'id' => [], 'ponow' => $ponow];
    if (!preg_match('/^(.*)<([^>]+)>\s*$/', (string)($k['mail_nadawca'] ?? ''), $m)) return $zly('zły mail_nadawca w konfigu (wzór: Nazwa <adres@domena>)');
    $wiadomosc = [
        'From' => ['Email' => trim($m[2]), 'Name' => trim($m[1])],
        'To' => [['Email' => $do]],
        'Subject' => $temat,
        'TextPart' => $tekst,
        'HTMLPart' => $html,
        'Attachments' => array_map(static fn($z) => ['ContentType' => 'application/pdf', 'Filename' => $z['nazwa'], 'Base64Content' => base64_encode($z['dane'])], $zalaczniki),
    ];
    if (!empty($k['mail_odpowiedz'])) $wiadomosc['ReplyTo'] = ['Email' => (string)$k['mail_odpowiedz']];

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
            $odp = bon_mailjet_wyslij($k, (string)$paczka[0]['buyer_email'], $temat, $tekst, $html, $zalaczniki);
            unset($zalaczniki);
            $mailjet[] = ['http' => $odp['http'], 'status' => $odp['status'], 'id' => $odp['id'], 'proby' => $odp['proby']];
            $blad = $odp['blad'];
            if ($blad !== null) break;
            $teraz = bon_teraz()->format('Y-m-d H:i:s');
            foreach ($paczka as $b) { $oznacz->execute([$teraz, $b['id']]); $wyslane++; }
            // tryb „plik” (testy, też test-ovh.php na produkcji): bez zapisu do kontroli – zmyślone MessageID nie mogą trafić do crona
            if ($k['mail_tryb'] !== 'plik') bon_mail_zapisz_wysylke($k, $zamowienie, array_column($paczka, 'code'), $odp['id']);
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

/** Status wiadomości z Mailjeta: GET /v3/REST/message/{id}. Zwraca status małymi literami albo null (jeszcze nie widać / błąd). */
function bon_mailjet_status(array $k, int|string $id): ?string {
    if ($k['mail_tryb'] === 'plik') return isset($k['mailjet_test_status']) ? (string)$k['mailjet_test_status'] : 'sent';
    $ch = curl_init('https://api.mailjet.com/v3/REST/message/' . rawurlencode((string)$id));
    curl_setopt_array($ch, [
        CURLOPT_USERPWD => (string)($k['mailjet_api_key'] ?? '') . ':' . (string)($k['mailjet_api_secret'] ?? ''),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $odp = curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($odp === false || $kod !== 200) return null;
    $s = json_decode((string)$odp, true)['Data'][0]['Status'] ?? null;
    return is_string($s) ? strtolower($s) : null;
}

/**
 * Kontrola dostarczenia (cron): wysyłki sprzed co najmniej 10 min bez ostatecznego statusu.
 * - wszystkie wiadomości sent/opened/clicked → zamknięte;
 * - którakolwiek bounce/spam/blocked… → zdarzenie + JEDNO powiadomienie gabinetu;
 * - po 24 h nadal bez statusu (queued, deferred, nieznany) → zdarzenie + jedno powiadomienie „niepotwierdzone”.
 * Zwraca podsumowanie do dziennika.
 */
function bon_mail_kontrola(array $k, int $teraz = 0, int $minWiekS = 600): array {
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

        $statusy = array_map(static fn($id) => bon_mailjet_status($k, $id), (array)($w['id'] ?? []));
        $wynik['sprawdzono']++;
        $w['sprawdzono'] = $teraz;
        $kody = implode(', ', (array)($w['kody'] ?? []));
        $zle = array_values(array_filter($statusy, static fn($s) => in_array($s, BON_MAIL_PROBLEM, true)));
        if ($zle) {
            $w['status'] = 'problem: ' . implode(',', array_unique($zle));
            $wynik['problem']++;
            bon_zdarzenie($k, 'mail-dostarczenie', ['zamowienie' => $w['zamowienie'], 'status' => $w['status'], 'id' => $w['id']]);
            if (!$w['powiadomiono']) {
                bon_powiadom($k, 'Bon: mail NIE dotarł – zamówienie ' . $w['zamowienie'], "Mailjet przyjął mail z bonem, ale go nie dostarczył.\n"
                    . 'Status: ' . implode(', ', array_unique($zle)) . "\nBony: $kody\n"
                    . "Sprawdź w Mailjecie (Statystyki → wiadomość) i wyślij bon ponownie albo przekaż go kupującemu inną drogą.");
                $w['powiadomiono'] = true;
            }
        } elseif ($statusy && count(array_filter($statusy, static fn($s) => in_array($s, BON_MAIL_DOSTARCZONO, true))) === count($statusy)) {
            $w['status'] = 'dostarczono';
            $wynik['dostarczone']++;
        } else {
            $wynik['czeka']++;
            if ($wiek >= 86400) {
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
