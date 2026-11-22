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
const BON_TABELE = ['vouchers', 'redemptions', 'voucher_verify_limit', 'voucher_sessions', 'voucher_login_attempts', 'voucher_admin_log'];
const BON_TABELE_ODWROTNIE = ['redemptions', 'voucher_verify_limit', 'voucher_sessions', 'voucher_login_attempts', 'voucher_admin_log', 'vouchers'];
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
// ważność bonu: regulamin i /bony mówią 6 mies.; konfig z etapu 1 miał 12 (review 2026-11-22)
$wiersz('  waznosc_miesiecy=' . (int)$k['waznosc_miesiecy'] . ((int)$k['waznosc_miesiecy'] === 6 ? ' (zgodnie z regulaminem)' : ' → UWAGA: regulamin mówi 6 – popraw konfig/bony.php'));
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
if ($brak) {
    $wiersz('Brak tabel: ' . implode(', ', $brak) . ' → wgraj plan/bony.sql w phpMyAdmin i odśwież.');
    exit;
}
$wiersz('Tabele ' . implode(', ', BON_TABELE) . ': są');

$kTest = array_replace($k, ['tabele_przedrostek' => 'test_', 'mail_powiadomienie' => []]);
foreach (BON_TABELE_ODWROTNIE as $t) {
    $pdo->exec("DROP TABLE IF EXISTS test_$t");
    $pdo->exec("CREATE TABLE test_$t LIKE $t");
}
$wiersz('Utworzono kopie test_* tabel: ' . implode(', ', BON_TABELE));

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
$wiersz('Po teście usuń test_token z konfig/bony.php.');
