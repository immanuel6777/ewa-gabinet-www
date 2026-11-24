<?php
// Panel realizacji bonów – wyszukiwanie (krok 4: 2026-11-23). Tylko po zalogowaniu (każda rola), JSON + X-CSRF.
//   POST {q} – numer bonu (→ {typ: kod, kod}), e-mail kupującego albo id zamówienia Easytools (→ {typ, bony: [...], wiecej}).
// POST, nie GET: e-mail kupującego nie trafia do logów serwera ani historii przeglądarki. Logika: lib/panel.php.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';

[$k, $pdo, $token] = bon_http_start(['POST']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    $d = bon_http_json_body($sesja);
    [$kod, $odp] = bon_panel_szukaj($k, $pdo, (string)($d['q'] ?? ''), null, $sesja); // P-10: poza adminem numery zamaskowane
    bon_json($kod, $odp);
    // bez wpisanego tekstu (e-mail = dane osobowe): tylko rodzaj wyszukiwania i liczba wyników
    bon_zdarzenie($k, 'szukaj', ['http' => $kod, 'kto' => $sesja['login'], 'typ' => (string)($odp['typ'] ?? ''), 'wynikow' => count($odp['bony'] ?? [])]);
} catch (Throwable $e) {
    error_log('bony/szukaj: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'szukaj'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
