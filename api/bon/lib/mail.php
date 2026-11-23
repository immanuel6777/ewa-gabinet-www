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

/** Wysyła jedną wiadomość. Zwraca null przy sukcesie albo opis błędu. */
function bon_mailjet_wyslij(array $k, string $do, string $temat, string $tekst, string $html, array $zalaczniki): ?string {
    if (!preg_match('/^(.*)<([^>]+)>\s*$/', (string)($k['mail_nadawca'] ?? ''), $m)) return 'zły mail_nadawca w konfigu (wzór: Nazwa <adres@domena>)';
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
        file_put_contents($k['_katalog'] . '/bony-mailjet.jsonl', json_encode($zapis, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return ($k['mailjet_test_blad'] ?? false) ? 'symulowany błąd Mailjet' : null;
    }

    $klucz = (string)($k['mailjet_api_key'] ?? '');
    $sekret = (string)($k['mailjet_api_secret'] ?? '');
    if ($klucz === '' || $sekret === '' || str_starts_with($klucz, 'WKLEJ')) return 'brak kluczy Mailjet w konfigu';
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
    curl_close($ch);
    if ($odp === false) return 'Mailjet: ' . $blad;
    $j = json_decode((string)$odp, true);
    if ($kod !== 200 || ($j['Messages'][0]['Status'] ?? '') !== 'success') return "Mailjet HTTP $kod: " . mb_substr((string)$odp, 0, 500);
    return null;
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
        $oznacz = $pdo->prepare("UPDATE $tab SET mail_wyslany_at = ? WHERE id = ? AND mail_wyslany_at IS NULL");
        foreach (array_chunk($bony, BON_PDF_NA_MAIL) as $paczka) {
            [$temat, $tekst, $html] = bon_mail_tresc($k, $paczka);
            $zalaczniki = array_map(static fn($b) => ['nazwa' => 'bon-' . $b['code'] . '.pdf', 'dane' => bon_pdf($b, $k)], $paczka);
            $blad = bon_mailjet_wyslij($k, (string)$paczka[0]['buyer_email'], $temat, $tekst, $html, $zalaczniki);
            unset($zalaczniki);
            if ($blad !== null) break;
            $teraz = bon_teraz()->format('Y-m-d H:i:s');
            foreach ($paczka as $b) { $oznacz->execute([$teraz, $b['id']]); $wyslane++; }
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
        return ['zamowienie' => $zamowienie, 'wyslane' => $wyslane, 'blad' => $blad, 'pominieto' => null];
    } finally {
        bon_zwolnij($pdo, $blokada);
    }
}
