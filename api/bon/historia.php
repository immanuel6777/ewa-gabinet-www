<?php
// Panel realizacji bonów – bon z historią (etap 3: 2026-11-21). Tylko po zalogowaniu.
//   GET /api/bon/historia.php?code=EWA-XXXX-XXXX → saldo, ważność, status, „Dla”, historia realizacji
//   + historia zdarzeń bonu, złożona rezygnacja, możliwość przedłużenia (krok 4, każda rola; szczegóły zdarzeń tylko admin)
//   (admin dodatkowo: powód wstrzymania, zamówienie, zapłacono, termin rezygnacji). Logika: lib/panel.php.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';
require __DIR__ . '/lib/zwroty.php'; // P-11: linia „Płatność w Easytools” dla Bartka i Ewy
require __DIR__ . '/lib/rezygnacja.php'; // wpis 17:35: stan potwierdzenia rezygnacji przy bonie

[$k, $pdo, $token] = bon_http_start(['GET']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    // P-10: ?uchwyt=… (bon z wyników wyszukiwania po e-mailu / zamówieniu) – numer zamaskowany do potwierdzenia (poza adminem)
    [$kod, $odp] = isset($_GET['uchwyt'])
        ? bon_panel_historia_uchwyt($k, $pdo, $sesja, (string)$_GET['uchwyt'])
        : bon_panel_historia($k, $pdo, $sesja, (string)($_GET['code'] ?? $_GET['kod'] ?? ''));
    bon_json($kod, $odp);
} catch (Throwable $e) {
    error_log('bony/historia: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'historia'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
