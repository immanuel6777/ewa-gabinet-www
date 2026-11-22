<?php
// Wspólne funkcje backendu bonów (plan/BONY-PLAN-TECHNICZNY.md; etap 1: 2026-11-21).
// Plik tylko definiuje funkcje – nic nie wypisuje. Na produkcji katalog /api/bon/lib/ jest zablokowany w .htaccess
// (scripts/produkcja.mjs, sprawdza scripts/sprawdz.mjs). Na Netlify (wersja testowa) PHP się nie wykonuje.
//
// Konfiguracja POZA repo: na OVH <katalog domowy>/konfig/bony.php (wzór plan/konfig-bony.przyklad.php).
// Testy lokalne podają inną ścieżkę zmienną środowiskową BONY_KONFIG (tests/php/).
//
// Zasady: kwoty liczone w groszach (int), do bazy jako tekst „123.45” (DECIMAL(10,2)); nigdy float w bazie.
// Daty w czasie polskim liczone w PHP. „Wygasły” liczony przy odczycie z expires_at.

declare(strict_types=1);

const BON_ALFABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 32 znaki, bez 0/O/1/I
const BON_STREFA = 'Europe/Warsaw';

function bon_konfig(): array {
    $plik = getenv('BONY_KONFIG') ?: dirname(__DIR__, 4) . '/konfig/bony.php';
    if (!is_file($plik)) throw new RuntimeException('brak konfiguracji bonów');
    $k = require $plik;
    if (!is_array($k)) throw new RuntimeException('konfiguracja bonów nie zwraca tablicy');
    $k['_katalog'] = dirname($plik);
    return $k + [
        'webhook_tryb' => 'log',
        'tabele_przedrostek' => '',
        'kwota_jednostka' => 'pln',
        'prog_wstrzymania' => 0.8,
        'min_dowolna_pln' => 180,
        'waznosc_miesiecy' => 6,
        'kod_prefix' => 'EWA',
        'pole_dla' => ['bon-dla', 'bon_dla', 'dla kogo (imię na bonie)'], // Easytools zamienia „_” na „-” w nazwie pola (2026-11-21)
        'pole_od' => ['bon-od', 'bon_od', 'od kogo'],
        'ceny' => ['bon-200' => 200, 'bon-300' => 300, 'bon-500' => 500, 'bon-dowolna' => 'dowolna'],
        'verify_limit_na_minute' => 20,
        'mail_tryb' => 'mail',
    ];
}

function bon_pdo(array $k): PDO {
    $pdo = new PDO($k['db_dsn'], $k['db_uzytkownik'] ?? null, $k['db_haslo'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $pdo->exec("SET NAMES utf8mb4");
    return $pdo;
}

// nazwa tabeli z przedrostkiem (test_ na testach OVH); tylko litery, cyfry i _
function bon_tabela(array $k, string $nazwa): string {
    $p = (string)$k['tabele_przedrostek'];
    if (!preg_match('/^[a-z0-9_]*$/', $p)) throw new RuntimeException('zły przedrostek tabel');
    return $p . $nazwa;
}

// --- kwoty ---

// kwota z bazy lub z JSON-a → grosze; $jednostka 'pln' (50 = 50 zł) albo 'grosze' (5000 = 50 zł). Null przy złej wartości.
function bon_grosze($v, string $jednostka = 'pln'): ?int {
    if (is_int($v)) $n = $v;
    elseif (is_float($v) && is_finite($v)) $n = $v;
    elseif (is_string($v) && preg_match('/^\s*\d+([.,]\d{1,2})?\s*$/', $v)) $n = (float)str_replace(',', '.', trim($v));
    else return null;
    if ($n < 0) return null;
    return $jednostka === 'grosze' ? (int)round($n) : (int)round($n * 100);
}

// grosze → tekst do DECIMAL(10,2) i do JSON-a: 15000 → "150.00"
function bon_zl(int $grosze): string {
    return intdiv($grosze, 100) . '.' . str_pad((string)($grosze % 100), 2, '0', STR_PAD_LEFT);
}

// --- daty ---

function bon_teraz(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone(BON_STREFA));
}

// data ważności: zakup + N miesięcy, ten sam dzień miesiąca; gdy go nie ma (29.02, 31.01 + 1 mies.) → ostatni dzień miesiąca
function bon_waznosc(DateTimeImmutable $zakup, int $miesiecy): string {
    $r = (int)$zakup->format('Y');
    $m = (int)$zakup->format('n') + $miesiecy;
    $r += intdiv($m - 1, 12);
    $m = ($m - 1) % 12 + 1;
    $dni = bon_dni_w_miesiacu($r, $m);
    $d = min((int)$zakup->format('j'), $dni);
    return sprintf('%04d-%02d-%02d', $r, $m, $d);
}

function bon_dni_w_miesiacu(int $r, int $m): int {
    return (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $r, $m)))->format('t');
}

