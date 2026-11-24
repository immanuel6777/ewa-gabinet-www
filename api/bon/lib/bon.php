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
    // 2026-11-24 (wpis 11:30): domyślne w kodzie ← lib/ustawienia.php z repo ← konfig/bony.php na serwerze (serwer wygrywa)
    $k['_serwer_klucze'] = array_keys($k);
    $k['_katalog'] = dirname($plik);
    return $k + bon_ustawienia_repo() + [
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
        // synchronizacja z API Easytools (lib/easytools.php, 2026-11-23): nazwa wariantu z API → nominał
        // 2026-11-24: nowe nazwy w Easytools (Bartek 01:50); stare zostają dla wcześniejszych zamówień.
        // Wpis „warianty” w konfigu ZASTĘPUJE tę mapę w całości (bez scalania), więc musi mieć oba komplety.
        'warianty' => [
            'Bon 200 zł' => 200, 'Bon 300 zł' => 300, 'Bon 500 zł' => 500, 'Dowolna kwota (od 180 zł)' => 'dowolna',
            'Bon Szmaragdowy' => 200, 'Bon Rubinowy' => 300, 'Bon Bursztynowy' => 500, 'Bon Szafirowy' => 'dowolna',
        ],
        // nazwa bonu w mailu wg rodzaju (nie wg nazwy z Easytools): nominał 200/300/500 albo 'dowolna'
        'nazwy_bonow' => [200 => 'Bon Szmaragdowy', 300 => 'Bon Rubinowy', 500 => 'Bon Bursztynowy', 'dowolna' => 'Bon Szafirowy'],
        'sync_tryb' => 'raport',
        'sync_dni' => 7,
        'sync_odstep_s' => 20,
    ];
}

/** Ustawienia jawne z repo (lib/ustawienia.php); BONY_USTAWIENIA podmienia plik (testy), 'brak' = bez pliku. Zły plik = wyjątek. */
function bon_ustawienia_repo(): array {
    $plik = getenv('BONY_USTAWIENIA') ?: __DIR__ . '/ustawienia.php';
    if ($plik === 'brak' || !is_file($plik)) return [];
    $u = require $plik;
    if (!is_array($u)) throw new RuntimeException('lib/ustawienia.php nie zwraca tablicy');
    return $u;
}

/** Ustawienia jawne (wpis 11:30) – w log.php ze źródłem wartości: kod / repo / serwer. */
const BON_USTAWIENIA_JAWNE = [
    'sync_tryb', 'sync_od', 'sync_dni', 'sync_odstep_s', 'warianty', 'nazwy_bonow', 'ceny', 'prog_wstrzymania', 'min_dowolna_pln',
    'waznosc_miesiecy', 'kod_prefix', 'verify_limit_na_minute', 'mail_nadawca', 'mail_odpowiedz', 'mail_powiadomienie',
    'telefon_pomoc_bony', 'telefon_pomoc_bony_godziny', 'mail_zapas', 'mail_smtp_host', 'mail_smtp_port', 'mail_smtp_user',
    'webhook_tryb', 'kwota_jednostka', 'easytools_tryb', 'easytools_produkt_uuid', 'bon_adres_pelny_min',
    'mail_zapas_test_do', // przełącznik testu zapasu – z repo zamiast FTP (wpis 11:35)
];

/**
 * Skąd jest każde ustawienie jawne: ['zrodlo' => kod|repo|serwer, 'inne_niz_repo' => bool]. Bez wartości (adresy w mail_*).
 * „do_usuniecia” = klucze jawne w pliku na serwerze – po sprzątaniu lista ma być pusta (serwer trzyma tylko sekrety i konta).
 */
