<?php
// Diagnostyka backendu bonów TYLKO DO ODCZYTU (2026-11-22, Bartek: „chcę, żebyś miał stały dostęp do logów”).
// Pokazuje też dziennik zdarzeń (co backend zrobił: lib/bon.php → bon_zdarzenie), ostatnie realizacje, liczbę nieudanych logowań
// i wersję buildu. Używa jej api/bon/log.php (token w nagłówku X-Log-Token, `log_token` w konfig/bony.php). Pokazuje: stan konfiguracji (bez sekretów),
// ostatnie wpisy logu webhooków, ostatnie bony, liczniki tabel, końcówkę logu błędów PHP (tylko linie „bony”).
//
// Dane osobowe NIGDY nie wychodzą: bon_log_ukryj() zamienia na „***” wartości kluczy osobowych (e-mail, imię, NIP, adres, telefon,
// pola własne Dla/Od, kod rabatowy, podpis webhooka, IP…), każdy napis wyglądający jak e-mail albo długi numer, a przy nieznanym
// kluczu zostawia samą strukturę. Kod bonu jest maskowany (EWA-****-AB12), Dla/Od tylko jako „wpisane: tak/nie”.
// Puste wartości (null) zostają puste, żeby było widać, czy pole przyszło.

declare(strict_types=1);
require_once __DIR__ . '/bon.php';

// klucze, których wartości są ukrywane (w całym poddrzewie); wyjątki poniżej
const BON_LOG_KLUCZE_OSOBOWE = '/(mail|name|nip|vat|tax|phone|tel|addr|street|city|zip|post|company|firma|token|secret|sign|pass|auth|customer|buyer|invoice|billing|shipping|custom|promo|coupon|forward|cookie|(^|[_-])ip($|[_-]))/i';
// mailjet: odpowiedź Mailjeta w zdarzeniu „mail” (HTTP, Status, MessageID, próby) – bez adresów; klucz zawiera „mail”, więc wyjątek
const BON_LOG_KLUCZE_JAWNE = '/^((product|price|plan)[_-]name|price[_-]custom[_-]id|mailjet)$/i';
const BON_LOG_EMAIL = '/[^\s@"]+@[^\s@"]+\.[^\s@"]+/';

/** Kopia struktury z ukrytymi danymi osobowymi (zob. opis na górze pliku). */
// $polaWlasne: jesteśmy w custom_fields – nazwy pól (name/label/key) zostają jawne, bo trzeba wiedzieć, jak sklep nazywa pola
// Dla/Od; wartości ukryte. Tylko tam: np. invoice_data.name to dane kupującego.
function bon_log_ukryj(mixed $v, bool $ukryj = false, string $klucz = '', bool $polaWlasne = false): mixed {
    if (is_array($v)) {
        $lista = array_is_list($v);
        $wynik = [];
        foreach ($v as $k => $w) {
            $kl = (string)$k;
            $pw = $polaWlasne || preg_match('/custom[_-]?fields?/i', $kl) === 1;
            $u = $ukryj || (!$lista && preg_match(BON_LOG_KLUCZE_OSOBOWE, $kl) === 1 && preg_match(BON_LOG_KLUCZE_JAWNE, $kl) !== 1);
            if ($polaWlasne && !$lista && !is_array($w) && in_array(strtolower($kl), ['name', 'label', 'key'], true)) $u = false;
            $wynik[$k] = bon_log_ukryj($w, $u, $kl, $pw);
        }
        return $wynik;
    }
    if ($v === null) return null;
    if ($ukryj) return '***';
    if (is_string($v)) {
        if (preg_match(BON_LOG_EMAIL, $v) === 1) return '***';
        if (preg_match('/\d{9,}/', $v) === 1 && preg_match('/(^|[_-])(id|uuid)$/i', $klucz) !== 1) return '***'; // telefon, NIP, konto
        return mb_substr($v, 0, 120);
    }
    return $v; // bool, int, float
}

/** Ostatnie linie pliku (czytane od końca, najwyżej $bajtow bajtów). */
function bon_log_ogon(string $plik, int $bajtow): array {
    $rozmiar = @filesize($plik);
    if (!$rozmiar) return [];
    $f = @fopen($plik, 'rb');
    if (!$f) return [];
    $od = max(0, $rozmiar - $bajtow);
    fseek($f, $od);
    $tekst = (string)stream_get_contents($f);
    fclose($f);
    $linie = explode("\n", $tekst);
    if ($od > 0) array_shift($linie); // pierwsza linia mogła zostać ucięta
    return array_values(array_filter($linie, static fn($l) => trim($l) !== ''));
}