// status widoczny na zewnątrz: 'valid' po terminie → 'expired' (bez crona, admin może zmienić expires_at)
function bon_status_efektywny(array $bon, ?DateTimeImmutable $teraz = null): string {
    $dzis = ($teraz ?? bon_teraz())->format('Y-m-d');
    if ($bon['status'] === 'valid' && substr((string)$bon['expires_at'], 0, 10) < $dzis) return 'expired';
    return (string)$bon['status'];
}

// --- kody ---

function bon_nowy_kod(string $prefix = 'EWA'): string {
    $z = '';
    for ($i = 0; $i < 8; $i++) $z .= BON_ALFABET[random_int(0, strlen(BON_ALFABET) - 1)];
    return $prefix . '-' . substr($z, 0, 4) . '-' . substr($z, 4, 4);
}

// wpisany kod → postać kanoniczna EWA-XXXX-XXXX albo null (wielkość liter i spacje/myślniki bez znaczenia)
function bon_normalizuj_kod(string $wpisany, string $prefix = 'EWA'): ?string {
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $wpisany) ?? '');
    if (strlen($s) !== strlen($prefix) + 8 || strncmp($s, $prefix, strlen($prefix)) !== 0) return null;
    $z = substr($s, strlen($prefix));
    if (strspn($z, BON_ALFABET) !== 8) return null;
    return $prefix . '-' . substr($z, 0, 4) . '-' . substr($z, 4, 4);
}

// --- pola własne checkoutu (Dla / Od) ---
// Struktura custom_fields nie jest opisana w dokumentacji Easytools – obsługujemy {"Nazwa": "wartość"}
// i listę [{"name"/"label"/"key": …, "value": …}]. Nazwy pól w konfigu (pole_dla, pole_od), bez wielkości liter.
function bon_pole_wlasne($pola, array $nazwy): ?string {
    if (!is_array($pola)) return null;
    $nazwy = array_map(static fn($n) => mb_strtolower(trim((string)$n), 'UTF-8'), $nazwy);
    foreach ($pola as $klucz => $wartosc) {
        if (is_array($wartosc)) {
            $klucz = $wartosc['name'] ?? $wartosc['label'] ?? $wartosc['key'] ?? null;
            $wartosc = $wartosc['value'] ?? null;
        }
        if (!is_string($klucz) || !is_scalar($wartosc)) continue;
        if (in_array(mb_strtolower(trim($klucz), 'UTF-8'), $nazwy, true)) {
            $v = bon_tekst((string)$wartosc, 80);
            return $v === '' ? null : $v;
        }
    }
    return null;
}

function bon_tekst(?string $v, int $max): string {
    $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', trim((string)$v)) ?? '';
    return mb_substr(trim($v), 0, $max, 'UTF-8');
}

