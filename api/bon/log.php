<?php
// Podgląd diagnostyczny backendu bonów, TYLKO ODCZYT (2026-11-22): GET /api/bon/log.php?n=10&dni=2&bony=20&z=40
// (n = surowe webhooki, z = zdarzenia z dziennika, dni = ile ostatnich dni plików, bony = ostatnie bony; siec=1 = test połączenia z Mailjetem)
// Token w NAGŁÓWKU `X-Log-Token` (nie w adresie – adresy trafiają do logów serwera), wartość = `log_token` z konfig/bony.php
// (min. 32 znaki, losowe). Bez tokenu, ze złym albo za krótkim: 404 jak dla nieistniejącej strony.
// Odpowiedź nie zawiera danych osobowych ani sekretów (lib/diagnostyka.php). Wyłączenie = usunięcie `log_token` z konfigu.

declare(strict_types=1);
require __DIR__ . '/lib/diagnostyka.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$nie = static function (): never { http_response_code(404); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') $nie();
try {
    $k = bon_konfig();
} catch (Throwable) {
    $nie();
}
$token = (string)($k['log_token'] ?? '');
if (strlen($token) < 32 || !hash_equals($token, (string)($_SERVER['HTTP_X_LOG_TOKEN'] ?? ''))) {
    usleep(300000); // zgadywanie tokenu nie jest szybsze od kilku prób na sekundę
    $nie();
}

// ?siec=1: test połączenia z Mailjetem z tego serwera (lib/diagnostyka-sieci.php), trwa do ok. 2 min
if (($_GET['siec'] ?? '') === '1') {
    require __DIR__ . '/lib/diagnostyka-sieci.php';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(bon_diagnostyka_sieci(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$liczba = static fn(string $nazwa, int $domyslnie, int $max): int => max(1, min($max, (int)($_GET[$nazwa] ?? $domyslnie)));
header('Content-Type: application/json; charset=utf-8');
echo json_encode(bon_diagnostyka($k, $liczba('n', 10, 50), $liczba('dni', 2, 14), $liczba('bony', 20, 100), $liczba('z', 40, 200)),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
