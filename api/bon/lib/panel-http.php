<?php
// Wspólna warstwa HTTP panelu realizacji (etap 3: 2026-11-21): konfiguracja, baza, ciasteczko sesji, JSON, CSRF, Origin.
// Używają jej sesja.php, historia.php, redeem.php i admin.php. Logika w lib/panel.php.

declare(strict_types=1);
require_once __DIR__ . '/panel.php';

/** Start żądania panelu: [konfig, PDO, token z ciasteczka]. Przy braku konfigu/bazy kończy 500 (JSON). */
function bon_http_start(array $metody): array {
    $metoda = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($metoda, $metody, true)) {
        header('Allow: ' . implode(', ', $metody));
        bon_json(405, ['ok' => false, 'blad' => 'zła metoda']);
        exit;
    }
    try {
        $k = bon_panel_konfig(bon_konfig());
        $pdo = bon_pdo($k);
    } catch (Throwable $e) {
        error_log('bony/panel: ' . $e->getMessage());
        bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
        exit;
    }
    return [$k, $pdo, (string)($_COOKIE[BON_PANEL_CIASTKO] ?? '')];
}

function bon_http_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function bon_http_ciastko(string $wartosc, int $wygasa): void {
    setcookie(BON_PANEL_CIASTKO, $wartosc, [
        'expires' => $wygasa,
        'path' => '/api/bon/',       // czyta je tylko API panelu
        'secure' => bon_http_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * POST: tylko JSON, z tej samej domeny (Origin, gdy jest) i – poza logowaniem – z nagłówkiem X-CSRF zgodnym z sesją.
 * Zwraca zdekodowane body. Przy błędzie kończy odpowiedzią.
 */
function bon_http_json_body(?array $sesja, bool $wymagajCsrf = true): array {
    $typ = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_starts_with($typ, 'application/json')) { bon_json(415, ['ok' => false, 'blad' => 'oczekiwano JSON']); exit; }
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && strtolower((string)parse_url($origin, PHP_URL_HOST)) !== strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0])) {
        bon_json(403, ['ok' => false, 'blad' => 'zła domena']);
        exit;
    }
    if ($wymagajCsrf && (!$sesja || !hash_equals($sesja['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? '')))) {
        bon_json(403, ['ok' => false, 'blad' => 'Sesja wygasła – odśwież stronę.']);
        exit;
    }
    $d = json_decode((string)file_get_contents('php://input', false, null, 0, 64 * 1024), true);
    if (!is_array($d)) { bon_json(400, ['ok' => false, 'blad' => 'zły JSON']); exit; }
    return $d;
}

function bon_http_wymagaj_sesji(array $k, PDO $pdo, string $token): array {
    $sesja = bon_panel_sesja($k, $pdo, $token);
    if (!$sesja) { bon_json(401, ['ok' => false, 'zalogowany' => false, 'blad' => 'Zaloguj się.']); exit; }
    return $sesja;
}
