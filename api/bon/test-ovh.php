<?php
// Test backendu bonów NA OVH (MySQL), na osobnych tabelach test_* (etap 1: 2026-11-21).
// Otwórz: https://www.ewa-gabinet.pl/api/bon/test-ovh.php?token=<test_token z konfig/bony.php>
// Bez test_token w konfigu (albo krótszego niż 24 znaki) zwraca 404 – po teście usuń test_token z konfigu.
//
// Co robi: wypisuje wersję PHP i rozszerzenia (GD do PDF-a w etapie 2), tworzy test_vouchers / test_redemptions /
// test_voucher_verify_limit jako kopie prawdziwych tabel (CREATE TABLE … LIKE – wymaga wgranego plan/bony.sql),
// przepuszcza te same scenariusze co testy lokalne (lib/testy.php) i usuwa tabele test_* (?zostaw=1 = zostaw do podglądu).
// Prawdziwych tabel bonów ani zgloszenia_staz nie zmienia. Nie wysyła maili (testy w trybie „plik”), ale generuje PDF-y (TCPDF + GD).

declare(strict_types=1);
// etap 3: tabele panelu (sesje, nieudane logowania, dziennik admina); kolejność odwrotna przy usuwaniu (vouchers na końcu)
// krok 1 (2026-11-23): rezygnacje i tokeny potwierdzeń (lib/migracje/krok1.sql). Gdy na OVH migracji jeszcze nie ma,
// test wykonuje ją na kopiach test_* – sprawdzenie migracji na prawdziwym MySQL przed wykonaniem na prawdziwych tabelach.
const BON_TABELE_KROK1 = ['voucher_withdrawals', 'voucher_withdrawal_tokens'];
// konta panelu w bazie (2026-11-25, lib/migracje/konta.sql): przed migracją test wykonuje ją na kopiach test_*, jak krok 1
const BON_TABELE_KONTA = ['voucher_accounts', 'voucher_account_log'];
const BON_TABELE = ['vouchers', 'redemptions', 'voucher_verify_limit', 'voucher_sessions', 'voucher_login_attempts', 'voucher_admin_log', 'voucher_withdrawals', 'voucher_withdrawal_tokens', 'voucher_accounts', 'voucher_account_log'];
const BON_TABELE_ODWROTNIE = ['voucher_account_log', 'voucher_accounts', 'voucher_withdrawal_tokens', 'voucher_withdrawals', 'redemptions', 'voucher_verify_limit', 'voucher_sessions', 'voucher_login_attempts', 'voucher_admin_log', 'vouchers'];
require __DIR__ . '/lib/bon.php';
require __DIR__ . '/lib/testy.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

