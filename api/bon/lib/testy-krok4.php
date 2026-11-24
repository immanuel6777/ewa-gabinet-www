<?php
// Testy kroku 4 (2026-11-23, panel gabinetu): wyszukiwanie po e-mailu i zamówieniu, historia zdarzeń dla każdej roli,
// rezygnacja zarejestrowana w panelu (mail / papier), przedłużenie o 3 miesiące, zwrot ręczny zamykający rezygnację.
// Woła je bon_testy() w lib/testy.php – te same na SQLite (npm run test:bony) i na OVH (test-ovh.php).

declare(strict_types=1);
require_once __DIR__ . '/panel.php';
require_once __DIR__ . '/easytools.php';

function bon_testy_krok4(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $tabB = bon_tabela($k, 'vouchers');
    $tabW = bon_tabela($k, 'voucher_withdrawals');
    $sEwa = ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'csrf' => 'x'];
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    $sEwaRez = $sEwa + ['rezygnacje' => true]; // konto z uprawnieniem do rejestracji rezygnacji (decyzja 1)
    $dzis = bon_teraz();
    $d = static fn(string $zmiana) => $dzis->modify($zmiana)->format('Y-m-d');
    $email = 'krok4-' . random_int(1000, 9999) . '@example.com';
    // bon przez webhook (jak w testach panelu); zwraca wiersz z bazy
    $nowyBon = static function (int $zl, array $nadpisz = []) use ($k, $pdo, $email): array {
        $p = bon_test_payload(array_replace(['price_custom_id' => "bon-$zl", 'amount_paid' => $zl, 'customer_email' => $email], $nadpisz));
        bon_test_webhook($k, $pdo, $p);
        return bon_test_bony($k, $pdo, $p['order_id'])[0] ?? [];
    };
    $wiersz = static function (string $kod) use ($pdo, $tabB): array {
        $st = $pdo->prepare("SELECT * FROM $tabB WHERE code = ?");
        $st->execute([$kod]);
        return $st->fetch() ?: [];
    };
    $ustaw = static function (string $kod, array $pola) use ($pdo, $tabB): void {
        foreach ($pola as $kol => $w) $pdo->prepare("UPDATE $tabB SET $kol = ? WHERE code = ?")->execute([$w, $kod]);
    };
    $rezygnacja = static function (int $id) use ($pdo, $tabW) {
        $st = $pdo->prepare("SELECT * FROM $tabW WHERE voucher_id = ?");
        $st->execute([$id]);
        return $st->fetchAll();
    };

    // --- terminy (czyste funkcje) ---
    $t('termin rezygnacji: zapłata 31.01 → ostatni dzień 14.02', bon_rezygnacja_termin(['created_at' => '2026-01-31 23:50:00']) === '2026-02-14');
    $b14 = ['created_at' => '2026-03-01 10:00:00'];
    $t('termin rezygnacji: 14. dzień w terminie, 15. już nie, przed zakupem nie',
        bon_rezygnacja_w_terminie($b14, '2026-03-15') && !bon_rezygnacja_w_terminie($b14, '2026-03-16') && !bon_rezygnacja_w_terminie($b14, '2026-02-28'));
    $okno = bon_przedluzenie_okno(['status' => 'valid', 'przedluzony_at' => null, 'balance_pln' => '50.00', 'expires_at' => '2026-11-30'],
        new DateTimeImmutable('2026-11-23', new DateTimeZone(BON_STREFA)));
    $t('przedłużenie: koniec 30.11 + 3 mies. = 28.02 (arytmetyka bon_waznosc), okno 31.10–30.12', $okno === ['od' => '2026-10-31', 'do' => '2026-12-30', 'nowa' => '2027-02-28'], json_encode($okno));

    // --- wyszukiwanie ---
    $b1 = $nowyBon(200);
    $b2 = $nowyBon(300);
    [$kod, $odp] = bon_panel_szukaj($k, $pdo, strtolower(str_replace('-', ' ', (string)$b1['code'])));
    $t('szukaj: numer bonu (małe litery, spacje) → typ kod', $kod === 200 && ($odp['typ'] ?? '') === 'kod' && ($odp['kod'] ?? '') === $b1['code']);
    [$kod, $odp] = bon_panel_szukaj($k, $pdo, '  ' . strtoupper($email) . ' ');
    $kody = array_column($odp['bony'] ?? [], 'kod');
    $t('szukaj: e-mail wielkimi literami → oba bony kupującego, najnowszy pierwszy, saldo i status', $kod === 200 && ($odp['typ'] ?? '') === 'email'
        && count($kody) === 2 && in_array($b1['code'], $kody, true) && ($odp['bony'][0]['status'] ?? '') === 'valid', json_encode($odp));
    [$kod, $odp] = bon_panel_szukaj($k, $pdo, (string)$b2['easytools_order_id']);
    $t('szukaj: id zamówienia Easytools → bon z tego zamówienia', $kod === 200 && ($odp['typ'] ?? '') === 'zamowienie' && array_column($odp['bony'] ?? [], 'kod') === [$b2['code']], json_encode($odp));
    [$kod, $odp] = bon_panel_szukaj($k, $pdo, 'nikt-' . random_int(1, 9999) . '@example.com');
    $t('szukaj: nieznany e-mail → 200, pusta lista', $kod === 200 && ($odp['bony'] ?? null) === []);
    [$kodA] = bon_panel_szukaj($k, $pdo, 'jan@');
    [$kodB] = bon_panel_szukaj($k, $pdo, "x' OR 1=1");
    $t('szukaj: niepełny e-mail i śmieci → 400', $kodA === 400 && $kodB === 400);

    // --- historia zdarzeń: każda rola, szczegóły tylko admin ---
    [, $hE] = bon_panel_historia($k, $pdo, $sEwa, (string)$b1['code']);
    [, $hA] = bon_panel_historia($k, $pdo, $sAdmin, (string)$b1['code']);
    $utwE = array_values(array_filter($hE['zdarzenia'] ?? [], static fn($z) => $z['akcja'] === 'utworzenie'))[0] ?? null;
    $utwA = array_values(array_filter($hA['zdarzenia'] ?? [], static fn($z) => $z['akcja'] === 'utworzenie'))[0] ?? null;
    $t('historia (kasa): zdarzenie „Bon utworzony” bez szczegółów (kwota zapłacona), bez zamówienia i terminu rezygnacji', $utwE && $utwE['nazwa'] === 'Bon utworzony'
        && $utwE['szczegoly'] === null && !isset($hE['zamowienie']) && !isset($hE['rezygnacja_do']) && array_key_exists('rezygnacja', $hE) && $hE['rezygnacja'] === null, json_encode($hE, JSON_UNESCAPED_UNICODE));
    $t('historia (admin): szczegóły zdarzeń, zamówienie, e-mail kupującego, termin rezygnacji = zakup + 14 dni', $utwA && is_string($utwA['szczegoly'])
        && ($hA['zamowienie'] ?? '') === $b1['easytools_order_id'] && ($hA['email'] ?? '') === $email && ($hA['rezygnacja_do'] ?? '') === $d('+14 days')
        && ($hA['rezygnacja_mozliwa'] ?? null) === true);
    [, $hR] = bon_panel_historia($k, $pdo, $sEwaRez, (string)$b1['code']);
    $t('historia (kasa z uprawnieniem do rezygnacji): formularz możliwy, termin i kwota, bez zamówienia i e-maila', ($hR['rezygnacja_mozliwa'] ?? null) === true
        && ($hR['rezygnacja_do'] ?? '') === $d('+14 days') && ($hR['rezygnacja_kwota'] ?? '') === '200.00' && !isset($hR['zamowienie']) && !isset($hR['email'])
        && !isset($hE['rezygnacja_mozliwa']), json_encode($hR, JSON_UNESCAPED_UNICODE));
    $t('uprawnienie do rezygnacji: admin tak, konto z rezygnacje => true tak, zwykła kasa nie', bon_panel_moze_rezygnacje(['rola' => 'admin'])
        && bon_panel_moze_rezygnacje(['rola' => 'realizacja', 'rezygnacje' => true]) && !bon_panel_moze_rezygnacje(['rola' => 'realizacja'])
        && !bon_panel_moze_rezygnacje(['rola' => 'realizacja', 'rezygnacje' => 'tak']));

    // --- przedłużenie o 3 miesiące (wpis 18:34: tylko admin i konto z uprawnieniem rezygnacji – Ewa; kasa 403 i sama informacja) ---
    $sEwaP = $sEwa + ['rezygnacje' => true];
    [$kodK, $odpK] = bon_panel_przedluz($k, $pdo, $sEwa, (string)$b1['code']);
    [, $hK] = bon_panel_historia($k, $pdo, $sEwa, (string)$b1['code']);
    [, $hP] = bon_panel_historia($k, $pdo, $sEwaP, (string)$b1['code']);
    [, $hAd] = bon_panel_historia($k, $pdo, $sAdmin, (string)$b1['code']);
    $t('przedłużenie (18:34): kasa bez uprawnienia → 403 „Przedłużenia robi Ewa albo Bartek”, przedluz_wolno false; Ewa i admin true',
        $kodK === 403 && str_contains((string)($odpK['blad'] ?? ''), 'Ewa albo Bartek') && ($hK['przedluz_wolno'] ?? null) === false
        && ($hP['przedluz_wolno'] ?? null) === true && ($hAd['przedluz_wolno'] ?? null) === true, json_encode([$odpK, $hK['przedluz_wolno'] ?? null], JSON_UNESCAPED_UNICODE));
    [$kod, $odp] = bon_panel_przedluz($k, $pdo, $sEwaP, (string)$b1['code']);
    $t('przedłużenie: świeży bon (6 mies. do końca) → 409, przycisku nie ma', $kod === 409 && array_key_exists('przedluzenie', $hE) && $hE['przedluzenie'] === null, json_encode($odp, JSON_UNESCAPED_UNICODE));
    $koniec = $d('+10 days');
    $ustaw($b1['code'], ['expires_at' => $koniec]);
    [, $h] = bon_panel_historia($k, $pdo, $sEwa, (string)$b1['code']);
    $oczekiwana = bon_waznosc(new DateTimeImmutable($koniec, new DateTimeZone(BON_STREFA)), 3);
    $t('przedłużenie: 10 dni przed końcem → panel pokazuje możliwość i nową datę', ($h['przedluzenie']['nowa'] ?? '') === $oczekiwana, json_encode($h['przedluzenie'] ?? null));
    [$kod, $odp] = bon_panel_przedluz($k, $pdo, $sEwaP, (string)$b1['code']);
    $w = $wiersz((string)$b1['code']);
    [, $h] = bon_panel_historia($k, $pdo, $sEwa, (string)$b1['code']);
    $t('przedłużenie: Ewa (rola realizacja) przedłuża → nowa data, pierwotna zapisana, wpis w historii z datami', $kod === 200
        && substr((string)$w['expires_at'], 0, 10) === $oczekiwana && substr((string)$w['expires_at_pierwotna'], 0, 10) === $koniec && $w['przedluzony_at'] !== null
        && ($h['zdarzenia'][0]['akcja'] ?? '') === 'przedluzenie' && ($h['zdarzenia'][0]['kto'] ?? '') === 'ewa' && str_contains((string)$h['zdarzenia'][0]['szczegoly'], $oczekiwana)
        && ($h['przedluzony'] ?? '') === $dzis->format('Y-m-d') && $h['przedluzenie'] === null, json_encode($odp, JSON_UNESCAPED_UNICODE));
    $ustaw($b1['code'], ['expires_at' => $d('+5 days')]); // nawet gdyby admin cofnął datę – drugie przedłużenie nie przysługuje
    [$kod, $odp] = bon_panel_przedluz($k, $pdo, $sEwaP, (string)$b1['code']);
    $t('przedłużenie: drugie → 409 „był już przedłużany”', $kod === 409 && str_contains((string)($odp['blad'] ?? ''), 'już przedłużany'));

    $b3 = $nowyBon(200);
    $ustaw($b3['code'], ['expires_at' => $d('-30 days')]);
    [$kod] = bon_panel_przedluz($k, $pdo, $sEwaP, (string)$b3['code']);
    $t('przedłużenie: 30. dzień po terminie → 200, bon znów ważny', $kod === 200 && bon_status_efektywny($wiersz((string)$b3['code'])) === 'valid');
    $b4 = $nowyBon(200);
    $ustaw($b4['code'], ['expires_at' => $d('-31 days')]);
    [$kod] = bon_panel_przedluz($k, $pdo, $sEwaP, (string)$b4['code']);
    $t('przedłużenie: 31. dzień po terminie → 409', $kod === 409);
    $ustaw($b4['code'], ['expires_at' => $d('+3 days'), 'balance_pln' => '0.00', 'status' => 'used']);
    [$kod] = bon_panel_przedluz($k, $pdo, $sEwaP, (string)$b4['code']);
    $t('przedłużenie: bon z saldem zero → 409', $kod === 409);

    // --- rezygnacja zarejestrowana w panelu (mail / papier) ---
    $b5 = $nowyBon(200, ['amount_paid' => 180]); // rabat: zapłacono 180 przy nominale 200
    $ustaw($b5['code'], ['created_at' => $dzis->modify('-14 days')->format('Y-m-d') . ' 20:00:00']);
    $rez = static fn(array $s, string $kodBonu, array $dane) => bon_panel_admin($k, $pdo, $s, ['kod' => $kodBonu, 'akcja' => 'rezygnacja'] + $dane);
    [$kod] = $rez($sEwa, (string)$b5['code'], ['kanal' => 'gabinet', 'data' => $d('+0 days')]);
    $t('rezygnacja w panelu: konto bez uprawnienia (kasa) → 403', $kod === 403);
    [$kodA] = $rez($sAdmin, (string)$b5['code'], ['kanal' => 'telefon', 'data' => $d('+0 days')]);
    [$kodB] = $rez($sAdmin, (string)$b5['code'], ['kanal' => 'mail', 'data' => $d('+1 day')]);
    [$kodC] = $rez($sAdmin, (string)$b5['code'], ['kanal' => 'mail', 'data' => $d('-20 days')]);
    [$kodD] = $rez($sAdmin, (string)$b5['code'], ['kanal' => 'mail', 'data' => $d('+0 days'), 'otrzymano' => $d('-1 day')]);
    [$kodE] = $rez($sAdmin, (string)$b5['code'], ['kanal' => 'mail', 'data' => $d('+0 days'), 'otrzymano' => $d('+1 day')]);
    $t('rezygnacja w panelu: zły kanał, data z przyszłości, data sprzed zakupu, otrzymanie przed wysłaniem, otrzymanie z przyszłości → 400',
        $kodA === 400 && $kodB === 400 && $kodC === 400 && $kodD === 400 && $kodE === 400, "$kodA $kodB $kodC $kodD $kodE");
    // częściowa realizacja przed rezygnacją: zwrot = saldo (150), nie więcej niż zapłacono (180)
    bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b5['code'], 'kwota_reczna' => '50', 'client_token' => bin2hex(random_bytes(12))]);
    [$kod, $odp] = $rez($sAdmin, (string)$b5['code'], ['kanal' => 'mail', 'data' => $d('+0 days')]);
    $w5 = $rezygnacja((int)$b5['id']);
    $t('rezygnacja w panelu: 14. dzień → 200, bon withdrawn, kanał mail, kwota 135 = saldo 150 × 180/200 (wpis 18:00: proporcjonalnie do zapłaconej ceny)', $kod === 200
        && $wiersz((string)$b5['code'])['status'] === 'withdrawn' && count($w5) === 1 && $w5[0]['kanal'] === 'mail' && $w5[0]['kto'] === 'bartek'
        && bon_zl((int)bon_grosze($w5[0]['kwota_pln'])) === '135.00' && $w5[0]['zwrot_stan'] === 'oczekuje' && !str_contains((string)$odp['opis'], 'PO TERMINIE'), json_encode([$odp, $w5], JSON_UNESCAPED_UNICODE));
    [$kod] = $rez($sAdmin, (string)$b5['code'], ['kanal' => 'mail', 'data' => $d('+0 days')]);
    $t('rezygnacja w panelu: druga na tym samym bonie → 409', $kod === 409);
    [, $h] = bon_panel_historia($k, $pdo, $sEwa, (string)$b5['code']);
    // wpis 19:33: kasa widzi status i daty oświadczenia, ale NIE kwotę ani termin zwrotu (i bez salda bonu nieaktywnego)
    [, $hE] = bon_panel_historia($k, $pdo, $sEwa + ['rezygnacje' => true], (string)$b5['code']);
    $t('rezygnacja w panelu: kasa widzi status i daty oświadczenia, bez kwoty i terminu zwrotu; Ewa widzi kwotę 135 i termin', ($h['status'] ?? '') === 'withdrawn'
        && ($h['rezygnacja']['zlozono'] ?? '') === $d('+0 days') && !isset($h['rezygnacja']['kwota']) && ($h['zdarzenia'][0]['akcja'] ?? '') === 'rezygnacja'
        && ($h['rezygnacja']['otrzymano'] ?? '') === $d('+0 days') && !isset($h['rezygnacja']['zwrot_do']) && $h['saldo'] === null
        && ($hE['rezygnacja']['kwota'] ?? '') === '135.00' && ($hE['rezygnacja']['zwrot_do'] ?? '') === $d('+14 days'), json_encode($h['rezygnacja'] ?? null));

    $b6 = $nowyBon(200);
    $ustaw($b6['code'], ['created_at' => $dzis->modify('-15 days')->format('Y-m-d') . ' 08:00:00']);
    [$kod, $odp] = $rez($sAdmin, (string)$b6['code'], ['kanal' => 'gabinet', 'data' => $d('+0 days')]);
    $t('rezygnacja w panelu: 15. dzień bez powodu → 400 z datą końca terminu', $kod === 400 && str_contains((string)($odp['blad'] ?? ''), $d('-1 day')), json_encode($odp, JSON_UNESCAPED_UNICODE));
    // list wysłany 14. dnia, doszedł dziś; rejestruje Ewa (konto z uprawnieniem)
    [$kod, $odp] = $rez($sEwaRez, (string)$b6['code'], ['kanal' => 'gabinet', 'data' => $d('-1 day')]);
    $w6 = $rezygnacja((int)$b6['id']);
    $t('rezygnacja w panelu: list wysłany 14. dnia (przyszedł później) → w terminie; data wysłania i otrzymania zapisane osobno', $kod === 200
        && !str_contains((string)($odp['opis'] ?? ''), 'PO TERMINIE') && substr((string)$w6[0]['zlozono_at'], 0, 10) === $d('-1 day')
        && substr((string)$w6[0]['potwierdzono_at'], 0, 10) === $d('+0 days') && str_contains((string)$odp['opis'], 'pieniądze do ' . $d('+14 days')), json_encode([$odp, $w6], JSON_UNESCAPED_UNICODE));
    $t('rezygnacja w panelu: zarejestrowała Ewa (konto z uprawnieniem)', ($w6[0]['kto'] ?? '') === 'ewa');
    [$kodC] = bon_panel_admin($k, $pdo, $sEwaRez, ['kod' => $b6['code'], 'akcja' => 'cofnij-rezygnacje', 'powod' => 'pomyłka']);
    // P-11 (2026-11-24, wpis 16:19): „Oznacz zwrot” Ewa może już na bonie Z REZYGNACJĄ; na bonie bez rezygnacji nadal tylko admin
    $bBez = $nowyBon(200);
    [$kodZ] = bon_panel_admin($k, $pdo, $sEwaRez, ['kod' => $bBez['code'], 'akcja' => 'zwrot', 'powod' => 'x']);
    $t('konto z uprawnieniem do rezygnacji: cofnięcie rezygnacji i „Oznacz zwrot” bonu bez rezygnacji → 403 (tylko admin)', $kodC === 403 && $kodZ === 403
        && $wiersz((string)$b6['code'])['status'] === 'withdrawn', "$kodC $kodZ");

    $b7 = $nowyBon(200);
    $ustaw($b7['code'], ['created_at' => $dzis->modify('-20 days')->format('Y-m-d') . ' 08:00:00']);
    [$kod, $odp] = $rez($sAdmin, (string)$b7['code'], ['kanal' => 'mail', 'data' => $d('+0 days'), 'powod' => 'zgoda Ewy']);
    $t('rezygnacja w panelu: po terminie z powodem → 200, w historii „PO TERMINIE” i powód', $kod === 200 && str_contains((string)$odp['opis'], 'PO TERMINIE') && str_contains((string)$odp['opis'], 'zgoda Ewy'));

    $bHeld = $nowyBon(300, ['amount_paid' => 100]);
    [$kod, $odp] = $rez($sAdmin, (string)$bHeld['code'], ['kanal' => 'mail', 'data' => $d('+0 days')]);
    $t('rezygnacja w panelu: bon wstrzymany → 409 (cofnięcie ominęłoby zatwierdzenie)', $kod === 409 && $wiersz((string)$bHeld['code'])['status'] === 'held', json_encode($odp, JSON_UNESCAPED_UNICODE));

    // cofnięcie i ponowna rezygnacja: ten sam wiersz, pola zwrotu wyzerowane
    $pdo->prepare("UPDATE $tabW SET zwrot_blad = 'stary błąd' WHERE voucher_id = ?")->execute([$b7['id']]);
    [$kodC] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $b7['code'], 'akcja' => 'cofnij-rezygnacje', 'powod' => 'pomyłka']);
    [$kod] = $rez($sAdmin, (string)$b7['code'], ['kanal' => 'gabinet', 'data' => $d('+0 days'), 'powod' => 'zgoda Ewy']);
    $w7 = $rezygnacja((int)$b7['id']);
    $t('rezygnacja w panelu: po cofnięciu można ponownie – jeden wiersz, stan „oczekuje”, kanał nowy, błąd wyczyszczony', $kodC === 200 && $kod === 200
        && count($w7) === 1 && $w7[0]['zwrot_stan'] === 'oczekuje' && $w7[0]['kanal'] === 'gabinet' && $w7[0]['zwrot_blad'] === null, json_encode($w7));

    // zwrot ręczny (Oznacz zwrot) zamyka oczekującą rezygnację – krok 3 nie zleci drugiego zwrotu przez API
    [$kod, $odp] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $b5['code'], 'akcja' => 'zwrot', 'powod' => 'zwrot w Easytools']);
    $w5 = $rezygnacja((int)$b5['id']);
    $t('zwrot ręczny przy rezygnacji: bon refunded, rezygnacja „zlecony” z ref „recznie” i kwotą', $kod === 200 && $wiersz((string)$b5['code'])['status'] === 'refunded'
        && $w5[0]['zwrot_stan'] === 'zlecony' && $w5[0]['zwrot_ref'] === 'recznie' && bon_zl((int)bon_grosze($w5[0]['zwrot_kwota_pln'])) === '135.00'
        && str_contains((string)$odp['opis'], 'rezygnacja zamknięta'), json_encode([$odp, $w5], JSON_UNESCAPED_UNICODE));
    [$kod] = bon_panel_przedluz($k, $pdo, $sEwaP, (string)$b5['code']);
    $t('przedłużenie: bon zwrócony → 409', $kod === 409);

    // --- data zapłaty zamiast chwili utworzenia (14 dni i 6 miesięcy „od dnia zapłaty”) ---
    $polnoc = new DateTimeImmutable($d('+0 days') . ' 00:20:00', new DateTimeZone(BON_STREFA));
    $wczoraj = $polnoc->modify('-1 day')->format('Y-m-d');
    $zIso = bon_data_zaplaty($wczoraj . 'T22:58:00+00:00', $polnoc); // 23:58 w Polsce (zima, UTC+1)
    $zUnix = bon_data_zaplaty($polnoc->modify('-25 minutes')->getTimestamp(), $polnoc);
    $t('data zapłaty: ISO z UTC i unix z webhooka → dzień zapłaty w czasie polskim (zakup 23:58, bon o 00:20)',
        $zIso->format('Y-m-d H:i') === $wczoraj . ' 23:58' && $zUnix->format('Y-m-d') === $wczoraj, $zIso->format('c') . ' ' . $zUnix->format('c'));
    $t('data zapłaty: brak, śmieci, przyszłość, sprzed 31 dni → teraz', bon_data_zaplaty(null, $polnoc) == $polnoc && bon_data_zaplaty('nie-data', $polnoc) == $polnoc
        && bon_data_zaplaty($polnoc->modify('+1 hour')->format('c'), $polnoc) == $polnoc && bon_data_zaplaty($polnoc->modify('-31 days')->format('c'), $polnoc) == $polnoc);
    $zamApi = ['id' => bon_test_uuid(), 'created_at' => $dzis->modify('-2 days')->format('c'), 'amount' => 20000, 'line_items' => []];
    $t('data zapłaty z API: „date” transakcji, bez niej created_at zamówienia', bon_et_dane_zamowienia($zamApi, 't', '2026-01-02T10:00:00+01:00')['zaplacono_at'] === '2026-01-02T10:00:00+01:00'
        && bon_et_dane_zamowienia($zamApi, null)['zaplacono_at'] === $zamApi['created_at']);
    $zaplataWczoraj = $dzis->modify('-1 day')->setTime(23, 58);
    $bZ = $nowyBon(200, ['timestamp' => $zaplataWczoraj->getTimestamp()]);
    $t('bon z webhooka: created_at = chwila zapłaty, ważność 6 mies. od dnia zapłaty, rezygnacja do zapłata + 14',
        substr((string)$bZ['created_at'], 0, 16) === $zaplataWczoraj->format('Y-m-d H:i')
        && substr((string)$bZ['expires_at'], 0, 10) === bon_waznosc($zaplataWczoraj, (int)$k['waznosc_miesiecy'])
        && bon_rezygnacja_termin($bZ) === $zaplataWczoraj->modify('+14 days')->format('Y-m-d'), json_encode($bZ));

    return $wyniki;
}
