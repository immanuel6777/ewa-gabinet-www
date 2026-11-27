<?php
// Raport dla Ewy i Bartka (wpisy czatu 2026-11-26 01:49 i 01:51). Tylko po zalogowaniu, poziom Gabinet i admin (Bon → 403).
//   GET /api/bon/raport.php?okres=dzis|tydzien|miesiac|rok  albo  ?od=RRRR-MM-DD&do=RRRR-MM-DD  (brak = bieżący miesiąc)
//   → {ok, okres, od, do, zrealizowano: "1234.50", realizacji: 3,
//      lista: [{id, kod, nazwa, kolor, cofnieta, kiedy, zdjeto, doplata, typ, pozycje, notatka, kto}], lista_wszystkich, lista_obcieta,
//      saldo: {pozostalo: "0.00", bonow, wstrzymane: "0.00", wstrzymanych, na_dzien: RRRR-MM-DD}}
//   (karta #210, Backend 2 26–27.11: zrealizowano i realizacji bez cofniętych, lista z cofniętymi; saldo = stan na koniec dnia „do”,
//   dla przyszłego „do” – na dziś, decyzja Bartka 26.11 „na koniec okresu”). Logika: lib/raport.php.
//   Cofnięta realizacja nie istniała (korekta, OK Backendu 27.11): znika z zrealizowano i nie odejmuje się od salda, także gdy
//   cofnięto ją po końcu okresu – cofnąć można tylko do 24 h (BON_COFNIECIE_GODZIN), więc raport jest ostateczny po 24 h od końca okresu.
//   Rezygnacja liczy się od chwili zarejestrowania w systemie (wpis w historii bonu), nie od daty wysłania przez klienta.

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