function bon_ustawienia_zrodla(array $k): array {
    $repo = bon_ustawienia_repo();
    $serwer = array_flip((array)($k['_serwer_klucze'] ?? []));
    $wynik = ['ustawienia' => [], 'do_usuniecia' => [], 'serwer_nadpisuje_inaczej' => []];
    foreach (BON_USTAWIENIA_JAWNE as $kl) {
        $zrodlo = isset($serwer[$kl]) ? 'serwer' : (array_key_exists($kl, $repo) ? 'repo' : 'kod');
        $inne = $zrodlo === 'serwer' && array_key_exists($kl, $repo) && $repo[$kl] != ($k[$kl] ?? null);
        $wynik['ustawienia'][$kl] = $zrodlo;
        if ($zrodlo === 'serwer') $wynik['do_usuniecia'][] = $kl;
        if ($inne) $wynik['serwer_nadpisuje_inaczej'][] = $kl;
    }
    return $wynik;
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

// --- warianty i nazwy bonów ---

/** nazwa wariantu (Easytools) → nominał z mapy „warianty” (liczba zł | 'dowolna') albo null. Bez wielkości liter i twardych spacji. */
function bon_wariant_nominal(array $k, string $nazwa): int|float|string|null {
    $norm = static fn(string $s): string => mb_strtolower(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $s) ?? ''), 'UTF-8');
    $szukana = $norm($nazwa);
    foreach ((array)($k['warianty'] ?? []) as $n => $w) if ($norm((string)$n) === $szukana) return $w;
    return null;
}

/**
 * Nazwa bonu do maila wg rodzaju: z price_id (identyfikator z „ceny” albo nazwa z „warianty”) ustala, czy to dowolna kwota;
 * inaczej po wartości (200/300/500). Nieznany rodzaj → „Bon podarunkowy”. Nazwa to tylko etykieta, kwota zawsze z initial_pln.
 */
function bon_nazwa(array $k, array $bon): string {
    $rodzaj = bon_rodzaj($k, $bon);
    return $rodzaj === null ? 'Bon podarunkowy' : (string)(((array)($k['nazwy_bonow'] ?? []))[$rodzaj] ?? 'Bon podarunkowy');
}

/**
 * Rodzaj bonu: 'dowolna' (po price_id: identyfikator z „ceny” albo nazwa z „warianty”), inaczej 200 / 300 / 500 po wartości;
 * inna wartość = null. Wspólne dla nazwy w mailu i koloru tła PDF (2026-11-24).
 */
