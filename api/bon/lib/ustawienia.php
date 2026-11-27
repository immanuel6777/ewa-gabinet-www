<?php
// Ustawienia bonów z repo (decyzja Bartka 2026-11-24, wpis 11:30): wszystko, co NIE jest sekretem. Wdrażane z kodem do lib/
// (zablokowany dla przeglądarki w .htaccess produkcji). Zmiana = PR + wrzuta, bez edycji pliku na serwerze.
// UWAGA: ten plik trafia też do publicznego repo ewa-gabinet-www – tylko wartości jawne (adresy firmowe, telefon z strony, UUID
// produktu widoczny w kasie). Sekrety i konta zostają w konfig/bony.php na serwerze.
// Kolejność łączenia (lib/bon.php → bon_konfig): domyślne w kodzie ← ten plik ← konfig/bony.php na serwerze (serwer wygrywa).
// log.php → „ustawienia” pokazuje, skąd jest każda wartość, i ostrzega, gdy serwer nadpisuje ten plik inną wartością.

return [
    // --- Easytools ---
    'easytools_produkt_uuid' => 'e12be4ae-2d3a-47f3-b220-437fc5b46277', // „Bon podarunkowy na zabiegi podologiczne”
    'easytools_tryb' => 'TEST',          // informacyjnie: TEST / PROD

    // --- synchronizacja zamówień z API (bony bez webhooka) ---
    'sync_tryb' => 'bon',                // 'wylaczony' | 'raport' | 'bon'
    'sync_od' => '2026-11-27 04:00:00',  // START SPRZEDAŻY (Bartek 27.11 03:05: „start 04:00”, czas Warszawy; wcześniej plan 8:00) – zamówienia starsze (testy) są pomijane (PLAN-START, krok 16a)
    'sync_dni' => 7,
    'sync_odstep_s' => 20,

    // --- webhook (2026-11-25: 'dzwonek' – prawdziwy zakup 25.11 00:29 przyszedł z podpis_ok = true, order_uuid = id zamówienia
    //     z API; podpisany zakup od razu uruchamia synchronizację z API, bon z danych API (decyzja Bartka 24.11 23:52);
    //     'log' = tylko zapis, wycofanie bez zmiany kodu) ---
    'webhook_tryb' => 'dzwonek',

    // --- #218 (27.11): cron odbiera dostęp do produktu w Easytools, gdy wszystkie bony zamówienia są zwrócone (lib/zwroty.php →
    //     bon_zwroty_dostep); false = wyłączone bez zmiany kodu ---
    'easytools_odbieraj_dostep' => true,

    // --- #259 (27.11): strażnik sprzedaży (api/bon/straznik.php, workflow GitHuba co godzinę 7–22) – false = wyłączony (odpowiada { wlaczony: false }, bez alarmu) ---
    'straznik' => true,

    // --- konta panelu (wpis 13:20): false = konta z panel_konta w konfigu; true = z tabeli voucher_accounts (po migracji konta.sql
    //     i przeniesieniu kont – plan/BONY-KONTA-WDROZENIE.md). Włączenie po starcie, na słowo Bartka; wycofanie = false + wrzuta ---
    'konta_z_bazy' => true, // WŁĄCZONE 25.11 na słowo Bartka – po migracji konta.sql i przeniesieniu kont (log.php: voucher_accounts > 0)
    'kwota_jednostka' => 'pln',

    // --- warianty i ceny → nominał; nieznany = bon wstrzymany ---
    // synchronizacja z API Easytools (lib/easytools.php, 2026-11-23): nazwa wariantu z API → nominał. API przy pozycji zamówienia
    // NIE podaje price_id (sprawdzone 23.11 i 25.11 na zamówieniu), więc rozstrzyga nazwa; nazwa, kolor i ważność bonu i tak liczą się
    // z WARTOŚCI (mapa „kamienie”, wpis 21:47). Wpis „warianty” w konfigu ZASTĘPUJE tę mapę w całości (bez scalania).
    // Wpis czatu 22:39: nowe nazwy LIVE w Easytools od 25.11 22:39 – zamówienie utworzone przed „stare_przed” → „warianty_stare”,
    // od „nowe_od” → „warianty”, pomiędzy → bon wstrzymany. Kwota zapłacona NIE rozstrzyga (rabat: Szafirowy 300 zł za 270 zł = 300 zł).
    'warianty' => [
        'Bon 200 zł' => 200, 'Bon 300 zł' => 300, 'Bon 500 zł' => 500, 'Dowolna kwota (od 180 zł)' => 'dowolna', 'Dowolna kwota' => 'dowolna',
        'Twoja kwota' => 'dowolna', // Bartek 2026-11-26 17:59: nowa nazwa wariantu dowolnej kwoty w Easytools (stare zostają dla zamówień sprzed zmiany)
        'Bon Topazowy' => 200, 'Bon Szafirowy' => 300, 'Bon Szmaragdowy' => 500,
    ],
    // stare nazwy (live do 25.11 22:39) – tylko zapas: wszystkie zamówienia sprzed publikacji to testy do wyczyszczenia przed startem
    'warianty_stare' => [
        'Bon 200 zł' => 200, 'Bon 300 zł' => 300, 'Bon 500 zł' => 500, 'Dowolna kwota (od 180 zł)' => 'dowolna',
        'Bon Szmaragdowy' => 200, 'Bon Rubinowy' => 300, 'Bon Szafirowy' => 'dowolna', // stara nazwa 500 zł usunięta (Bartek 25.11) – taki bon czeka na sprawdzenie
    ],
    'warianty_zmiana' => ['stare_przed' => '2026-11-25 22:38:00', 'nowe_od' => '2026-11-25 22:40:00'], // czas polski
    // kamień bonu (wpis 21:47): nazwa, kolor i ważność z WARTOŚCI POCZĄTKOWEJ (nie z wariantu, nie z kwoty po rabacie).
    // Progi = górne granice włącznie, rosnąco; ostatni bez granicy. Kolejne „staty” bonu = nowe pola tutaj, bez zmiany kodu.
    'kamienie' => [
        ['do_pln' => 200, 'nazwa' => 'Bon Topazowy', 'kolor' => 'zloty', 'waznosc_miesiecy' => 6],
        ['do_pln' => 300, 'nazwa' => 'Bon Szafirowy', 'kolor' => 'granat', 'waznosc_miesiecy' => 6],
        ['do_pln' => 500, 'nazwa' => 'Bon Szmaragdowy', 'kolor' => 'zielen', 'waznosc_miesiecy' => 12],
        ['do_pln' => null, 'nazwa' => 'Bon Rubinowy', 'kolor' => 'czerwony', 'waznosc_miesiecy' => 12],
    ],
    'ceny' => [
        'bon-200' => 200, 'bon-300' => 300, 'bon-500' => 500, 'bon-dowolna' => 'dowolna',
        'price_1UeMn8DJkxNsH4K3wXFIBCh4' => 200, 'price_1UeHCtDJkxNsH4K38JGuXhbj' => 300,
        'price_1UeMn9DJkxNsH4K38LqAGPRG' => 500, 'price_1UeMn8DJkxNsH4K3Y4D7uIaE' => 'dowolna',
    ],
    'prog_wstrzymania' => 0.8,           // START (PR „start”, 27.11): zapłacono < 80% nominału → bon wstrzymany (kody rabatowe tylko na 200/300/500)
    'min_dowolna_pln' => 180,

    // --- bon (ważność: „kamienie” wyżej) ---
    'kod_prefix' => 'EWA',
    'verify_limit_na_minute' => 20,
    'adres_limit_na_minute' => 30,       // strona podziękowania: pytań o stan bonu z jednego IP na minutę (Bartek 26.11: 10 → 30 – wspólny IP sieci/operatora)
    'bon_adres_pelny_min' => 60,         // /bony/dziekujemy: pełny adres do tylu minut od zapłaty, potem zamaskowany

    // --- maile ---
    // 2026-11-24 (wpisy 11:45–12:10, POCZTA-ADRESY.md): bon wychodzi z bony@, odpowiedzi na bony@, alerty gabinetu na alerty@.
    // Nadawca musi być zatwierdzony w Mailjet (konto główne) – inaczej Mailjet odrzuci mail, a zapas SMTP go nie uratuje.
    'mail_nadawca' => 'EWA Gabinet podologiczny <bony@ewa-gabinet.pl>', // nazwa nadawcy: Bartek 2026-11-24
    'mail_odpowiedz' => 'bony@ewa-gabinet.pl',
    'mail_powiadomienie' => ['alerty@ewa-gabinet.pl'],
    // sprawy klientów (rezygnacja ze strony /bon, P-12): Reply-To = kupujący, link do panelu; awarie dalej na alerty@
    'mail_obsluga' => ['bony@ewa-gabinet.pl'],
    'telefon_pomoc_bony' => '506 102 340',
    'pomoc_imie' => 'Bartek',             // wpis 15:12: stopka panelu po zalogowaniu – „Pomoc: Bartek, 506 102 340”
    'telefon_pomoc_bony_godziny' => 'pon.–pt. 9–17',
    // wpis 17:47: zdanie „Rezygnacja w ciągu 14 dni od zapłaty: najszybciej na stronie ewa-gabinet.pl/bon…” w mailu z bonem.
    // włączone 2026-11-24 razem z nowym § 14 (funkcjeBonu.rezygnacja frontu, PR #84) – Ewa zaakceptowała, wpis doradcy 18:35.
    'mail_zdanie_rezygnacja' => true,
    // wpis 22:47: HTML maila z bonem z szablonu frontu (api/bon/lib/mail-bon.tpl, #101; obrazy img/bon-mail/ na produkcji = 200).
    // false = dotychczasowy prosty HTML. Włączenie (PR + wrzuta) dopiero po teście „Wyślij ponownie” za zgodą Bartka.
    'mail_szablon_html' => true, // włączone 2026-11-24 (Bartek: „włącz i wyślij”)

    // --- zapas SMTP OVH (hasło skrzynki tylko na serwerze: mail_smtp_haslo) ---
    'mail_zapas' => true,
    'mail_smtp_host' => 'ssl0.ovh.net',
    'mail_smtp_port' => 465,
    'mail_smtp_user' => 'bony@ewa-gabinet.pl', // login = skrzynka nadawcy (OVH wymaga tej samej koperty); hasło bony@ w konfigu na serwerze
];
