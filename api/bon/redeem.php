<?php
// Panel realizacji bonów – realizacja (etap 3: 2026-11-21). Tylko po zalogowaniu, JSON + nagłówek X-CSRF.
//   POST {kod, pozycje: [{id, cena?}], kwota_reczna, notatka, client_token}
//   → {do_kasy, zdjeto, doplata, saldo_przed, saldo, status}
// Transakcja + blokada wiersza, client_token UNIQUE (podwójne kliknięcie nie odejmuje 2×), saldo nigdy < 0.
// Panel NIE wystawia paragonu – „do_kasy” to kwota do nabicia na kasę fiskalną w gabinecie. Logika: lib/panel.php.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';

[$k, $pdo, $token] = bon_http_start(['POST']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    $d = bon_http_json_body($sesja);
    [$kod, $odp] = bon_panel_realizuj($k, $pdo, $sesja, $d);
    bon_json($kod, $odp);
    bon_zdarzenie($k, $kod === 200 ? 'realizacja' : 'realizacja_odmowa', ['http' => $kod, 'kto' => $sesja['login'], 'kod' => bon_kod_maska((string)($odp['kod'] ?? ''))]
        + array_intersect_key($odp, array_flip(['zdjeto', 'doplata', 'saldo', 'status', 'powtorzona', 'blad'])));
} catch (Throwable $e) {
    error_log('bony/redeem: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'redeem'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera – sprawdź saldo bonu, zanim spróbujesz ponownie.']);
}
