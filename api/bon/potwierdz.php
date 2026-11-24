<?php
// Panel bonów – P-10 (2026-11-24): potwierdzenie numeru bonu znalezionego po e-mailu kupującego albo numerze zamówienia.
//   POST {uchwyt, koncowka} (sesja + X-CSRF) → 200 {ok, kod} | 403 „To nie ten numer…” | 429 limit (5 prób na bon na godzinę)
// Po sukcesie wpis „Numer potwierdzony przez pacjenta” w historii bonu (kto). Każda próba w dzienniku (bez numeru i wpisanych znaków).
// Logika: lib/panel.php → bon_panel_potwierdz_numer.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';

[$k, $pdo, $token] = bon_http_start(['POST']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    $d = bon_http_json_body($sesja);
    [$kod, $odp] = bon_panel_potwierdz_numer($k, $pdo, $sesja, (string)($d['uchwyt'] ?? ''), (string)($d['koncowka'] ?? ''));
    bon_json($kod, $odp);
    bon_zdarzenie($k, 'numer-potwierdzenie', ['http' => $kod, 'kto' => $sesja['login'], 'kod' => $kod === 200 ? bon_kod_maska((string)$odp['kod']) : null]);
} catch (Throwable $e) {
    error_log('bony/potwierdz: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'potwierdz'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
