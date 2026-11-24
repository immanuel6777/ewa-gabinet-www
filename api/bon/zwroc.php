<?php
// Panel bonów – P-11 (2026-11-24): „Zwróć pieniądze” przez API Easytools. Admin i konto z uprawnieniem rezygnacji (Ewa).
//   POST {kod, sprawdzone?} (sesja + X-CSRF) → 200 {ok, kwota} | 409 {juz_zwrocony: true} | 409/502 {blad}
// Logika: lib/zwroty.php → bon_panel_zwroc (stan „zlecony” w bazie przed wywołaniem API; potwierdzenie w cronie).

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';
require __DIR__ . '/lib/zwroty.php';

[$k, $pdo, $token] = bon_http_start(['POST']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    $d = bon_http_json_body($sesja);
    @set_time_limit(60);
    [$kod, $odp] = bon_panel_zwroc($k, $pdo, $sesja, $d);
    bon_json($kod, $odp);
    $kodBonu = bon_normalizuj_kod((string)($d['kod'] ?? ''), (string)$k['kod_prefix']);
    bon_zdarzenie($k, 'zwrot-panel', ['http' => $kod, 'kto' => $sesja['login'], 'kod' => $kodBonu ? bon_kod_maska($kodBonu) : null,
        'kwota' => $odp['kwota'] ?? null, 'juz_zwrocony' => $odp['juz_zwrocony'] ?? null, 'blad' => $odp['blad'] ?? null]);
} catch (Throwable $e) {
    error_log('bony/zwroc: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'zwroc'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Sprawdź w Easytools, czy zwrot poszedł, zanim spróbujesz ponownie.']);
}
