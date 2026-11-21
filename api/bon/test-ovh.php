<?php
// Test backendu bonów NA OVH (MySQL), na osobnych tabelach test_* (etap 1: 2026-11-21).
// Otwórz: https://www.ewa-gabinet.pl/api/bon/test-ovh.php?token=<test_token z konfig/bony.php>
// Bez test_token w konfigu (albo krótszego niż 24 znaki) zwraca 404 – po teście usuń test_token z konfigu.
//
// Co robi: wypisuje wersję PHP i rozszerzenia (GD do PDF-a w etapie 2), tworzy test_vouchers / test_redemptions /
// test_voucher_verify_limit jako kopie prawdziwych tabel (CREATE TABLE … LIKE – wymaga wgranego plan/bony.sql),
// przepuszcza te same scenariusze co testy lokalne (lib/testy.php) i usuwa tabele test_* (?zostaw=1 = zostaw do podglądu).
// Prawdziwych tabel bonów ani zgloszenia_staz nie zmienia. Nie wysyła maili.

declare(strict_types=1);
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
foreach (['vouchers', 'redemptions', 'voucher_verify_limit'] as $t) {
    try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); } catch (Throwable $e) { $brak[] = $t; }
}
if ($brak) {
    $wiersz('Brak tabel: ' . implode(', ', $brak) . ' → wgraj plan/bony.sql w phpMyAdmin i odśwież.');
    exit;
}
$wiersz('Tabele vouchers, redemptions, voucher_verify_limit: są');

$kTest = array_replace($k, ['tabele_przedrostek' => 'test_', 'mail_powiadomienie' => []]);
foreach (['redemptions', 'voucher_verify_limit', 'vouchers'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS test_$t");
    $pdo->exec("CREATE TABLE test_$t LIKE $t");
}
$wiersz('Utworzono test_vouchers, test_redemptions, test_voucher_verify_limit');

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
    foreach (['redemptions', 'voucher_verify_limit', 'vouchers'] as $t) $pdo->exec("DROP TABLE IF EXISTS test_$t");
    $wiersz('Tabele test_* usunięte.');
} else {
    $wiersz('Tabele test_* zostawione (?zostaw=1) – usuń je ręcznie albo uruchom test ponownie bez zostaw.');
}
$wiersz('Po teście usuń test_token z konfig/bony.php.');