function bon_rodzaj(array $k, array $bon): int|string|null {
    $id = (string)($bon['price_id'] ?? '');
    $zMapy = $id !== '' ? ((array)($k['ceny'] ?? []))[$id] ?? bon_wariant_nominal($k, $id) : null;
    if ($zMapy === 'dowolna') return 'dowolna';
    $grosze = (int)bon_grosze($bon['initial_pln'] ?? null);
    return in_array($grosze, [20000, 30000, 50000], true) ? intdiv($grosze, 100) : null;
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

// --- terminy z regulaminu (krok 4, 2026-11-23; te same funkcje użyją /bon w krokach 2 i 5) ---
// Daty w czasie polskim, liczone na dniach kalendarzowych (bez godzin). created_at bonu = chwila ZAPŁATY (bon_data_zaplaty),
// nie utworzenia wiersza – regulamin liczy 14 dni i 6 miesięcy „od dnia zapłaty”.

/**
 * Chwila zapłaty zamówienia (2026-11-23): od jej dnia liczą się 14 dni na rezygnację i 6 miesięcy ważności. Bon utworzony
 * przez synchronizację po północy nie może dostać dnia utworzenia zamiast dnia zapłaty.
 * $zrodlo: tekst daty z API (ISO 8601: „date” transakcji, awaryjnie created_at zamówienia) albo liczba sekund unix
 * (webhook: timestamp). Brak, nieczytelna albo spoza [teraz − 30 dni, teraz + 5 min] → $teraz.
 */
function bon_data_zaplaty(mixed $zrodlo, DateTimeImmutable $teraz): DateTimeImmutable {
    $strefa = new DateTimeZone(BON_STREFA);
    try {
        if (is_int($zrodlo) || (is_string($zrodlo) && ctype_digit($zrodlo))) $d = new DateTimeImmutable('@' . (int)$zrodlo);
        elseif (is_string($zrodlo) && trim($zrodlo) !== '') $d = new DateTimeImmutable($zrodlo, $strefa);
        else return $teraz;
    } catch (Throwable) {
        return $teraz;
    }
    $d = $d->setTimezone($strefa);
    if ($d > $teraz->modify('+5 minutes') || $d < $teraz->modify('-30 days')) return $teraz;
    return $d;
}

/** Ostatni dzień na rezygnację: dzień zapłaty + 14 (dzień zapłaty się nie liczy; 14. dzień jeszcze w terminie, 15. już nie). */
function bon_rezygnacja_termin(array $bon): string {
    $zaplata = new DateTimeImmutable(substr((string)$bon['created_at'], 0, 10), new DateTimeZone(BON_STREFA));
    return $zaplata->modify('+14 days')->format('Y-m-d');
}

/** Czy oświadczenie z dnia $data (RRRR-MM-DD: wysłanie maila / listu, złożenie w gabinecie, formularz na /bon) mieści się w terminie. */
function bon_rezygnacja_w_terminie(array $bon, string $data): bool {
    return $data >= substr((string)$bon['created_at'], 0, 10) && $data <= bon_rezygnacja_termin($bon);
}

const BON_PRZEDLUZENIE_MIESIECY = 3;
const BON_PRZEDLUZENIE_OKNO_DNI = 30;

/**
 * Jedno bezpłatne przedłużenie o 3 miesiące: bon ważny w bazie (także „po terminie” liczony z daty), saldo > 0, jeszcze nieprzedłużany,
 * dziś w oknie od 30 dni przed końcem ważności do 30 dni po nim. Nowa data = obecny koniec + 3 mies. (arytmetyka bon_waznosc).
 * Zwraca ['od','do','nowa'] albo null (przedłużenie niemożliwe).
 */
function bon_przedluzenie_okno(array $bon, ?DateTimeImmutable $teraz = null): ?array {
    if (!array_key_exists('przedluzony_at', $bon)) return null; // przed migracją kroku 1 na OVH: bez przycisku (UPDATE by się wywalił)
    if ($bon['status'] !== 'valid' || !empty($bon['przedluzony_at']) || (int)bon_grosze($bon['balance_pln']) <= 0) return null;
    $koniec = new DateTimeImmutable(substr((string)$bon['expires_at'], 0, 10), new DateTimeZone(BON_STREFA));
    $od = $koniec->modify('-' . BON_PRZEDLUZENIE_OKNO_DNI . ' days')->format('Y-m-d');
    $do = $koniec->modify('+' . BON_PRZEDLUZENIE_OKNO_DNI . ' days')->format('Y-m-d');
    $dzis = ($teraz ?? bon_teraz())->format('Y-m-d');
    if ($dzis < $od || $dzis > $do) return null;
    return ['od' => $od, 'do' => $do, 'nowa' => bon_waznosc($koniec, BON_PRZEDLUZENIE_MIESIECY)];
}

/**
 * Przedłużenie (panel: każda rola; /bon w kroku 5: klient). Wołać w transakcji, z bonem odczytanym FOR UPDATE.
 * Zwraca [kod HTTP, odpowiedź]. UPDATE z warunkiem przedluzony_at IS NULL – drugie kliknięcie / drugie urządzenie nie przedłuży dwa razy.
 */
function bon_przedluz(array $k, PDO $pdo, array $bon, string $kto, ?DateTimeImmutable $teraz = null): array {
    $teraz ??= bon_teraz();
    $okno = bon_przedluzenie_okno($bon, $teraz);
    if (!$okno) {
        $powod = !empty($bon['przedluzony_at']) ? 'Bon był już przedłużany – drugie bezpłatne przedłużenie nie przysługuje.'
            : ($bon['status'] !== 'valid' || (int)bon_grosze($bon['balance_pln']) <= 0 ? 'Tego bonu nie można przedłużyć.'
            : 'Przedłużenie jest możliwe od 30 dni przed końcem ważności do 30 dni po nim.');
        return [409, ['ok' => false, 'blad' => $powod]];
    }
    $stara = substr((string)$bon['expires_at'], 0, 10);
    $up = $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . ' SET expires_at = ?, expires_at_pierwotna = ?, przedluzony_at = ?
                         WHERE id = ? AND przedluzony_at IS NULL AND status = \'valid\'');
    $up->execute([$okno['nowa'], $stara, $teraz->format('Y-m-d H:i:s'), $bon['id']]);
    if ($up->rowCount() !== 1) return [409, ['ok' => false, 'blad' => 'Bon był już przedłużany – drugie bezpłatne przedłużenie nie przysługuje.']];
    $opis = "$stara → {$okno['nowa']} (o " . BON_PRZEDLUZENIE_MIESIECY . ' mies.)';
    bon_historia($k, $pdo, (int)$bon['id'], 'przedluzenie', $opis, $kto, $teraz);
    return [200, ['ok' => true, 'akcja' => 'przedluzenie', 'opis' => 'ważność przedłużona: ' . $opis, 'wazny_do' => $okno['nowa']]];
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

// --- dziennik zdarzeń (2026-11-22): co backend ZROBIŁ – wynik webhooka, wysyłka PDF, błędy, logowania, realizacje, zmiany admina.
// Bez danych osobowych (e-maile wycinane także z treści błędów). konfig/bony-zdarzenia/RRRR-MM-DD.log (JSONL), czyta api/bon/log.php.
// Pliki starsze niż 90 dni usuwane przy okazji. Błąd zapisu nigdy nie psuje obsługi żądania.
function bon_zdarzenie(array $k, string $typ, array $dane = []): void {
    try {
        if ((string)($k['_katalog'] ?? '') === '') return;
        $katalog = $k['_katalog'] . '/bony-zdarzenia';
        if (!is_dir($katalog) && !@mkdir($katalog, 0700, true)) return;
        $plik = $katalog . '/' . bon_teraz()->format('Y-m-d') . '.log';
        if (is_file($plik) && filesize($plik) > 5 * 1024 * 1024) return;
        $json = json_encode(['czas' => bon_teraz()->format('c'), 'typ' => $typ] + $dane, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $json = preg_replace('/[^\s@"]+@[^\s@"]+\.[^\s@"]+/', '***', (string)$json) ?? '';
        @file_put_contents($plik, $json . "\n", FILE_APPEND | LOCK_EX);
        if (random_int(1, 100) === 1) foreach (glob($katalog . '/*.log') ?: [] as $stary) if ((int)filemtime($stary) < time() - 90 * 86400) @unlink($stary);
    } catch (Throwable) {}
}

/** Wyjątek do dziennika: klasa, kod (przy bazie numer błędu MySQL, np. 1045 = złe hasło), treść bez 'użytkownik'@'host'. */
function bon_opis_bledu(Throwable $e): array {
    $opis = preg_replace("/'[^']*'@'[^']*'/", "'***'@'***'", $e->getMessage()) ?? '';
    return ['wyjatek' => get_class($e), 'kod' => $e instanceof PDOException ? ($e->errorInfo[1] ?? $e->getCode()) : $e->getCode(), 'opis' => mb_substr($opis, 0, 300)];
}

// kod bonu w dzienniku: EWA-****-AB12 (pełny numer pozwala sprawdzić saldo)
function bon_kod_maska(string $kod): string {
    return $kod === '' ? '' : 'EWA-****-' . substr($kod, -4);
}

// --- historia bonu (krok 1, 2026-11-23): tabela voucher_admin_log = pełna historia z jednego miejsca ---
// kto: login panelu, 'klient' (strona /bon) albo 'system' (webhook, synchronizacja, cron). Realizacje są osobno w redemptions.
// Bez danych osobowych w szczegółach (panel pokazuje je kasie i adminowi).
const BON_HISTORIA_AKCJE = [
    'utworzenie', 'mail', 'dla-od',                                  // zakup: bon powstał, PDF wysłany, Dla/Od uzupełnione z webhooka
    'waznosc', 'zatwierdz', 'zwrot',                                 // admin w panelu (etap 3)
    'rezygnacja', 'rezygnacja-cofnieta', 'przedluzenie', 'przypomnienie', // krok 2, 5, 6 (cofnięcie: pomyłka albo klient się wycofał)
    'zwrot-zlecony', 'zwrot-potwierdzony', 'zwrot-nieudany',         // krok 3
    'mail-odbity', 'adres', 'mail-ponownie', 'pdf',                  // 2026-11-24 (TA-11): odbicie, zmiana adresu, ponowna wysyłka, PDF z panelu
];

function bon_historia(array $k, PDO $pdo, int $voucherId, string $akcja, string $szczegoly, string $kto, ?DateTimeImmutable $teraz = null): void {
    if (!in_array($akcja, BON_HISTORIA_AKCJE, true)) throw new InvalidArgumentException('nieznana akcja historii bonu: ' . $akcja);
    $pdo->prepare('INSERT INTO ' . bon_tabela($k, 'voucher_admin_log') . ' (voucher_id, akcja, szczegoly, kto, kiedy) VALUES (?, ?, ?, ?, ?)')
        ->execute([$voucherId, $akcja, mb_substr($szczegoly, 0, 255), mb_substr($kto, 0, 40), ($teraz ?? bon_teraz())->format('Y-m-d H:i:s')]);
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

    // id zamówienia: order_uuid (ten sam UUID, który zwraca API Easytools i parametr ec_order_id powrotu z kasy) –
    // dzięki temu bon z synchronizacji (lib/easytools.php) i bon z webhooka trafiają na ten sam klucz unikalny i się nie dublują.
    // order_id (liczba) tylko awaryjnie, gdy webhook nie niesie UUID.
    $zamowienie = $d['order_uuid'] ?? null;
    if (!is_string($zamowienie) || !preg_match('/^[0-9a-f-]{36}$/i', $zamowienie) || $zamowienie === '00000000-0000-0000-0000-000000000000') {
        $zamowienie = $d['order_id'] ?? null;
    }
    if (!is_int($zamowienie) && !(is_string($zamowienie) && $zamowienie !== '')) return [400, ['ok' => false, 'blad' => 'brak order_id']];

    $email = bon_tekst((string)($d['customer_email'] ?? ''), 190);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [400, ['ok' => false, 'blad' => 'brak e-maila']];

    $priceId = bon_tekst((string)($d['price_id'] ?? ''), 100);
    $nip = is_array($d['invoice_data'] ?? null) ? bon_tekst((string)($d['invoice_data']['nip'] ?? ''), 20) : '';
    $pdo = $pdoFabryka ? $pdoFabryka($k) : bon_pdo($k);
    return bon_utworz_bony($k, $pdo, [
        'zrodlo' => 'webhook',
        'zamowienie' => (string)$zamowienie,
        'transakcja' => null,
        'email' => $email,
        'imie' => (string)($d['customer_name'] ?? ''),
        'nip' => $nip,
        'waluta' => (string)($d['currency'] ?? ''),
        'ilosc' => $d['quantity'] ?? 1,
        'zaplacono' => bon_grosze($d['amount_paid'] ?? null, (string)$k['kwota_jednostka']),
        'wariant' => $priceId,
        'nominal' => $k['ceny'][$priceId] ?? ($k['ceny'][(string)($d['price_custom_id'] ?? '')] ?? null),
        'promo' => is_scalar($d['promo_code'] ?? null) ? (string)$d['promo_code'] : '',
        'dla' => bon_pole_wlasne($d['custom_fields'] ?? null, (array)$k['pole_dla']),
        'od' => bon_pole_wlasne($d['custom_fields'] ?? null, (array)$k['pole_od']),
        'zaplacono_at' => $d['timestamp'] ?? null, // chwila zdarzenia w Easytools (webhook przychodzi zaraz po zapłacie)
    ]);
}

/**
 * Bony z jednego opłaconego zamówienia – wspólne dla webhooka i synchronizacji z API Easytools (2026-11-23).
 * $z (już znormalizowane przez wołającego):
 *   zrodlo 'webhook'|'api', zamowienie (UUID), transakcja (?UUID transakcji Easytools), email, imie, nip, waluta,
 *   ilosc (surowa wartość), zaplacono (?int grosze za całe zamówienie), wariant (identyfikator do kolumny price_id),
 *   nominal (z mapy: liczba zł | 'dowolna' | null = nieznany wariant), promo, dla, od,
 *   zaplacono_at (opcjonalnie: chwila zapłaty, zob. bon_data_zaplaty – z niej created_at i expires_at).
 * Zwraca [200, odpowiedź, ?callable wysyłka PDF po odpowiedzi]. Powtórka (klucz unikalny zamówienie+nr) niczego nie dubluje,
 * a jej callable dokańcza nieudaną wcześniej wysyłkę.
 */
function bon_utworz_bony(array $k, PDO $pdo, array $z): array {
    $zamowienie = mb_substr((string)$z['zamowienie'], 0, 64);
    $email = bon_tekst((string)$z['email'], 190);
    $transakcja = is_string($z['transakcja'] ?? null) && $z['transakcja'] !== '' ? mb_substr($z['transakcja'], 0, 64) : null;
    $tab = bon_tabela($k, 'vouchers');

    if (strtolower((string)$z['waluta']) !== 'pln') {
        bon_powiadom($k, 'Bon: zamówienie w innej walucie', "Zamówienie $zamowienie, waluta: " . ($z['waluta'] ?: '?') . ". Bon NIE powstał – sprawdź w Easytools.");
        return [200, ['ok' => true, 'pominieto' => 'waluta']];
    }

    // ilość: pieniądze już wpłynęły, więc nic nie odrzucamy – dziwna ilość = bon wstrzymany do sprawdzenia
    $uwagi = [];
    $ilosc = $z['ilosc'] ?? 1;
    if (is_string($ilosc) && ctype_digit($ilosc)) $ilosc = (int)$ilosc;
    if (!is_int($ilosc) || $ilosc < 1) {
        $uwagi[] = 'zła ilość (quantity: ' . json_encode($z['ilosc'] ?? null) . ') – utworzono 1 bon, sprawdź zamówienie';
        $ilosc = 1;
    } elseif ($ilosc > 50) {
        $uwagi[] = "ilość $ilosc > 50 – utworzono 50 bonów, resztę dodaj ręcznie";
        $ilosc = 50;
    }

    // --- wartość bonu: nominał wariantu z konfigu; „dowolna” = kwota zapłacona ---
    $zaplaconoRazem = $z['zaplacono'];
    if (!is_int($zaplaconoRazem) || $zaplaconoRazem < 0) { $uwagi[] = 'brak lub zła kwota zapłacona'; $zaplaconoRazem = 0; }
    $zaplaconoJedn = intdiv($zaplaconoRazem, $ilosc);

    $wariantId = bon_tekst((string)$z['wariant'], 100);
    $wariant = $z['nominal'];
    if ($wariant === 'dowolna') {
        $wartosc = $zaplaconoJedn;
        if ($wartosc < (int)$k['min_dowolna_pln'] * 100) $uwagi[] = 'dowolna kwota poniżej minimum ' . $k['min_dowolna_pln'] . ' zł';
    } elseif (is_int($wariant) || is_float($wariant) || (is_string($wariant) && is_numeric($wariant))) {
        $wartosc = (int)round((float)$wariant * 100);
        if ($zaplaconoJedn < (int)round($wartosc * (float)$k['prog_wstrzymania'])) {
            $uwagi[] = 'zapłacono ' . bon_zl($zaplaconoJedn) . ' zł przy nominale ' . bon_zl($wartosc) . ' zł (próg ' . ((float)$k['prog_wstrzymania'] * 100) . '%)';
        } elseif ($zaplaconoJedn > $wartosc) {
            // np. ilość nie doszła albo kwota to coś innego niż suma zamówienia – mogło przepaść kilka bonów
            $uwagi[] = 'zapłacono ' . bon_zl($zaplaconoJedn) . ' zł za bon przy nominale ' . bon_zl($wartosc) . ' zł – więcej niż nominał, sprawdź ilość';
        }
    } else {
        $wartosc = $zaplaconoJedn;
        $uwagi[] = $z['zrodlo'] === 'api'
            ? 'nieznany wariant ceny („' . ($wariantId ?: 'brak') . '”) – dopisz nazwę wariantu w konfigu w „warianty”'
            : 'nieznany wariant ceny (price_id: ' . ($wariantId ?: 'brak') . ') – dopisz go w konfigu w „ceny”';
    }
    if ($wartosc <= 0) $uwagi[] = 'wartość bonu 0 zł';
    $status = $uwagi ? 'held' : 'valid';

    $teraz = bon_teraz(); // chwila utworzenia (historia); terminy bonu liczone od zapłaty
    $zaplata = bon_data_zaplaty($z['zaplacono_at'] ?? null, $teraz);
    $promo = bon_tekst((string)($z['promo'] ?? ''), 100);
    $nip = bon_tekst((string)($z['nip'] ?? ''), 20);
    $wspolne = [
        'initial_pln' => bon_zl($wartosc),
        'balance_pln' => bon_zl($wartosc),
        'price_id' => $wariantId,
        'promo_code' => $promo === '' ? null : $promo,
        'buyer_email' => $email,
        'buyer_name' => bon_tekst((string)($z['imie'] ?? ''), 120) ?: null,
        'buyer_nip' => $nip === '' ? null : $nip,
        'dla' => $z['dla'] ?? null,
        'od' => $z['od'] ?? null,
        'status' => $status,
        'status_uwagi' => $uwagi ? mb_substr(implode('; ', $uwagi), 0, 255) : null,
        'easytools_order_id' => $zamowienie,
        'easytools_transaction_id' => $transakcja,
        'created_at' => $zaplata->format('Y-m-d H:i:s'),
        'expires_at' => bon_waznosc($zaplata, (int)$k['waznosc_miesiecy']),
    ];

    $sql = "INSERT INTO $tab (code, initial_pln, balance_pln, paid_pln, price_id, promo_code, buyer_email, buyer_name, buyer_nip, dla, od,
              status, status_uwagi, easytools_order_id, easytools_transaction_id, nr_w_zamowieniu, created_at, expires_at)
            VALUES (:code, :initial_pln, :balance_pln, :paid_pln, :price_id, :promo_code, :buyer_email, :buyer_name, :buyer_nip, :dla, :od,
              :status, :status_uwagi, :easytools_order_id, :easytools_transaction_id, :nr, :created_at, :expires_at)";

    // idempotencja: UNIQUE (easytools_order_id, nr_w_zamowieniu); powtórka (webhook, synchronizacja) → 23000 → nic nowego nie powstaje
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
                bon_historia($k, $pdo, (int)$pdo->lastInsertId(), 'utworzenie', 'źródło: ' . $z['zrodlo'] . ', status: ' . $status
                    . ', zapłacono ' . bon_zl($zaplacono) . ' zł' . ($nr > 1 || $ilosc > 1 ? ", sztuka $nr z $ilosc" : ''), 'system', $teraz);
            }
            $pdo->commit();
            break;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (($e->errorInfo[0] ?? $e->getCode()) !== '23000') throw $e;
            $ile = $pdo->prepare("SELECT COUNT(*) FROM $tab WHERE easytools_order_id = ?");
            $ile->execute([$zamowienie]);
            if ((int)$ile->fetchColumn() > 0) {
                // bony już są; uzupełnij id transakcji (webhook go nie niesie) i dokończ ewentualną nieudaną wysyłkę (po odpowiedzi)
                if ($transakcja !== null) {
                    $pdo->prepare("UPDATE $tab SET easytools_transaction_id = ? WHERE easytools_order_id = ? AND easytools_transaction_id IS NULL")
                        ->execute([$transakcja, $zamowienie]);
                }
                // webhook po synchronizacji: bon z API nie ma Dla/Od – uzupełnij puste (Bartek 2026-11-23). Maila NIE wysyłamy ponownie:
                // zadanie niżej wysyła tylko bony z mail_wyslany_at = NULL. Wpisanych wartości nie nadpisujemy.
                foreach (['dla', 'od'] as $pole) {
                    $w = $z[$pole] ?? null;
                    if (is_string($w) && trim($w) !== '') {
                        $ids = $pdo->prepare("SELECT id FROM $tab WHERE easytools_order_id = ? AND ($pole IS NULL OR $pole = '')");
                        $ids->execute([$zamowienie]);
                        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
                            $pdo->prepare("UPDATE $tab SET $pole = ? WHERE id = ?")->execute([$w, $id]);
                            bon_historia($k, $pdo, (int)$id, 'dla-od', "„" . ($pole === 'dla' ? 'Dla' : 'Od') . "” uzupełnione z webhooka", 'system');
                        }
                    }
                }
                return [200, ['ok' => true, 'powtorzony' => true], static fn() => bon_wyslij_zamowienie($k, $pdo, $zamowienie)];
            }
            if ($proba === 3) throw $e; // trzy kolizje kodu z rzędu – nie powinno się zdarzyć
        }
    }

    if ($status === 'held') {
        bon_powiadom($k, 'Bon WSTRZYMANY – zamówienie ' . $zamowienie, implode("\n", [
            'Bon(y) czekają na ręczne zatwierdzenie (status held).',
            'Powód: ' . implode('; ', $uwagi),
            'Kody: ' . implode(', ', $kody),
            'Zamówienie Easytools: ' . $zamowienie . ', wariant: ' . ($wariantId ?: 'brak') . ', kod rabatowy: ' . ($promo ?: ($z['zrodlo'] === 'api' ? 'nieznany (API go nie podaje)' : 'brak')),
            'Zapłacono razem: ' . bon_zl($zaplaconoRazem) . ' zł, sztuk: ' . $ilosc . ', źródło: ' . $z['zrodlo'],
        ]));
    }
    // trzeci element: zadanie PO odpowiedzi (PDF + mail) – woła je po fastcgi_finish_request; wstrzymane bez maila
    $poOdpowiedzi = $status === 'valid' ? static fn() => bon_wyslij_zamowienie($k, $pdo, $zamowienie) : null;
    return [200, ['ok' => true, 'bonow' => count($kody), 'status' => $status], $poOdpowiedzi];
}