/** Ostatnie wpisy logu webhooków, najnowsze pierwsze, z ukrytymi danymi osobowymi. */
function bon_log_webhooki(array $k, int $ile, int $dni): array {
    $pliki = glob($k['_katalog'] . '/bony-webhook-log/*.log') ?: [];
    rsort($pliki); // nazwy RRRR-MM-DD.log → najnowszy dzień pierwszy
    $wynik = [];
    foreach (array_slice($pliki, 0, $dni) as $plik) {
        foreach (array_reverse(bon_log_ogon($plik, 512 * 1024)) as $linia) {
            if (count($wynik) >= $ile) break 2;
            $w = json_decode($linia, true);
            if (!is_array($w)) { $wynik[] = ['plik' => basename($plik), 'blad' => 'wpis nie jest JSON-em']; continue; }
            $body = is_string($w['body'] ?? null) ? json_decode($w['body'], true) : null;
            $wynik[] = [
                'plik' => basename($plik),
                'czas' => $w['czas'] ?? null,
                'podpis_ok' => $w['podpis_ok'] ?? null,
                'naglowki' => bon_log_ukryj($w['naglowki'] ?? []),
                'body' => is_array($body) ? bon_log_ukryj($body) : ['niejson' => true, 'dlugosc' => strlen((string)($w['body'] ?? ''))],
            ];
        }
    }
    return $wynik;
}