try {
    $k = bon_konfig();
} catch (Throwable $e) {
    http_response_code(404);
    exit;
}
$token = (string)($k['test_token'] ?? '');
if (strlen($token) < 24 || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
$wiersz = static function (string $t) { echo $t, "\n"; flush(); };

// --- środowisko ---
$wiersz('== Środowisko PHP');
$wiersz('PHP: ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
foreach (['pdo_mysql', 'mbstring', 'openssl', 'curl', 'gd', 'json'] as $ext) $wiersz(sprintf('  %-10s %s', $ext, extension_loaded($ext) ? 'TAK' : 'NIE'));
if (extension_loaded('gd')) {
    $gd = gd_info();
    $wiersz('  GD: ' . ($gd['GD Version'] ?? '?') . ', PNG: ' . (!empty($gd['PNG Support']) ? 'tak' : 'nie') . ', FreeType: ' . (!empty($gd['FreeType Support']) ? 'tak' : 'nie'));
}
$wiersz('  date.timezone=' . (ini_get('date.timezone') ?: '(puste)') . ', memory_limit=' . ini_get('memory_limit') . ', max_execution_time=' . ini_get('max_execution_time'));
$wiersz('  mail(): ' . (function_exists('mail') ? 'jest' : 'BRAK'));
$wiersz('  konfiguracja: webhook_tryb=' . $k['webhook_tryb'] . ', kwota_jednostka=' . $k['kwota_jednostka'] . ', wariantów w „ceny”: ' . count((array)$k['ceny']));
// ważność bonu: z progów „kamienie” (wpis 21:47) – do 300 zł 6 mies., powyżej 12; regulamin musi mówić to samo
$wiersz('  kamienie: ' . implode('; ', array_map(static fn($p) => ($p['do_pln'] === null ? 'powyżej' : 'do ' . $p['do_pln'] . ' zł') . ' ' . $p['nazwa'] . ' ' . $p['waznosc_miesiecy'] . ' mies.', (array)$k['kamienie'])));
try {
    $kon = bon_kontakt($k);
    $wiersz('  kontakt.json (z buildu): telefon ' . $kon['telefon'] . ', ' . $kon['ulica']);
} catch (Throwable $e) {
    $wiersz('  kontakt.json: BRAK – mail i PDF bonu się nie wyślą (' . $e->getMessage() . ')');
}
$katalogLog = $k['_katalog'] . '/bony-webhook-log';
$wiersz('  log webhooka: ' . (is_dir($katalogLog) ? count(glob($katalogLog . '/*.log') ?: []) . ' plik(ów) w konfig/bony-webhook-log' : 'jeszcze brak katalogu (powstanie przy pierwszym webhooku)')
    . '; zapis do konfig/: ' . (is_writable($k['_katalog']) ? 'możliwy' : 'NIEMOŻLIWY'));

// --- baza ---
$wiersz("\n== Baza");
try {
    $pdo = bon_pdo($k);
    $wiersz('MySQL: ' . $pdo->query('SELECT VERSION()')->fetchColumn());
} catch (Throwable $e) {
    $wiersz('BŁĄD połączenia z bazą: ' . $e->getMessage());
    exit;
}
$brak = [];
foreach (BON_TABELE as $t) {
    try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); } catch (Throwable $e) { $brak[] = $t; }
}
// brak samych tabel kroku 1 = migracja jeszcze niewykonana → przećwiczymy ją na kopiach
$migrujKopie = (bool)array_intersect($brak, BON_TABELE_KROK1) && !array_diff(array_intersect($brak, BON_TABELE_KROK1), BON_TABELE_KROK1);
$migrujKonta = (bool)array_intersect($brak, BON_TABELE_KONTA);
if ($brak && !array_diff($brak, array_merge(BON_TABELE_KROK1, BON_TABELE_KONTA))) {
    if ($migrujKopie) $wiersz('Migracja kroku 1 jeszcze niewykonana na prawdziwych tabelach – wykonam ją na kopiach test_*.');
    if ($migrujKonta) $wiersz('Migracja kont (lib/migracje/konta.sql) jeszcze niewykonana na prawdziwych tabelach – wykonam ją na kopiach test_*.');
    $brak = [];
}
if ($brak) {
    $wiersz('Brak tabel: ' . implode(', ', $brak) . ' → wgraj plan/bony.sql w phpMyAdmin i odśwież.');
    exit;
}
$wiersz('Tabele ' . implode(', ', BON_TABELE) . ': są');