// --- verify (publiczny) ---

// limit zapytań na IP i minutę; true = można. $cel rozdziela liczniki (verify / adres na stronie podziękowania).
function bon_limit_ok(PDO $pdo, array $k, string $ip, string $cel = 'limit', ?int $max = null): bool {
    $tab = bon_tabela($k, 'voucher_verify_limit');
    $klucz = hash('sha256', $ip . '|' . ($k['easytools_webhook_sekret'] ?? '') . '|' . $cel);
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
    return (int)$st->fetchColumn() <= ($max ?? (int)$k['verify_limit_na_minute']);
}

// --- stan zamówienia dla /bony/dziekujemy (Bartek 2026-11-24, wpisy 10:00 i 10:30; TA-10) ---

/** „kasia.nowak@gmail.com” → „k…k@gmail.com”; jedna litera przed @ → „k…@gmail.com”. Domena w całości. */
function bon_adres_maska(string $adres): string {
    $i = strrpos($adres, '@');
    if ($i === false || $i === 0) return '…';
    $lok = mb_substr($adres, 0, $i);
    $n = mb_strlen($lok);
    return mb_substr($lok, 0, 1) . '…' . ($n > 1 ? mb_substr($lok, -1) : '') . substr($adres, $i);
}

/**
 * Stan bonów zamówienia z adresem, na który poszedł bon: pełnym do bon_adres_pelny_min (60) minut od zapłaty, potem zamaskowanym.
 * Identyfikator zamówienia służy WYŁĄCZNIE do odczytu (bony tworzy tylko synchronizacja z API). Tylko UUID, tylko zamówienia
 * z ostatnich 7 dni, osobny limit na IP. Adres nigdy nie trafia do dziennika ani do log.php – zwracany jest tylko kupującemu.
 * Zwraca: null (zły format albo limit – endpoint odpowiada wtedy samym {ok:true}) albo
 * ['stan' => wyslany|w_toku|wstrzymany|nieznane, + adres, adres_pelny, bonow przy wyslany / w_toku].
 */
