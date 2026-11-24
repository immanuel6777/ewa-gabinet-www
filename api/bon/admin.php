<?php
// Panel realizacji bonów – zmiany administracyjne (etap 3: 2026-11-21). Konto z rolą admin (Bartek), JSON + X-CSRF;
// akcję „rezygnacja” także konto z 'rezygnacje' => true w konfigu (Ewa, decyzja 1 z 2026-11-23).
//   POST {kod, akcja: waznosc, data: RRRR-MM-DD, powod} – ręczna zmiana daty ważności (indywidualne zgody Ewy, poza regulaminem)
//   POST {kod, akcja: zatwierdz}                       – bon wstrzymany → ważny, potem PDF mailem do kupującego
//   POST {kod, akcja: zwrot, powod}                    – oznaczenie zwrotu (same pieniądze zwraca się w Easytools);
//                                                         przy rezygnacji zamyka ją jako zwrot ręczny (krok 3 nie zleci drugiego)
//   POST {kod, akcja: rezygnacja, kanal: mail|gabinet, data: RRRR-MM-DD, otrzymano: RRRR-MM-DD, powod}
//                                                       – rezygnacja mailem / na papierze (krok 4); data = dzień wysłania lub
//                                                         złożenia oświadczenia (termin 14 dni), otrzymano = dzień, w którym
//                                                         doszło do gabinetu (blokada bonu, 14 dni na zwrot); po terminie z powodem
//   POST {kod, akcja: cofnij-rezygnacje, powod}        – rezygnacja złożona pomyłkowo albo wycofana, tylko przed zleceniem zwrotu
// Każda zmiana trafia do voucher_admin_log (kto, kiedy, co). Logika: lib/panel.php.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';
require __DIR__ . '/lib/mail.php';

[$k, $pdo, $token] = bon_http_start(['POST']);
$doMaila = null;
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    $d = bon_http_json_body($sesja);
    [$kod, $odp, $doMaila] = bon_panel_admin($k, $pdo, $sesja, $d) + [2 => null];
    if ($doMaila) $odp['mail'] = 'wysyłam PDF do kupującego';
    bon_json($kod, $odp);
    // bez „opis”: zawiera wpisany powód (wolny tekst)
    bon_zdarzenie($k, 'admin', ['http' => $kod, 'kto' => $sesja['login'], 'akcja' => (string)($d['akcja'] ?? ''),
        'kod' => bon_kod_maska((string)(bon_normalizuj_kod((string)($d['kod'] ?? ''), (string)$k['kod_prefix']) ?? ''))] + array_intersect_key($odp, array_flip(['blad'])));
} catch (Throwable $e) {
    error_log('bony/admin: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'admin'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}

if ($doMaila) {
    // po zatwierdzeniu wstrzymanego bonu: PDF i mail jak po zwykłym zakupie (po odpowiedzi, żeby panel nie czekał)
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    ignore_user_abort(true);
    @set_time_limit(120);
    try {
        bon_zdarzenie($k, 'mail', ['po' => 'zatwierdzenie'] + bon_wyslij_zamowienie($k, $pdo, $doMaila));
    } catch (Throwable $e) {
        error_log('bony/admin (wysyłka): ' . $e->getMessage());
        bon_zdarzenie($k, 'blad', ['plik' => 'admin (wysyłka)'] + bon_opis_bledu($e));
        try { bon_powiadom($k, 'Bon: błąd wysyłki PDF po zatwierdzeniu', 'Zamówienie ' . $doMaila . ': ' . $e->getMessage()); } catch (Throwable) {}
    }
}
