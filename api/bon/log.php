<?php
// Podgląd diagnostyczny backendu bonów, TYLKO ODCZYT (2026-11-22): GET /api/bon/log.php?n=10&dni=2&bony=20&z=40
// (n = surowe webhooki, z = zdarzenia z dziennika, dni = ile ostatnich dni plików, bony = ostatnie bony; siec=1 = test połączenia z Mailjetem)
// Token w NAGŁÓWKU `X-Log-Token` (nie w adresie – adresy trafiają do logów serwera), wartość = `log_token` z konfig/bony.php
// (min. 32 znaki, losowe). Bez tokenu, ze złym albo za krótkim: 404 jak dla nieistniejącej strony.
// Odpowiedź nie zawiera danych osobowych ani sekretów (lib/diagnostyka.php). Wyłączenie = usunięcie `log_token` z konfigu.

declare(strict_types=1);
require __DIR__ . '/lib/diagnostyka.php';
require_once __DIR__ . '/lib/mail.php'; // status nadawcy w Mailjet (bon_mailjet_nadawca, 2026-11-24)

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
    require_once __DIR__ . '/lib/diagnostyka-sieci.php';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(bon_diagnostyka_sieci(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ?adres=1 (REVIEW nr 14): co serwer widzi jako adres WYWOŁUJĄCEGO (osoby z tokenem, nie klientów) – REMOTE_ADDR i nagłówki
// pośrednika. Zapytanie z dwóch sieci: ten sam REMOTE_ADDR przy różnych publicznych IP = pośrednik, limity liczone wspólnie.
if (($_GET['adres'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $naglowki = [];
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_X_CLIENT_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_PROTO'] as $n) {
        if (isset($_SERVER[$n])) $naglowki[$n] = mb_substr((string)$_SERVER[$n], 0, 200);
    }
    echo json_encode(['remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'naglowki_posrednika' => $naglowki],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$liczba = static fn(string $nazwa, int $domyslnie, int $max): int => max(1, min($max, (int)($_GET[$nazwa] ?? $domyslnie)));
header('Content-Type: application/json; charset=utf-8');
echo json_encode(bon_diagnostyka($k, $liczba('n', 10, 50), $liczba('dni', 2, 14), $liczba('bony', 20, 100), $liczba('z', 40, 200)),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