/** Ostatnie bony: bez danych kupującego, kod zamaskowany, Dla/Od tylko jako „wpisane”. */
function bon_log_bony(array $k, PDO $pdo, int $ile): array {
    $st = $pdo->query('SELECT code, status, status_uwagi, initial_pln, balance_pln, paid_pln, price_id, promo_code, dla, od, mail_wyslany_at, created_at, expires_at,
            easytools_order_id, nr_w_zamowieniu FROM ' . bon_tabela($k, 'vouchers') . ' ORDER BY id DESC LIMIT ' . max(1, $ile));
    return array_map(static fn($b) => [
        'kod' => 'EWA-****-' . substr((string)$b['code'], -4),
        'status' => $b['status'],
        'uwagi' => $b['status_uwagi'],
        'wartosc' => $b['initial_pln'],
        'saldo' => $b['balance_pln'],
        'zaplacono' => $b['paid_pln'],
        'wariant' => $b['price_id'],
        'rabat' => (string)($b['promo_code'] ?? '') !== '',
        'dla_wpisane' => (string)($b['dla'] ?? '') !== '',
        'od_wpisane' => (string)($b['od'] ?? '') !== '',
        'mail_wyslany' => $b['mail_wyslany_at'],
        'kupiono' => $b['created_at'],
        'wazny_do' => $b['expires_at'],
        'zamowienie' => $b['easytools_order_id'],
        'nr_w_zamowieniu' => $b['nr_w_zamowieniu'],
    ], $st->fetchAll());
}

/** Końcówka logu błędów PHP (jeśli hosting ją udostępnia): tylko linie z „bony”, e-maile wycięte. */
function bon_log_php(): array {
    $plik = (string)ini_get('error_log');
    if ($plik === '' || !is_readable($plik)) return ['dostepny' => false];
    $bony = array_values(array_filter(bon_log_ogon($plik, 128 * 1024), static fn($l) => stripos($l, 'bony') !== false));
    return ['dostepny' => true, 'linie' => array_map(
        static fn($l) => mb_substr(preg_replace(BON_LOG_EMAIL, '***', $l) ?? '', 0, 300),
        array_slice($bony, -30)
    )];
}

/** Ostatnie zdarzenia z dziennika (bon_zdarzenie w lib/bon.php), najnowsze pierwsze. */
function bon_log_zdarzenia(array $k, int $ile, int $dni): array {
    $pliki = glob($k['_katalog'] . '/bony-zdarzenia/*.log') ?: [];
    rsort($pliki);
    $wynik = [];
    foreach (array_slice($pliki, 0, $dni) as $plik) {
        foreach (array_reverse(bon_log_ogon($plik, 512 * 1024)) as $linia) {
            if (count($wynik) >= $ile) break 2;
            $w = json_decode($linia, true);
            $wynik[] = is_array($w) ? bon_log_ukryj($w) : ['blad' => 'wpis nie jest JSON-em'];
        }
    }
    return $wynik;
}

/** Ostatnie realizacje w gabinecie: kwoty, kto (login), kod zamaskowany; bez pozycji i notatek (wolny tekst). */
function bon_log_realizacje(array $k, PDO $pdo, int $ile): array {
    $st = $pdo->query('SELECT v.code, r.amount_pln, r.doplata_pln, r.typ, r.redeemed_at, r.redeemed_by FROM ' . bon_tabela($k, 'redemptions') . ' r JOIN '
        . bon_tabela($k, 'vouchers') . ' v ON v.id = r.voucher_id ORDER BY r.id DESC LIMIT ' . max(1, $ile));
    return array_map(static fn($r) => ['kod' => bon_kod_maska((string)$r['code']), 'zdjeto' => $r['amount_pln'], 'doplata' => $r['doplata_pln'],
        'typ' => $r['typ'], 'kiedy' => $r['redeemed_at'], 'kto' => $r['redeemed_by']], $st->fetchAll());
}

/** Całość dla log.php. Błąd bazy nie przerywa reszty: zwraca klasę wyjątku i kod, bez treści (mogłaby zawierać login do bazy). */
function bon_diagnostyka(array $k, int $ile = 10, int $dni = 2, int $bony = 20, int $zdarzen = 40, ?PDO $pdo = null): array {
    $wersja = is_file(__DIR__ . '/wersja.json') ? json_decode((string)file_get_contents(__DIR__ . '/wersja.json'), true) : null;
    $wynik = [
        'czas' => bon_teraz()->format('c'),
        'wersja' => $wersja, // commit i data buildu (src/pages/api/bon/lib/wersja.json.ts) – czy wrzuta doszła
        'php' => PHP_VERSION,
        'konfig' => [
            'webhook_tryb' => $k['webhook_tryb'],
            'prog_wstrzymania' => $k['prog_wstrzymania'],
            'waznosc_miesiecy' => $k['waznosc_miesiecy'],
            'kwota_jednostka' => $k['kwota_jednostka'],
            'mail_tryb' => $k['mail_tryb'],
            'wariantow_w_cenach' => count((array)$k['ceny']),
            'kont_panelu' => count((array)($k['panel_konta'] ?? [])),
            // synchronizacja z API Easytools (2026-11-23): sam fakt ustawienia klucza, nigdy klucz
            'sync_tryb' => (string)($k['sync_tryb'] ?? ''),
            'sync_od' => (string)($k['sync_od'] ?? ''),
            'easytools_api_key_ustawiony' => (string)($k['easytools_api_key'] ?? '') !== '' && !str_starts_with((string)$k['easytools_api_key'], 'WKLEJ'),
            'easytools_produkt_uuid' => (string)($k['easytools_produkt_uuid'] ?? ''),
        ],
        'pliki_logu' => array_map(static fn($p) => ['plik' => basename($p), 'bajtow' => filesize($p), 'zmieniono' => date('c', (int)filemtime($p))],
            array_slice(array_reverse(glob($k['_katalog'] . '/bony-webhook-log/*.log') ?: []), 0, 14)),
        'zdarzenia' => bon_log_zdarzenia($k, $zdarzen, $dni),
        'webhooki' => bon_log_webhooki($k, $ile, $dni),
    ];
    try {
        $pdo ??= bon_pdo($k);
        $wynik['baza'] = ['ok' => true, 'tabele' => []];
        foreach (['vouchers', 'redemptions', 'voucher_sessions', 'voucher_login_attempts', 'voucher_admin_log', 'voucher_verify_limit'] as $t) {
            $wynik['baza']['tabele'][$t] = (int)$pdo->query('SELECT COUNT(*) FROM ' . bon_tabela($k, $t))->fetchColumn();
        }
        $wynik['bony'] = bon_log_bony($k, $pdo, $bony);
        $wynik['realizacje'] = bon_log_realizacje($k, $pdo, 10);
        // każda nieudana próba to 2 wpisy (login+IP i samo IP)
        $st = $pdo->prepare('SELECT COUNT(*) FROM ' . bon_tabela($k, 'voucher_login_attempts') . ' WHERE czas >= ?');
        $st->execute([time() - 86400]);
        $wynik['nieudane_logowania_24h'] = intdiv((int)$st->fetchColumn(), 2);
    } catch (Throwable $e) {
        $wynik['baza'] = ['ok' => false] + bon_opis_bledu($e);
    }
    $wynik['blad_php'] = bon_log_php();
    return $wynik;
}

/** TYMCZASOWE (Krok 0 strony /bony/dziekujemy, Bartek 2026-11-22): parametry powrotu z kasy Easytools do dziennika zdarzeń,
 *  żeby po zakupie testowym ustalić, który parametr niesie id zamówienia (= easytools_order_id z webhooka). Wartości przez
 *  bon_log_ukryj (e-maile, długie liczby, klucze osobowe → ***), a obok skrót sha256 (16 znaków) każdej wartości – zamówienie
 *  porównuje się po skrócie, bez zapisywania danych wprost. Po ustaleniu parametru usunąć razem z api/bon/powrot.php. */
function bon_powrot_skrot(string $w): string {
    return implode('-', str_split(substr(hash('sha256', $w), 0, 16), 4));
}
function bon_powrot_dane(array $get, string $qs): array {
    $get = array_slice($get, 0, 30, true);
    $skroty = [];
    // grupy po 4 znaki: ciąg 9+ cyfr bon_log_ukryj (też przy odczycie w log.php) bierze za telefon i zamienia na *** (2026-11-22)
    foreach ($get as $kl => $w) if (is_string($w) && $w !== '') $skroty[(string)$kl] = bon_powrot_skrot($w);
    return ['klucze' => array_map('strval', array_keys($get)), 'parametry' => bon_log_ukryj($get), 'skroty' => $skroty, 'dlugosc' => strlen($qs)];
}
