<?php
// Panel bonów – konta (wpis czatu 2026-11-25 13:20). JSON + nagłówek X-CSRF, logika w lib/konta.php.
//   POST {akcja: 'zmien-haslo', stare, nowe}                       – każdy zalogowany (także z wymuszoną zmianą hasła)
//   POST {akcja: 'lista'}                                          – admin: konta (bez hashy) + ostatnie zmiany kont
//   POST {akcja: 'dodaj', login, imie, rola, rezygnacje}           – admin → {haslo_tymczasowe} (pokazane raz)
//   POST {akcja: 'haslo-tymczasowe' | 'rola' | 'wylacz' | 'wlacz' | 'wyloguj-wszedzie', login, …} – admin
//   POST {akcja: 'przenies'}                                       – admin: jednorazowo konta z panel_konta (konfig) do bazy
// Hasła tymczasowe tylko w odpowiedzi – nigdy w dzienniku ani w historii.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';

[$k, $pdo, $token] = bon_http_start(['POST']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token, true);
    $d = bon_http_json_body($sesja);
    $akcja = (string)($d['akcja'] ?? '');
    if (!empty($sesja['zmiana_hasla']) && $akcja !== 'zmien-haslo') {
        bon_json(403, ['ok' => false, 'zmiana_hasla' => true, 'blad' => 'Najpierw ustaw własne hasło.']);
        exit;
    }
    [$kod, $odp] = bon_panel_konta($k, $pdo, $sesja, $d, $token);
    bon_json($kod, $odp);
    if ($akcja !== 'lista') {
        bon_zdarzenie($k, 'konta', ['http' => $kod, 'kto' => $sesja['login'], 'akcja' => $akcja,
            'login' => preg_match('/^[a-z0-9._-]{2,40}$/', strtolower((string)($d['login'] ?? ''))) ? strtolower((string)$d['login']) : null]
            + array_intersect_key($odp, array_flip(['blad', 'przeniesione', 'pominiete'])));
    }
} catch (Throwable $e) {
    error_log('bony/konta: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'konta'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
