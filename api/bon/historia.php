<?php
// Panel realizacji bonów – bon z historią (etap 3: 2026-11-21). Tylko po zalogowaniu.
//   GET /api/bon/historia.php?code=EWA-XXXX-XXXX → saldo, ważność, status, „Dla”, historia realizacji
//   (admin dodatkowo: powód wstrzymania, zamówienie, zapłacono, dziennik zmian). Logika: lib/panel.php.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';

[$k, $pdo, $token] = bon_http_start(['GET']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    [$kod, $odp] = bon_panel_historia($k, $pdo, $sesja, (string)($_GET['code'] ?? $_GET['kod'] ?? ''));
    bon_json($kod, $odp);
} catch (Throwable $e) {
    error_log('bony/historia: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'historia'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
