<?php
// Panel realizacji bonów – sesja (etap 3: 2026-11-21). Woła ją strona /panel-bonow.
//   GET    → {zalogowany, login, imie, rola, csrf}  (csrf idzie potem w nagłówku X-CSRF przy każdym POST)
//   POST   {login, haslo, zapamietaj} → logowanie (limit prób), ciasteczko bon_panel (HttpOnly, Secure, SameSite=Lax)
//   DELETE → wylogowanie (wymaga X-CSRF)
// Logika: lib/panel.php.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';

[$k, $pdo, $token] = bon_http_start(['GET', 'POST', 'DELETE']);
$metoda = $_SERVER['REQUEST_METHOD'];

try {
    if ($metoda === 'GET') {
        $s = bon_panel_sesja($k, $pdo, $token);
        bon_json(200, $s ? ['ok' => true, 'zalogowany' => true] + $s : ['ok' => true, 'zalogowany' => false]);
    } elseif ($metoda === 'POST') {
        $d = bon_http_json_body(null, false);
        [$kod, $odp, $ciastko] = bon_panel_zaloguj($k, $pdo, (string)($d['login'] ?? ''), (string)($d['haslo'] ?? ''), !empty($d['zapamietaj']),
            (string)($_SERVER['REMOTE_ADDR'] ?? '')) + [2 => null];
        if ($ciastko) {
            bon_panel_wyloguj($k, $pdo, $token); // stara sesja z tej przeglądarki przestaje działać
            bon_http_ciastko($ciastko['wartosc'], $ciastko['wygasa']);
        }
        bon_json($kod, $odp);
    } else {
        $s = bon_panel_sesja($k, $pdo, $token);
        if ($s) bon_http_json_body($s); // CSRF
        bon_panel_wyloguj($k, $pdo, $token);
        bon_http_ciastko('', time() - 3600);
        bon_json(200, ['ok' => true, 'zalogowany' => false]);
    }
} catch (Throwable $e) {
    error_log('bony/sesja: ' . $e->getMessage());
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
