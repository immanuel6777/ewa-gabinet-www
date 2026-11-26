<?php
// Raport dla Ewy i Bartka (wpisy czatu 2026-11-26 01:49 i 01:51). Tylko po zalogowaniu, poziom Gabinet i admin (Bon → 403).
//   GET /api/bon/raport.php?okres=dzis|tydzien|miesiac|rok  albo  ?od=RRRR-MM-DD&do=RRRR-MM-DD  (brak = bieżący miesiąc)
//   → {ok, okres, od, do, zrealizowano: "1234.50", realizacji: 3}. Logika: lib/raport.php.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';
require __DIR__ . '/lib/raport.php';

[$k, $pdo, $token] = bon_http_start(['GET']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    [$kod, $odp] = bon_raport($k, $pdo, $sesja, $_GET);
    bon_json($kod, $odp);
} catch (Throwable $e) {
    error_log('bony/raport: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'raport'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