// --- powiadomienia (etap 1: mail() z OVH; etap 2: Mailjet) ---
function bon_powiadom(array $k, string $temat, string $tresc): bool {
    $do = (array)($k['mail_powiadomienie'] ?? []);
    if (!$do) return false;
    if ($k['mail_tryb'] === 'plik') { // testy: zamiast wysyłki dopisanie do pliku obok konfiguracji
        return (bool)file_put_contents($k['_katalog'] . '/bony-powiadomienia.txt', "== $temat\n$tresc\n\n", FILE_APPEND);
    }
    $naglowki = [
        'From: ' . ($k['mail_nadawca'] ?? 'Bony ewa-gabinet.pl <bony@ewa-gabinet.pl>'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $ok = @mail(implode(', ', $do), '=?UTF-8?B?' . base64_encode($temat) . '?=', $tresc, implode("\r\n", $naglowki));
    if (!$ok) error_log('bony: mail() nie wysłał powiadomienia: ' . $temat);
    return $ok;
}

// --- log surowych webhooków (tryb log / log+bon) ---
// Zawiera dane osobowe → katalog obok konfiguracji (poza astro-www); po teście usunąć.
function bon_log_webhooka(array $k, string $raw, array $naglowki, ?bool $podpisOk): void {
    $katalog = $k['_katalog'] . '/bony-webhook-log';
    if (!is_dir($katalog) && !@mkdir($katalog, 0700, true)) { error_log('bony: nie mogę utworzyć ' . $katalog); return; }
    $plik = $katalog . '/' . bon_teraz()->format('Y-m-d') . '.log';
    if (is_file($plik) && filesize($plik) > 5 * 1024 * 1024) { error_log('bony: log webhooka > 5 MB, pomijam'); return; }
    $wpis = json_encode([
        'czas' => bon_teraz()->format('c'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'podpis_ok' => $podpisOk,
        'naglowki' => $naglowki,
        'body' => $raw,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    @file_put_contents($plik, $wpis . "\n", FILE_APPEND | LOCK_EX);
}

// --- webhook Easytools ---
// Dokumentacja (2026-11-21): nagłówek X-Webhook-Signature = hex HMAC-SHA256 surowego body kluczem podpisu
// (https://easy.tools/docs/generate-webhook-signing-key). Zdarzenie z kwotą: single_product_bought
// (product_assigned ma amount_paid = null; „Zakup dla kogoś innego” wyłączony w sklepie).
function bon_podpis_ok(array $k, string $raw, string $podpis): bool {
    $sekret = (string)($k['easytools_webhook_sekret'] ?? '');
    if ($sekret === '' || str_starts_with($sekret, 'WKLEJ')) return false;
    return hash_equals(hash_hmac('sha256', $raw, $sekret), strtolower(trim($podpis)));
}

/**
 * Obsługa webhooka. Zwraca [kod HTTP, odpowiedź JSON, ?callable zadanie po odpowiedzi (wysyłka PDF, lib/mail.php)].
 * Bez wyjścia na ekran – testowalne.
 * $naglowki: nazwy nagłówków małymi literami.
 */
function bon_obsluz_webhook(array $k, string $raw, array $naglowki, ?callable $pdoFabryka = null): array {
    $tryb = (string)$k['webhook_tryb'];
    $podpis = (string)($naglowki['x-webhook-signature'] ?? '');
    $podpisOk = bon_podpis_ok($k, $raw, $podpis);

    if ($tryb === 'log' || $tryb === 'log+bon') bon_log_webhooka($k, $raw, $naglowki, $podpisOk);
    // tryb log: nic nie tworzymy, zawsze 200 (żeby sklep nie ponawiał i nie wyłączył webhooka w trakcie testu)
    if ($tryb === 'log') return [200, ['ok' => true, 'tryb' => 'log', 'podpis_ok' => $podpisOk]];
    if ($tryb !== 'log+bon' && $tryb !== 'bon') return [500, ['ok' => false, 'blad' => 'nieznany webhook_tryb']];

    if (!$podpisOk) return [401, ['ok' => false, 'blad' => 'zły podpis']];

    $d = json_decode($raw, true);
    if (!is_array($d)) return [400, ['ok' => false, 'blad' => 'zły JSON']];

    $zdarzenie = (string)($d['event'] ?? '');
    if ($zdarzenie !== 'single_product_bought') return [200, ['ok' => true, 'pominieto' => $zdarzenie]];
    if (($d['success'] ?? null) !== true) return [200, ['ok' => true, 'pominieto' => 'success != true']];

    $zamowienie = $d['order_id'] ?? null;
    if (!is_int($zamowienie) && !(is_string($zamowienie) && $zamowienie !== '')) return [400, ['ok' => false, 'blad' => 'brak order_id']];
    $zamowienie = mb_substr((string)$zamowienie, 0, 64);

    $email = bon_tekst((string)($d['customer_email'] ?? ''), 190);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [400, ['ok' => false, 'blad' => 'brak e-maila']];

    if (strtolower((string)($d['currency'] ?? '')) !== 'pln') {
        bon_powiadom($k, 'Bon: zamówienie w innej walucie', "Zamówienie $zamowienie, waluta: " . ($d['currency'] ?? '?') . ". Bon NIE powstał – sprawdź w Easytools.");
        return [200, ['ok' => true, 'pominieto' => 'waluta']];
    }

    // ilość: pieniądze już wpłynęły, więc nic nie odrzucamy – dziwna ilość = bon wstrzymany do sprawdzenia
    $uwagi = [];
    $ilosc = $d['quantity'] ?? 1;
    if (is_string($ilosc) && ctype_digit($ilosc)) $ilosc = (int)$ilosc;
    if (!is_int($ilosc) || $ilosc < 1) {
        $uwagi[] = 'zła ilość (quantity: ' . json_encode($d['quantity'] ?? null) . ') – utworzono 1 bon, sprawdź zamówienie';
        $ilosc = 1;
    } elseif ($ilosc > 50) {
        $uwagi[] = "ilość $ilosc > 50 – utworzono 50 bonów, resztę dodaj ręcznie";
        $ilosc = 50;
    }

    // --- wartość bonu: nominał wariantu z konfigu; „dowolna” = kwota zapłacona ---
    $zaplaconoRazem = bon_grosze($d['amount_paid'] ?? null, (string)$k['kwota_jednostka']);
    if ($zaplaconoRazem === null) { $uwagi[] = 'brak lub zła kwota amount_paid'; $zaplaconoRazem = 0; }
    $zaplaconoJedn = intdiv($zaplaconoRazem, $ilosc);

    $priceId = bon_tekst((string)($d['price_id'] ?? ''), 100);
    $wariant = $k['ceny'][$priceId] ?? ($k['ceny'][(string)($d['price_custom_id'] ?? '')] ?? null);
    if ($wariant === 'dowolna') {
        $wartosc = $zaplaconoJedn;
        if ($wartosc < (int)$k['min_dowolna_pln'] * 100) $uwagi[] = 'dowolna kwota poniżej minimum ' . $k['min_dowolna_pln'] . ' zł';
    } elseif (is_int($wariant) || is_float($wariant) || (is_string($wariant) && is_numeric($wariant))) {
        $wartosc = (int)round((float)$wariant * 100);
        if ($zaplaconoJedn < (int)round($wartosc * (float)$k['prog_wstrzymania'])) {
            $uwagi[] = 'zapłacono ' . bon_zl($zaplaconoJedn) . ' zł przy nominale ' . bon_zl($wartosc) . ' zł (próg ' . ((float)$k['prog_wstrzymania'] * 100) . '%)';
        } elseif ($zaplaconoJedn > $wartosc) {
            // np. ilość nie doszła albo amount_paid to coś innego niż suma zamówienia – mogło przepaść kilka bonów
            $uwagi[] = 'zapłacono ' . bon_zl($zaplaconoJedn) . ' zł za bon przy nominale ' . bon_zl($wartosc) . ' zł – więcej niż nominał, sprawdź ilość';
        }
    } else {
        $wartosc = $zaplaconoJedn;
        $uwagi[] = 'nieznany wariant ceny (price_id: ' . ($priceId ?: 'brak') . ') – dopisz go w konfigu w „ceny”';
    }
    if ($wartosc <= 0) $uwagi[] = 'wartość bonu 0 zł';
    $status = $uwagi ? 'held' : 'valid';

    $teraz = bon_teraz();
    $promo = bon_tekst(is_scalar($d['promo_code'] ?? null) ? (string)$d['promo_code'] : '', 100);
    $nip = is_array($d['invoice_data'] ?? null) ? bon_tekst((string)($d['invoice_data']['nip'] ?? ''), 20) : '';
    $wspolne = [
        'initial_pln' => bon_zl($wartosc),
        'balance_pln' => bon_zl($wartosc),
        'price_id' => $priceId,
        'promo_code' => $promo === '' ? null : $promo,
        'buyer_email' => $email,
        'buyer_name' => bon_tekst((string)($d['customer_name'] ?? ''), 120) ?: null,
        'buyer_nip' => $nip === '' ? null : $nip,
        'dla' => bon_pole_wlasne($d['custom_fields'] ?? null, (array)$k['pole_dla']),
        'od' => bon_pole_wlasne($d['custom_fields'] ?? null, (array)$k['pole_od']),
        'status' => $status,
        'status_uwagi' => $uwagi ? mb_substr(implode('; ', $uwagi), 0, 255) : null,
        'easytools_order_id' => $zamowienie,
        'created_at' => $teraz->format('Y-m-d H:i:s'),
        'expires_at' => bon_waznosc($teraz, (int)$k['waznosc_miesiecy']),
    ];

    $pdo = $pdoFabryka ? $pdoFabryka($k) : bon_pdo($k);
    $tab = bon_tabela($k, 'vouchers');
    $sql = "INSERT INTO $tab (code, initial_pln, balance_pln, paid_pln, price_id, promo_code, buyer_email, buyer_name, buyer_nip, dla, od,
              status, status_uwagi, easytools_order_id, nr_w_zamowieniu, created_at, expires_at)
            VALUES (:code, :initial_pln, :balance_pln, :paid_pln, :price_id, :promo_code, :buyer_email, :buyer_name, :buyer_nip, :dla, :od,
              :status, :status_uwagi, :easytools_order_id, :nr, :created_at, :expires_at)";

    // idempotencja: UNIQUE (easytools_order_id, nr_w_zamowieniu); powtórzony webhook → 23000 → nic nowego nie powstaje
    for ($proba = 1; $proba <= 3; $proba++) {
        $kody = [];
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare($sql);
            for ($nr = 1; $nr <= $ilosc; $nr++) {
                $kod = bon_nowy_kod((string)$k['kod_prefix']);
                // ostatni bon dostaje resztę z dzielenia zapłaconej kwoty
                $zaplacono = $nr === $ilosc ? $zaplaconoRazem - $zaplaconoJedn * ($ilosc - 1) : $zaplaconoJedn;
                foreach ($wspolne as $pole => $w) $st->bindValue(':' . $pole, $w, $w === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $st->bindValue(':code', $kod);
                $st->bindValue(':paid_pln', bon_zl($zaplacono));
                $st->bindValue(':nr', $nr, PDO::PARAM_INT);
                $st->execute();
                $kody[] = $kod;
            }
            $pdo->commit();
            break;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (($e->errorInfo[0] ?? $e->getCode()) !== '23000') throw $e;
            $ile = $pdo->prepare("SELECT COUNT(*) FROM $tab WHERE easytools_order_id = ?");
            $ile->execute([$zamowienie]);
            // powtórzony webhook: bony już są; jeśli poprzednia wysyłka maila się nie udała – dokończ ją (po odpowiedzi)
            if ((int)$ile->fetchColumn() > 0) return [200, ['ok' => true, 'powtorzony' => true], static fn() => bon_wyslij_zamowienie($k, $pdo, $zamowienie)];
            if ($proba === 3) throw $e; // trzy kolizje kodu z rzędu – nie powinno się zdarzyć
        }
    }

    if ($status === 'held') {
        bon_powiadom($k, 'Bon WSTRZYMANY – zamówienie ' . $zamowienie, implode("\n", [
            'Bon(y) czekają na ręczne zatwierdzenie (status held).',
            'Powód: ' . implode('; ', $uwagi),
            'Kody: ' . implode(', ', $kody),
            'Zamówienie Easytools: ' . $zamowienie . ', wariant: ' . ($priceId ?: 'brak') . ', kod rabatowy: ' . ($promo ?: 'brak'),
            'Zapłacono razem: ' . bon_zl($zaplaconoRazem) . ' zł, sztuk: ' . $ilosc,
        ]));
    }
    // trzeci element: zadanie PO odpowiedzi 200 (PDF + mail) – webhook.php woła je po fastcgi_finish_request; wstrzymane bez maila
    $poOdpowiedzi = $status === 'valid' ? static fn() => bon_wyslij_zamowienie($k, $pdo, $zamowienie) : null;
    return [200, ['ok' => true, 'bonow' => count($kody), 'status' => $status], $poOdpowiedzi];
}

// --- verify (publiczny) ---

// limit zapytań na IP i minutę; true = można
function bon_limit_ok(PDO $pdo, array $k, string $ip): bool {
    $tab = bon_tabela($k, 'voucher_verify_limit');
    $klucz = hash('sha256', $ip . '|' . ($k['easytools_webhook_sekret'] ?? '') . '|limit');
    $okno = intdiv(time(), 60);
    $up = $pdo->prepare("UPDATE $tab SET licznik = licznik + 1 WHERE klucz = ? AND okno = ?");
    $up->execute([$klucz, $okno]);
    if ($up->rowCount() === 0) {
        try {
            $pdo->prepare("INSERT INTO $tab (klucz, okno, licznik) VALUES (?, ?, 1)")->execute([$klucz, $okno]);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') !== '23000') throw $e;
            $up->execute([$klucz, $okno]);
        }
        if (random_int(1, 50) === 1) $pdo->prepare("DELETE FROM $tab WHERE okno < ?")->execute([$okno - 60]);
    }
    $st = $pdo->prepare("SELECT licznik FROM $tab WHERE klucz = ? AND okno = ?");
    $st->execute([$klucz, $okno]);
    return (int)$st->fetchColumn() <= (int)$k['verify_limit_na_minute'];
}

/** Zwraca [kod HTTP, odpowiedź]. Bez danych osobowych: tylko status, saldo, wartość i ważność. */
function bon_obsluz_verify(array $k, string $wpisany, string $ip, ?callable $pdoFabryka = null, ?DateTimeImmutable $teraz = null): array {
    $kod = bon_normalizuj_kod($wpisany, (string)$k['kod_prefix']);
    if ($kod === null) return [400, ['ok' => false, 'blad' => 'zły format kodu']];
    $pdo = $pdoFabryka ? $pdoFabryka($k) : bon_pdo($k);
    if (!bon_limit_ok($pdo, $k, $ip)) return [429, ['ok' => false, 'blad' => 'za dużo zapytań – spróbuj za minutę']];
    $st = $pdo->prepare('SELECT status, initial_pln, balance_pln, expires_at FROM ' . bon_tabela($k, 'vouchers') . ' WHERE code = ?');
    $st->execute([$kod]);
    $bon = $st->fetch();
    if (!$bon) return [404, ['ok' => false, 'znaleziono' => false]];
    $status = bon_status_efektywny($bon, $teraz);
    $odp = ['ok' => true, 'znaleziono' => true, 'kod' => $kod, 'status' => $status, 'wazny_do' => substr((string)$bon['expires_at'], 0, 10)];
    // saldo tylko dla bonu, którym można płacić albo który się zużył; wstrzymany / zwrócony / wygasły – sam status
    if ($status === 'valid' || $status === 'used') {
        $odp['saldo'] = bon_zl((int)bon_grosze($bon['balance_pln']));
        $odp['wartosc'] = bon_zl((int)bon_grosze($bon['initial_pln']));
    }
    return [200, $odp];
}

// --- odpowiedź HTTP ---
function bon_json(int $kod, array $dane): void {
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($dane, JSON_UNESCAPED_UNICODE);
}
