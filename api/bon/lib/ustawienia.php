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
    'sync_od' => '2026-11-23 10:00:00',  // zamówienia starsze są pomijane; przed startem: chwila startu (PLAN-START, krok 16a)
    'sync_dni' => 7,
    'sync_odstep_s' => 20,

    // --- webhook (zostaje 'log', bony tworzy synchronizacja; zob. plan/konfig-bony.przyklad.php) ---
    'webhook_tryb' => 'log',
    'kwota_jednostka' => 'pln',

    // --- warianty i ceny → nominał; nieznany = bon wstrzymany ---
    'warianty' => [
        'Bon 200 zł' => 200, 'Bon 300 zł' => 300, 'Bon 500 zł' => 500, 'Dowolna kwota (od 180 zł)' => 'dowolna',
        'Bon Szmaragdowy' => 200, 'Bon Rubinowy' => 300, 'Bon Bursztynowy' => 500, 'Bon Szafirowy' => 'dowolna',
    ],
    'nazwy_bonow' => [200 => 'Bon Szmaragdowy', 300 => 'Bon Rubinowy', 500 => 'Bon Bursztynowy', 'dowolna' => 'Bon Szafirowy'],
    'ceny' => [
        'bon-200' => 200, 'bon-300' => 300, 'bon-500' => 500, 'bon-dowolna' => 'dowolna',
        'price_1UeMn8DJkxNsH4K3wXFIBCh4' => 200, 'price_1UeHCtDJkxNsH4K38JGuXhbj' => 300,
        'price_1UeMn9DJkxNsH4K38LqAGPRG' => 500, 'price_1UeMn8DJkxNsH4K3Y4D7uIaE' => 'dowolna',
    ],
    'prog_wstrzymania' => 0,             // DO CZWARTKU 0 (testy z kodem rabatowym); na start 0.8 – osobnym PR
    'min_dowolna_pln' => 180,

    // --- bon ---
    'waznosc_miesiecy' => 6,
    'kod_prefix' => 'EWA',
    'verify_limit_na_minute' => 20,
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
    'telefon_pomoc_bony_godziny' => 'pon.–pt. 9–17',
    // wpis 17:47: zdanie „Rezygnacja w ciągu 14 dni od zapłaty: najszybciej na stronie ewa-gabinet.pl/bon…” w mailu z bonem.
    // true dopiero na słowo Bartka, w tej samej wrzucie co włączenie nowego § 14 (funkcjeBonu.rezygnacja frontu).
    'mail_zdanie_rezygnacja' => false,

    // --- zapas SMTP OVH (hasło skrzynki tylko na serwerze: mail_smtp_haslo) ---
    'mail_zapas' => true,
    'mail_smtp_host' => 'ssl0.ovh.net',
    'mail_smtp_port' => 465,
    'mail_smtp_user' => 'bony@ewa-gabinet.pl', // login = skrzynka nadawcy (OVH wymaga tej samej koperty); hasło bony@ w konfigu na serwerze
];
