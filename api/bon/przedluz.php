<?php
// Panel realizacji bonów – przedłużenie ważności o 3 miesiące (krok 4: 2026-11-23). Każda rola panelu, JSON + X-CSRF.
//   POST {kod} → {opis, wazny_do}. Raz na bon, w oknie od 30 dni przed końcem ważności do 30 dni po nim (regulamin § 14).
// Reguły w bon_przedluz (lib/bon.php) – tę samą funkcję użyje /bon w kroku 5. Dowolna data: admin.php (akcja waznosc).

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';

[$k, $pdo, $token] = bon_http_start(['POST']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    $d = bon_http_json_body($sesja);
    [$kod, $odp] = bon_panel_przedluz($k, $pdo, $sesja, (string)($d['kod'] ?? ''));
    bon_json($kod, $odp);
    bon_zdarzenie($k, 'przedluzenie', ['http' => $kod, 'kto' => $sesja['login'],
        'kod' => bon_kod_maska((string)(bon_normalizuj_kod((string)($d['kod'] ?? ''), (string)$k['kod_prefix']) ?? ''))]
        + array_intersect_key($odp, array_flip(['wazny_do', 'blad'])));
} catch (Throwable $e) {
    error_log('bony/przedluz: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'przedluz'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