function bon_stan_zamowienia(array $k, PDO $pdo, string $zamowienie, string $ip, ?DateTimeImmutable $teraz = null): ?array {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $zamowienie)) return null;
    if (!bon_limit_ok($pdo, $k, $ip, 'adres', (int)($k['adres_limit_na_minute'] ?? 10))) return null;
    $teraz ??= bon_teraz();
    $st = $pdo->prepare('SELECT status, buyer_email, mail_wyslany_at, created_at FROM ' . bon_tabela($k, 'vouchers')
        . ' WHERE easytools_order_id = ? ORDER BY id');
    $st->execute([$zamowienie]);
    $bony = $st->fetchAll();
    $zaplata = $bony ? new DateTimeImmutable((string)$bony[0]['created_at'], new DateTimeZone(BON_STREFA)) : null;
    if (!$bony || $zaplata < $teraz->modify('-7 days')) return ['stan' => 'nieznane'];
    if (array_filter($bony, static fn($b) => $b['status'] === 'held')) return ['stan' => 'wstrzymany'];
    $adres = trim((string)$bony[0]['buyer_email']);
    if ($adres === '') return ['stan' => 'nieznane'];
    $wyslane = !array_filter($bony, static fn($b) => $b['mail_wyslany_at'] === null);
    $pelny = $teraz->getTimestamp() - $zaplata->getTimestamp() <= 60 * (int)($k['bon_adres_pelny_min'] ?? 60);
    return ['stan' => $wyslane ? 'wyslany' : 'w_toku', 'adres' => $pelny ? $adres : bon_adres_maska($adres), 'adres_pelny' => $pelny, 'bonow' => count($bony)];
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