$kTest = array_replace($k, ['tabele_przedrostek' => 'test_', 'mail_powiadomienie' => []]);
foreach (BON_TABELE_ODWROTNIE as $t) $pdo->exec("DROP TABLE IF EXISTS test_$t");
foreach (BON_TABELE as $t) {
    if ($migrujKopie && in_array($t, BON_TABELE_KROK1, true)) continue; // powstaną z migracji
    if ($migrujKonta && in_array($t, BON_TABELE_KONTA, true)) continue;  // powstaną z migracji kont
    $pdo->exec("CREATE TABLE test_$t LIKE $t");
}
$wiersz('Utworzono kopie test_* tabel.');
if ($migrujKopie) {
    try {
        foreach (bon_migracja_na_kopiach((string)file_get_contents(__DIR__ . '/lib/migracje/krok1.sql')) as $polecenie) $pdo->exec($polecenie);
        $wiersz('Migracja kroku 1 na kopiach test_*: OK (lib/migracje/krok1.sql) – można ją wykonać w phpMyAdmin na prawdziwych tabelach.');
    } catch (Throwable $e) {
        $wiersz('BŁĄD migracji kroku 1 na kopiach test_*: ' . $e->getMessage() . ' – NIE wykonuj jej na prawdziwych tabelach.');
        foreach (BON_TABELE_ODWROTNIE as $t) $pdo->exec("DROP TABLE IF EXISTS test_$t");
        exit;
    }
}
if ($migrujKonta) {
    try {
        foreach (bon_migracja_na_kopiach((string)file_get_contents(__DIR__ . '/lib/migracje/konta.sql')) as $polecenie) {
            if (stripos($polecenie, 'SELECT') === 0) continue; // SELECT sprawdzający z końca pliku – tylko dla phpMyAdmina
            $pdo->exec($polecenie);
        }
        $wiersz('Migracja kont na kopiach test_*: OK (lib/migracje/konta.sql) – można ją wykonać w phpMyAdmin na prawdziwych tabelach.');
    } catch (Throwable $e) {
        $wiersz('BŁĄD migracji kont na kopiach test_*: ' . $e->getMessage() . ' – NIE wykonuj jej na prawdziwych tabelach.');
        foreach (BON_TABELE_ODWROTNIE as $t) $pdo->exec("DROP TABLE IF EXISTS test_$t");
        exit;
    }
}

// --- scenariusze ---
$wiersz("\n== Testy (te same co lokalnie na SQLite)");
$bledy = 0;
try {
    $wyniki = bon_testy($kTest, $pdo);
} catch (Throwable $e) {
    $wyniki = [['wyjątek w testach', false, $e->getMessage()]];
}
foreach ($wyniki as [$nazwa, $ok, $szczegol]) {
    if (!$ok) $bledy++;
    $wiersz(($ok ? '  ok   ' : '  BŁĄD ') . $nazwa . ($szczegol !== '' && (!$ok || str_contains($nazwa, 'informacyjnie')) ? "\n         → $szczegol" : ''));
}
$wiersz("\n" . (count($wyniki) - $bledy) . '/' . count($wyniki) . ' testów przeszło');

if (empty($_GET['zostaw'])) {
    foreach (BON_TABELE_ODWROTNIE as $t) $pdo->exec("DROP TABLE IF EXISTS test_$t");
    $wiersz('Tabele test_* usunięte.');
} else {
    $wiersz('Tabele test_* zostawione (?zostaw=1) – usuń je ręcznie albo uruchom test ponownie bez zostaw.');
}
@unlink($k['_katalog'] . '/bony-mailjet.jsonl'); // zapis testowych maili (tryb „plik”) – nic nie poszło do Mailjeta
@unlink($k['_katalog'] . '/bony-smtp.jsonl'); // zapas SMTP w trybie „plik” – nic nie poszło przez OVH
@unlink($k['_katalog'] . '/bony-powiadomienia.txt'); // testowe powiadomienia gabinetu (tryb „plik”) – nic nie poszło mailem
$wiersz('Po teście usuń test_token z konfig/bony.php.');

/** Polecenia migracji przepisane na tabele test_* (nazwy tabel i klucza obcego; komentarze usunięte). */
function bon_migracja_na_kopiach(string $sql): array {
    $sql = preg_replace('/--[^\n]*/', '', $sql) ?? '';
    $sql = preg_replace('/\b(vouchers|voucher_withdrawals|voucher_withdrawal_tokens|voucher_withdrawals_fk|voucher_sessions|voucher_accounts|voucher_account_log|voucher_account_log_login)\b/', 'test_$1', $sql) ?? '';
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}
