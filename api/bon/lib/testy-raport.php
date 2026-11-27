<?php
// Testy raportu „Zrealizowano z bonów” (wpis czatu 2026-11-26 01:51): okresy, granice 23:59:59 / 00:00:00 dnia, tygodnia,
// miesiąca i roku, cofnięcie (wyłączone z sumy), uprawnienia (poziom Bon = 403). Woła je bon_testy() w lib/testy.php.
// Granice na realizacjach z 2031 r. (wpisanych wprost do tabeli), żeby inne testy z dzisiejszą datą nie zmieniały sum.

declare(strict_types=1);
require_once __DIR__ . '/raport.php';

function bon_testy_raport(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $strefa = new DateTimeZone(BON_STREFA);
    $chwila = static fn(string $s) => new DateTimeImmutable($s, $strefa);
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    $sEwa = ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'rezygnacje' => true, 'csrf' => 'x'];
    $sKasa = ['login' => 'karolina', 'imie' => 'Karolina', 'rola' => 'realizacja', 'csrf' => 'x'];
    $okres = static fn(array $get, string $teraz) => bon_raport_okres($get, $chwila($teraz));

    // --- okresy (czwartek 26.11.2026) ---
    $czw = '2026-11-26 10:00:00';
    $t('raport okres: brak parametrów = bieżący miesiąc 1–26.11', $okres([], $czw) === [200, ['okres' => 'miesiac', 'od' => '2026-11-01', 'do' => '2026-11-26']]);
    $t('raport okres: dziś, tydzień od poniedziałku 23.11, rok od 1.01', $okres(['okres' => 'dzis'], $czw)[1] === ['okres' => 'dzis', 'od' => '2026-11-26', 'do' => '2026-11-26']
        && $okres(['okres' => 'tydzien'], $czw)[1] === ['okres' => 'tydzien', 'od' => '2026-11-23', 'do' => '2026-11-26']
        && $okres(['okres' => 'rok'], $czw)[1] === ['okres' => 'rok', 'od' => '2026-01-01', 'do' => '2026-11-26']);
    $t('raport okres: tydzień w niedzielę 29.11 od 23.11, w poniedziałek 30.11 tylko ten dzień, o 00:00 nowy dzień',
        $okres(['okres' => 'tydzien'], '2026-11-29 23:59:59')[1]['od'] === '2026-11-23'
        && $okres(['okres' => 'tydzien'], '2026-11-30 00:00:00')[1] === ['okres' => 'tydzien', 'od' => '2026-11-30', 'do' => '2026-11-30']);
    $t('raport okres: zakres od–do (też przyszłe „do”) → okres „zakres”, daty bez zmian',
        $okres(['od' => '2026-12-01', 'do' => '2027-01-31', 'okres' => 'dzis'], $czw) === [200, ['okres' => 'zakres', 'od' => '2026-12-01', 'do' => '2027-01-31']]);
    $zle = [['od' => '2026-12-01'], ['od' => '2026-12-31', 'do' => '2026-12-01'], ['od' => '2026-02-30', 'do' => '2026-03-01'],
        ['od' => '1.12.2026', 'do' => '2026-12-31'], ['okres' => 'kwartal'], ['od' => '2020-01-01', 'do' => '2026-01-01']];
    $kody = array_map(static fn($g) => $okres($g, $czw)[0], $zle);
    $t('raport okres: jedna data, od > do, 30.02, zły format, nieznany okres, zakres > 5 lat → 400', $kody === [400, 400, 400, 400, 400, 400], json_encode($kody));

    // --- sumy na granicach (2031: 31.03 to poniedziałek) ---
    $p = bon_test_payload(['price_custom_id' => 'bon-500', 'amount_paid' => 500]);
    bon_test_webhook($k, $pdo, $p);
    $bon = bon_test_bony($k, $pdo, $p['order_id'])[0] ?? [];
    $tabR = bon_tabela($k, 'redemptions');
    $tabBon = bon_tabela($k, 'vouchers');
    $dodaj = static function (string $kiedy, string $kwota) use ($pdo, $tabR, $tabBon, $bon): int {
        $pdo->prepare("INSERT INTO $tabR (voucher_id, amount_pln, doplata_pln, typ, pozycje, notatka, redeemed_at, redeemed_by, client_token)
                       VALUES (?, ?, '0.00', 'reczna', '[]', NULL, ?, 'test-raport', ?)")->execute([$bon['id'], $kwota, $kiedy, bin2hex(random_bytes(12))]);
        $id = (int)$pdo->lastInsertId();
        // saldo jak przy prawdziwej realizacji (niezmiennik salda niżej: wartość − realizacje nie cofnięte = saldo)
        $pdo->prepare("UPDATE $tabBon SET balance_pln = balance_pln - ? WHERE id = ?")->execute([$kwota, $bon['id']]);
        return $id;
    };
    $dodaj('2031-03-30 23:59:59', '5.00');   // niedziela przed tygodniem 31.03
    $dodaj('2031-03-31 23:59:59', '50.00');  // ostatnia sekunda marca
    $dodaj('2031-04-01 00:00:00', '30.00');  // pierwsza sekunda kwietnia
    $cof = $dodaj('2031-04-15 12:00:00', '100.00');
    bon_historia($k, $pdo, (int)$bon['id'], 'realizacja-cofnieta', "realizacja #$cof: −100.00 zł (kwota ręczna, 2031-04-15 12:00), saldo 0.00 → 100.00 zł", 'ewa', $chwila('2031-04-16 09:00:00'));
    $pdo->prepare("UPDATE $tabBon SET balance_pln = balance_pln + 100 WHERE id = ?")->execute([$bon['id']]);
    $dodaj('2031-04-30 23:59:59', '20.10');
    $dodaj('2031-05-01 00:00:00', '7.00');
    $suma = static fn(array $get, string $teraz) => bon_raport($k, $pdo, $sAdmin, $get, $chwila($teraz))[1];
    $kw = $suma(['od' => '2031-04-01', 'do' => '2031-04-30'], $czw);
    $t('raport suma: kwiecień = 00:00 1.04 + 23:59:59 30.04, bez 31.03, 1.05 i cofniętej (100 zł cofnięte następnego dnia)',
        ($kw['zrealizowano'] ?? '') === '50.10' && ($kw['realizacji'] ?? 0) === 2 && ($kw['ok'] ?? false) === true, json_encode($kw));
    $dz = $suma(['okres' => 'dzis'], '2031-04-01 08:00:00');
    $mar = $suma(['okres' => 'miesiac'], '2031-03-31 23:59:59');
    $t('raport suma: dziś 1.04 = tylko 30 zł; bieżący miesiąc o 23:59:59 31.03 = 5 + 50 zł',
        ($dz['zrealizowano'] ?? '') === '30.00' && ($mar['zrealizowano'] ?? '') === '55.00' && ($mar['od'] ?? '') === '2031-03-01', json_encode([$dz, $mar]));
    $tyd = $suma(['okres' => 'tydzien'], '2031-04-06 20:00:00');
    $t('raport suma: tydzień (pon. 31.03 – niedz. 6.04) = 50 + 30 zł, bez niedzieli 30.03', ($tyd['zrealizowano'] ?? '') === '80.00' && ($tyd['od'] ?? '') === '2031-03-31', json_encode($tyd));
    $rok = $suma(['okres' => 'rok'], '2031-12-31 23:59:59');
    $nowy = $suma(['okres' => 'rok'], '2032-01-01 00:00:00');
    $t('raport suma: rok 2031 = 5 + 50 + 30 + 20,10 + 7 (bez cofniętej), 1.01.2032 = nowy rok bez nich',
        ($rok['zrealizowano'] ?? '') === '112.10' && ($rok['realizacji'] ?? 0) === 5 && ($nowy['zrealizowano'] ?? '') === '0.00', json_encode([$rok, $nowy]));

    // --- lista realizacji (karta #210, Backend 2 26.11): kształt jak historia[] z historia.php + kod, nazwa, kolor ---
    $lis = $kw['lista'] ?? null;
    $klucze = ['id', 'kod', 'nazwa', 'kolor', 'cofnieta', 'kiedy', 'zdjeto', 'doplata', 'typ', 'pozycje', 'notatka', 'kto'];
    $t('raport lista: kwiecień = 3 wiersze najnowsze pierwsze (30.04, 15.04 cofnięta, 1.04), z cofniętą, lista_wszystkich 3, nie ucięta',
        is_array($lis) && count($lis) === 3
        && array_column($lis, 'kiedy') === ['2031-04-30 23:59:59', '2031-04-15 12:00:00', '2031-04-01 00:00:00']
        && array_column($lis, 'cofnieta') === [false, true, false] && array_column($lis, 'zdjeto') === ['20.10', '100.00', '30.00']
        && ($kw['lista_wszystkich'] ?? null) === 3 && ($kw['lista_obcieta'] ?? null) === false, json_encode($kw, JSON_UNESCAPED_UNICODE));
    $w0 = $lis[1] ?? [];
    $t('raport lista: wiersz ma dokładnie pola umowy (bez danych kupującego), pełny numer bonu, id cofniętej realizacji, typ i pozycje',
        array_keys($w0) === $klucze && ($w0['kod'] ?? '') === $bon['code'] && ($w0['id'] ?? 0) === $cof && ($w0['typ'] ?? '') === 'reczna'
        && ($w0['pozycje'] ?? null) === [] && ($w0['doplata'] ?? '') === '0.00' && ($w0['kto'] ?? '') === bon_panel_imie($k, 'test-raport')
        && ($w0['nazwa'] ?? '') === bon_nazwa($k, $bon) && ($w0['kolor'] ?? '') === bon_kolor($k, $bon), json_encode($w0, JSON_UNESCAPED_UNICODE));
    $json = json_encode($kw, JSON_UNESCAPED_UNICODE);
    $t('raport lista: w odpowiedzi nie ma e-maila ani nazwiska kupującego', !str_contains($json, '@') && !str_contains($json, 'buyer'), $json);
    $sumaListy = array_sum(array_map(static fn($w) => $w['cofnieta'] ? 0 : (int)bon_grosze($w['zdjeto']), $lis ?? []));
    $t('raport lista: suma nie-cofniętych z listy = zrealizowano, liczba = realizacji (rok 2031)',
        $sumaListy === (int)bon_grosze((string)($kw['zrealizowano'] ?? 'x'))
        && count(array_filter($rok['lista'] ?? [], static fn($w) => !$w['cofnieta'])) === ($rok['realizacji'] ?? -1) && ($rok['lista_wszystkich'] ?? 0) === 6);
    $ucieta = bon_raport_lista($k, $pdo, '2031-04-01', '2031-04-30', 2);
    $t('raport lista: limit 2 → 2 najnowsze wiersze, lista_wszystkich 3, lista_obcieta true',
        count($ucieta['lista']) === 2 && ($ucieta['lista'][0]['kiedy'] ?? '') === '2031-04-30 23:59:59'
        && $ucieta['lista_wszystkich'] === 3 && $ucieta['lista_obcieta'] === true, json_encode($ucieta, JSON_UNESCAPED_UNICODE));
    $pusty = $suma(['od' => '2031-06-01', 'do' => '2031-06-30'], $czw);
    $t('raport lista: okres bez realizacji → pusta lista, 0, nie ucięta',
        ($pusty['lista'] ?? null) === [] && ($pusty['lista_wszystkich'] ?? null) === 0 && ($pusty['lista_obcieta'] ?? null) === false, json_encode($pusty));

    // --- prawdziwy przepływ: realizacja i cofnięcie dziś = 0 ---
    $p2 = bon_test_payload(['price_custom_id' => 'bon-300', 'amount_paid' => 300]);
    bon_test_webhook($k, $pdo, $p2);
    $kod = (string)(bon_test_bony($k, $pdo, $p2['order_id'])[0]['code'] ?? '');
    $dzis = static fn() => bon_raport($k, $pdo, $sEwa, ['okres' => 'dzis'])[1];
    $przed = $dzis();
    bon_panel_realizuj($k, $pdo, $sKasa, ['kod' => $kod, 'kwota_reczna' => '40', 'client_token' => bin2hex(random_bytes(12))]);
    $po = $dzis();
    [, $h] = bon_panel_historia($k, $pdo, $sEwa, $kod);
    [$kodC] = bon_panel_admin($k, $pdo, $sEwa, ['kod' => $kod, 'akcja' => 'cofnij-realizacje', 'realizacja' => $h['cofnij_realizacje']['id'] ?? 0, 'powod' => 'test raportu']);
    $poCof = $dzis();
    $g = static fn(array $o) => (int)bon_grosze((string)($o['zrealizowano'] ?? 'x'));
    $t('raport suma: realizacja 40 zł dziś → +40 zł i +1; po cofnięciu tego samego dnia z powrotem jak przed (0)',
        $g($po) - $g($przed) === 4000 && ($po['realizacji'] ?? 0) - ($przed['realizacji'] ?? 0) === 1 && $kodC === 200
        && $g($poCof) === $g($przed) && ($poCof['realizacji'] ?? -1) === ($przed['realizacji'] ?? -2), json_encode([$przed, $po, $poCof]));
    $wiersz = array_values(array_filter($poCof['lista'] ?? [], static fn($w) => ($w['kod'] ?? '') === $kod))[0] ?? [];
    $wPrzed = array_values(array_filter($po['lista'] ?? [], static fn($w) => ($w['kod'] ?? '') === $kod))[0] ?? [];
    $t('raport lista: realizacja z panelu jest na liście dziś (40 zł, imię realizującego), po cofnięciu zostaje z cofnieta = true',
        ($wPrzed['cofnieta'] ?? null) === false && ($wPrzed['zdjeto'] ?? '') === '40.00' && ($wPrzed['kto'] ?? '') === bon_panel_imie($k, 'karolina')
        && ($wiersz['cofnieta'] ?? null) === true && ($wiersz['id'] ?? 0) === ($wPrzed['id'] ?? -1)
        && ($poCof['lista_wszystkich'] ?? 0) === ($po['lista_wszystkich'] ?? -1), json_encode([$wPrzed, $wiersz], JSON_UNESCAPED_UNICODE));

    // --- saldo pozostałe na koniec okresu (karta #210, decyzja Bartka 26.11 ok. 23:55: „na koniec okresu”) ---
    // Czysta funkcja stanu jednego bonu na chwilę $koniec (Q1, bez bazy): cofamy zdarzenia od $koniec, od najnowszego.
    $K = '2031-05-01 00:00:00'; // koniec kwietnia 2031
    $b = static fn(array $z = []) => $z + ['status' => 'valid', 'balance_pln' => '100.00', 'expires_at' => '2031-10-01', 'status_uwagi' => null, 'created_at' => '2031-04-10 12:00:00'];
    $r = static fn(string $kwota, string $kiedy, bool $cof = false) => ['kwota_pln' => $kwota, 'kiedy' => $kiedy, 'cofnieta' => $cof];
    $z = static fn(string $akcja, string $kiedy, string $szcz = '') => ['akcja' => $akcja, 'szczegoly' => $szcz, 'kiedy' => $kiedy];
    $st = static fn(array $bon, array $rr = [], array $zz = []) => bon_raport_stan_na($bon, $rr, $zz, $K);
    $t('saldo stan: bon kupiony po końcu okresu (1.05 00:00) → nie istniał; kupiony 30.04 23:59:59 → istniał',
        $st($b(['created_at' => '2031-05-01 00:00:00'])) === null && ($st($b(['created_at' => '2031-04-30 23:59:59']))['saldo'] ?? 0) === 10000);
    $t('saldo stan: bez zdarzeń po końcu = stan dzisiejszy (ważny, 100 zł, data ważności)',
        $st($b(), [$r('50.00', '2031-04-20 10:00:00')]) === ['status' => 'valid', 'saldo' => 10000, 'wazny_do' => '2031-10-01']);
    $t('saldo stan: realizacja po końcu (2.05) wraca do salda; realizacja 1.05 00:00:00 też (granica)',
        ($st($b(['balance_pln' => '40.00']), [$r('60.00', '2031-05-02 09:00:00')])['saldo'] ?? 0) === 10000
        && ($st($b(['balance_pln' => '70.00']), [$r('30.00', '2031-05-01 00:00:00')])['saldo'] ?? 0) === 10000);
    $t('saldo stan: bon wykorzystany po końcu (saldo 0, status used) → na koniec okresu ważny z saldem',
        $st($b(['status' => 'used', 'balance_pln' => '0.00']), [$r('100.00', '2031-05-03 09:00:00')]) === ['status' => 'valid', 'saldo' => 10000, 'wazny_do' => '2031-10-01']);
    $t('saldo stan: wykorzystany przed końcem → used, saldo 0',
        $st($b(['status' => 'used', 'balance_pln' => '0.00']), [$r('100.00', '2031-04-20 09:00:00')]) === ['status' => 'used', 'saldo' => 0, 'wazny_do' => '2031-10-01']);
    $t('saldo stan: cofnięta realizacja = korekta pomyłki (jak w zrealizowano) – przed końcem i po końcu bez wpływu na saldo',
        ($st($b(), [$r('40.00', '2031-04-30 18:00:00', true)], [$z('realizacja-cofnieta', '2031-05-01 09:00:00', 'realizacja #1: …')])['saldo'] ?? 0) === 10000
        && ($st($b(), [$r('40.00', '2031-05-02 18:00:00', true)])['saldo'] ?? 0) === 10000);
    $t('saldo stan: zwrot po końcu („valid → zwrócony”) → na koniec okresu ważny; zwrot przed końcem → refunded',
        ($st($b(['status' => 'refunded']), [], [$z('zwrot', '2031-05-05 10:00:00', 'valid → zwrócony, saldo w chwili zwrotu 100.00 zł')])['status'] ?? '') === 'valid'
        && ($st($b(['status' => 'refunded']), [], [$z('zwrot', '2031-04-25 10:00:00', 'valid → zwrócony')])['status'] ?? '') === 'refunded');
    $t('saldo stan: rezygnacja przed końcem + zwrot potwierdzony po końcu → withdrawn; rezygnacja po końcu → valid',
        ($st($b(['status' => 'refunded']), [], [$z('rezygnacja', '2031-04-28 10:00:00'), $z('zwrot-potwierdzony', '2031-05-06 10:00:00')])['status'] ?? '') === 'withdrawn'
        && ($st($b(['status' => 'refunded']), [], [$z('rezygnacja', '2031-05-02 10:00:00'), $z('zwrot-potwierdzony', '2031-05-06 10:00:00')])['status'] ?? '') === 'valid');
    $t('saldo stan: rezygnacja cofnięta po końcu → na koniec okresu withdrawn; zwrot przy rezygnacji („withdrawn → zwrócony”) po końcu → withdrawn',
        ($st($b(), [], [$z('rezygnacja', '2031-04-28 10:00:00'), $z('rezygnacja-cofnieta', '2031-05-02 10:00:00')])['status'] ?? '') === 'withdrawn'
        && ($st($b(['status' => 'refunded']), [], [$z('rezygnacja', '2031-04-28 10:00:00'), $z('zwrot', '2031-05-02 10:00:00', 'withdrawn → zwrócony, saldo …')])['status'] ?? '') === 'withdrawn');
    $t('saldo stan: zatwierdzony po końcu → na koniec okresu wstrzymany (held); przed końcem → valid',
        ($st($b(['status_uwagi' => 'kwota']), [], [$z('zatwierdz', '2031-05-02 10:00:00', 'wstrzymany → ważny')])['status'] ?? '') === 'held'
        && ($st($b(['status_uwagi' => 'kwota']), [], [$z('zatwierdz', '2031-04-12 10:00:00', 'wstrzymany → ważny')])['status'] ?? '') === 'valid');
    $t('saldo stan: przedłużenie i zmiana daty po końcu → data sprzed zmian (od najnowszej, z „; powód”); przed końcem → bez zmian',
        ($st($b(['expires_at' => '2032-01-20']), [], [$z('przedluzenie', '2031-05-03 10:00:00', '2031-04-20 → 2031-07-20 (o 3 mies.)'),
            $z('waznosc', '2031-06-01 10:00:00', '2031-07-20 → 2032-01-20; prośba klienta')])['wazny_do'] ?? '') === '2031-04-20'
        && ($st($b(['expires_at' => '2031-07-20']), [], [$z('przedluzenie', '2031-04-25 10:00:00', '2031-04-20 → 2031-07-20 (o 3 mies.)')])['wazny_do'] ?? '') === '2031-07-20');

    // Agregat z bazy: różnica przed/po dodaniu bonów testowych z 2031 (inne testy zostawiają bony z dowolnymi datami).
    $saldo = static fn(string $do) => bon_raport_saldo($k, $pdo, $do, $chwila('2031-06-15 12:00:00'));
    $przedS = $saldo('2031-04-30');
    $przedMaj = $saldo('2031-05-31');
    $tabB = bon_tabela($k, 'vouchers');
    $nowyBon = static function (int $zl, string $kupiony, string $wazny, string $status = 'valid', ?string $uwagi = null) use ($k, $pdo, $tabB): array {
        $p = bon_test_payload(['price_custom_id' => 'bon-' . $zl, 'amount_paid' => $zl]);
        bon_test_webhook($k, $pdo, $p);
        $bb = bon_test_bony($k, $pdo, $p['order_id'])[0];
        $pdo->prepare("UPDATE $tabB SET created_at = ?, expires_at = ?, status = ?, status_uwagi = ? WHERE id = ?")->execute([$kupiony, $wazny, $status, $uwagi, $bb['id']]);
        return $bb;
    };
    $wR = static function (array $bb, string $kwota, string $kiedy) use ($pdo, $tabR, $tabB): int {
        $pdo->prepare("INSERT INTO $tabR (voucher_id, amount_pln, doplata_pln, typ, pozycje, notatka, redeemed_at, redeemed_by, client_token)
                       VALUES (?, ?, '0.00', 'reczna', '[]', NULL, ?, 'test-raport', ?)")->execute([$bb['id'], $kwota, $kiedy, bin2hex(random_bytes(12))]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE $tabB SET balance_pln = balance_pln - ? WHERE id = ?")->execute([$kwota, $bb['id']]);
        return $id;
    };
    $s1 = $nowyBon(200, '2031-04-05 10:00:00', '2031-10-05');               // ważny: 200 − 50 (kwiecień) − 30 (maj) → na 30.04: 150
    $wR($s1, '50.00', '2031-04-12 10:00:00');
    $wR($s1, '30.00', '2031-05-02 10:00:00');
    $s2 = $nowyBon(300, '2031-05-03 10:00:00', '2031-11-03');               // kupiony w maju → poza saldem na 30.04
    $s3 = $nowyBon(500, '2031-04-08 10:00:00', '2031-10-08', 'refunded');   // zwrócony w maju → na 30.04 ważny 500
    bon_historia($k, $pdo, (int)$s3['id'], 'zwrot', 'valid → zwrócony, saldo w chwili zwrotu 500.00 zł', 'bartek', $chwila('2031-05-04 10:00:00'));
    $s4 = $nowyBon(200, '2031-04-09 10:00:00', '2031-10-09', 'refunded');   // zwrócony w kwietniu → poza saldem
    bon_historia($k, $pdo, (int)$s4['id'], 'zwrot', 'valid → zwrócony, saldo w chwili zwrotu 200.00 zł', 'bartek', $chwila('2031-04-20 10:00:00'));
    $s5 = $nowyBon(300, '2031-04-10 10:00:00', '2031-10-10', 'valid', 'kwota'); // zatwierdzony w maju → na 30.04 wstrzymany 300
    bon_historia($k, $pdo, (int)$s5['id'], 'zatwierdz', 'wstrzymany → ważny', 'bartek', $chwila('2031-05-02 10:00:00'));
    $s6 = $nowyBon(200, '2030-10-01 10:00:00', '2031-04-30', 'valid');       // ważny do 30.04 włącznie → liczy się na 30.04
    $s7 = $nowyBon(500, '2030-10-01 10:00:00', '2031-04-29', 'valid');       // wygasł 29.04 → poza saldem
    $s8 = $nowyBon(200, '2030-10-02 10:00:00', '2031-07-28', 'valid');       // przedłużony w maju z 28.04 → na 30.04 wygasły
    bon_historia($k, $pdo, (int)$s8['id'], 'przedluzenie', '2031-04-28 → 2031-07-28 (o 3 mies.)', 'klient', $chwila('2031-05-10 10:00:00'));
    $s9 = $nowyBon(300, '2031-04-11 10:00:00', '2031-10-11', 'valid');       // realizacja 30.04 cofnięta 1.05 = korekta → 300
    $cof9 = $wR($s9, '100.00', '2031-04-30 18:00:00');
    $pdo->prepare("UPDATE $tabB SET balance_pln = balance_pln + 100 WHERE id = ?")->execute([$s9['id']]);
    bon_historia($k, $pdo, (int)$s9['id'], 'realizacja-cofnieta', "realizacja #$cof9: −100.00 zł (kwota ręczna, 2031-04-30 18:00), saldo 200.00 → 300.00 zł", 'ewa', $chwila('2031-05-01 09:00:00'));
    $poS = $saldo('2031-04-30');
    $gr = static fn(array $s, string $p) => (int)bon_grosze((string)($s[$p] ?? 'x'));
    $t('saldo agregat 30.04.2031: +150 (realizacja w maju wraca) +500 (zwrot w maju) +200 (ważny do 30.04) +300 (cofnięcie = korekta) = +1150 zł, +4 bony; wstrzymane +300, +1; poza: kupiony w maju, zwrot w kwietniu, wygasły 29.04, przedłużony po terminie',
        $gr($poS, 'pozostalo') - $gr($przedS, 'pozostalo') === 115000 && ($poS['bonow'] ?? 0) - ($przedS['bonow'] ?? 0) === 4
        && $gr($poS, 'wstrzymane') - $gr($przedS, 'wstrzymane') === 30000 && ($poS['wstrzymanych'] ?? 0) - ($przedS['wstrzymanych'] ?? 0) === 1
        && ($poS['na_dzien'] ?? '') === '2031-04-30', json_encode([$przedS, $poS]));
    $poMaj = $saldo('2031-05-31');
    $t('saldo agregat: przyszły „do” (31.12.2031 przy dniu 15.06.2031) liczy się na dziś, na_dzien = dziś',
        $saldo('2031-12-31') === $saldo('2031-06-15') && ($saldo('2031-12-31')['na_dzien'] ?? '') === '2031-06-15');
    $t('saldo agregat 31.05.2031: s1 120, s2 300, s5 300 (już zatwierdzony), s8 200 (przedłużony), s9 300; bez s3 (zwrot), s6 i s7 (wygasłe) = +1220 zł, wstrzymanych +0',
        $gr($poMaj, 'pozostalo') - $gr($przedMaj, 'pozostalo') === 122000 && ($poMaj['bonow'] ?? 0) - ($przedMaj['bonow'] ?? 0) === 5
        && ($poMaj['wstrzymanych'] ?? 0) === ($przedMaj['wstrzymanych'] ?? -1), json_encode([$przedMaj, $poMaj]));

    // Korekta (OK Backendu 27.11): realizacja 30.11 18:00 cofnięta 1.12 9:00 → listopad bez niej w OBU liczbach
    // (zrealizowano i realizacji bez zmian, saldo na 30.11 pełne), na liście listopada jest, z cofnieta = true.
    $listopad = static fn() => bon_raport($k, $pdo, $sAdmin, ['od' => '2031-11-01', 'do' => '2031-11-30'], $chwila('2031-12-15 12:00:00'))[1];
    $przedL = $listopad();
    $s10 = $nowyBon(300, '2031-11-02 10:00:00', '2032-05-02');
    $cof10 = $wR($s10, '120.00', '2031-11-30 18:00:00');
    $pdo->prepare("UPDATE $tabB SET balance_pln = balance_pln + 120 WHERE id = ?")->execute([$s10['id']]);
    bon_historia($k, $pdo, (int)$s10['id'], 'realizacja-cofnieta', "realizacja #$cof10: −120.00 zł (kwota ręczna, 2031-11-30 18:00), saldo 180.00 → 300.00 zł", 'ewa', $chwila('2031-12-01 09:00:00'));
    $poL = $listopad();
    $w10 = array_values(array_filter($poL['lista'] ?? [], static fn($w) => ($w['id'] ?? 0) === $cof10))[0] ?? [];
    $t('saldo korekta: realizacja 30.11 18:00 cofnięta 1.12 9:00 → listopad: zrealizowano i realizacji bez zmian, saldo +300 (pełne), na liście z cofnieta = true',
        ($poL['zrealizowano'] ?? '') === ($przedL['zrealizowano'] ?? 'x') && ($poL['realizacji'] ?? -1) === ($przedL['realizacji'] ?? -2)
        && $gr($poL['saldo'] ?? [], 'pozostalo') - $gr($przedL['saldo'] ?? [], 'pozostalo') === 30000 && ($w10['cofnieta'] ?? null) === true,
        json_encode([$przedL['saldo'] ?? null, $poL['saldo'] ?? null, $w10], JSON_UNESCAPED_UNICODE));

    // Kontrola zgodności (bon_raport_niezgodnosci – do podpięcia w log.php na produkcji): na bonach z prawdziwych ścieżek
    // pusta, a wykrywa saldo bez realizacji i zwrot / rezygnację bez wpisu w historii. Na całej bazie testowej jej nie
    // puszczamy: inne zestawy ustawiają stan wprost w SQL (np. testy-krok1, testy-sync) – to skróty testów, nie kodu.
    $moje = array_map(static fn($bb) => (int)$bb['id'], [$s1, $s2, $s3, $s4, $s5, $s6, $s7, $s8, $s9, $bon]);
    $t('saldo kontrola: bony testowe raportu (realizacje, cofnięcie, zwroty z historią) – bez niezgodności',
        bon_raport_niezgodnosci($k, $pdo, $moje) === [], json_encode(bon_raport_niezgodnosci($k, $pdo, $moje), JSON_UNESCAPED_UNICODE));
    $pdo->prepare("UPDATE $tabB SET balance_pln = balance_pln - 1 WHERE id = ?")->execute([$s2['id']]);
    $pdo->prepare("UPDATE $tabB SET status = 'refunded' WHERE id = ?")->execute([$s6['id']]);
    $pdo->prepare("UPDATE $tabB SET status = 'withdrawn' WHERE id = ?")->execute([$s9['id']]);
    $niez = bon_raport_niezgodnosci($k, $pdo, $moje);
    $t('saldo kontrola: wykrywa saldo bez realizacji, zwrot bez wpisu „zwrot” i rezygnację bez wpisu „rezygnacja”',
        array_column($niez, 'kod') === [$s2['code'], $s6['code'], $s9['code']] && count(array_filter(array_column($niez, 'problem'))) === 3, json_encode($niez, JSON_UNESCAPED_UNICODE));
    // #274: kontrola dla log.php (?saldo=1) – tylko bony ruszane w ostatnich N dniach, najwyżej $maks, numery zamaskowane
    $kontr = bon_raport_kontrola($k, $pdo, 30, 500);
    $kodyK = array_column($kontr['bony'], 'kod');
    $t('#274 kontrola: rozjechane saldo z ostatnich 30 dni w wyniku, numer zamaskowany (EWA-****-XXXX), pełnego numeru brak',
        in_array(bon_kod_maska($s2['code']), $kodyK, true) && !str_contains(json_encode($kontr), $s2['code']) && $kontr['niezgodnych'] >= 3
        && $kontr['dni'] === 30 && $kontr['sprawdzono'] >= count($moje), json_encode(array_diff_key($kontr, ['bony' => 1])));
    $kontr1 = bon_raport_kontrola($k, $pdo, 30, 1);
    $t('#274 kontrola: limit bonów – sprawdzono 1, obcieto = true', $kontr1['sprawdzono'] === 1 && $kontr1['obcieto'] === true, json_encode($kontr1));
    $pdo->prepare("UPDATE $tabB SET balance_pln = balance_pln + 1 WHERE id = ?")->execute([$s2['id']]);
    $pdo->prepare("UPDATE $tabB SET status = 'valid' WHERE id IN (?, ?)")->execute([$s6['id'], $s9['id']]);
    $t('#274 kontrola: po naprawie saldo bonu poza wynikiem', !in_array(bon_kod_maska($s2['code']), array_column(bon_raport_kontrola($k, $pdo, 30, 500)['bony'], 'kod'), true));

    // W obie strony na PRAWDZIWYCH akcjach (opisy zdarzeń pisze kod Backendu – lib/panel.php, lib/bon.php): stan bonu przed akcją
    // = bon_raport_stan_na(stan po akcji, jej realizacje i zdarzenia, koniec = chwila akcji). Zmiana prefiksu opisu = test czerwony.
    $sekunda = 0;
    $krok = static function () use (&$sekunda): DateTimeImmutable { $sekunda += 2; return bon_teraz()->modify("+$sekunda seconds"); };
    $stanTeraz = static function (string $kod) use ($k, $pdo): array {
        $bb = bon_panel_znajdz($k, $pdo, $kod);
        $saldoG = (int)bon_grosze($bb['balance_pln']);
        $st = in_array($bb['status'], ['valid', 'used'], true) ? ($saldoG > 0 ? 'valid' : 'used') : (string)$bb['status'];
        return ['status' => $st, 'saldo' => $saldoG, 'wazny_do' => substr((string)$bb['expires_at'], 0, 10)];
    };
    $wstecz = static function (string $kod, string $koniec) use ($k, $pdo, $tabR): ?array {
        $bb = bon_panel_znajdz($k, $pdo, $kod);
        $cofW = bon_raport_cofniete($k, $pdo);
        $st = $pdo->prepare("SELECT id, amount_pln, redeemed_at FROM $tabR WHERE voucher_id = ?");
        $st->execute([$bb['id']]);
        $rr = array_map(static fn($x) => ['kwota_pln' => $x['amount_pln'], 'kiedy' => (string)$x['redeemed_at'], 'cofnieta' => isset($cofW[(int)$x['id']])], $st->fetchAll());
        $st = $pdo->prepare('SELECT id, akcja, szczegoly, kiedy FROM ' . bon_tabela($k, 'voucher_admin_log') . ' WHERE voucher_id = ?');
        $st->execute([$bb['id']]);
        return bon_raport_stan_na($bb, $rr, $st->fetchAll(), $koniec);
    };
    $wObie = static function (string $nazwa, string $kod, callable $akcja) use ($t, $krok, $stanTeraz, $wstecz): void {
        $przed = $stanTeraz($kod);
        $chwila = $krok();
        [$kodA, $odpA] = $akcja($chwila);
        $po = $stanTeraz($kod);
        $odtw = $wstecz($kod, $chwila->format('Y-m-d H:i:s'));
        $t("saldo w obie strony: $nazwa – akcja 200, stan się zmienił, odtworzony stan sprzed akcji = prawdziwy",
            $kodA === 200 && $po !== $przed && $odtw === $przed, json_encode(['kod' => $kodA, 'odp' => $odpA['blad'] ?? null, 'przed' => $przed, 'po' => $po, 'odtw' => $odtw], JSON_UNESCAPED_UNICODE));
    };
    $bonRT = static function (int $zl) use ($k, $pdo): string {
        $p = bon_test_payload(['price_custom_id' => 'bon-' . $zl, 'amount_paid' => $zl]);
        bon_test_webhook($k, $pdo, $p);
        return (string)bon_test_bony($k, $pdo, $p['order_id'])[0]['code'];
    };
    $admin = static fn(string $kod, array $dane) => static fn(DateTimeImmutable $c) => bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kod] + $dane, $c);
    $rt1 = $bonRT(300);
    $wObie('zmiana daty (waznosc, z powodem)', $rt1, $admin($rt1, ['akcja' => 'waznosc', 'data' => bon_teraz()->modify('+2 years')->format('Y-m-d'), 'powod' => 'test raportu']));
    $wObie('realizacja z panelu', $rt1, static fn($c) => bon_panel_realizuj($k, $pdo, $sKasa, ['kod' => $rt1, 'kwota_reczna' => '70', 'client_token' => bin2hex(random_bytes(12))], $c));
    $wObie('Oznacz zwrot bonu ważnego (admin, „valid → zwrócony”)', $rt1, $admin($rt1, ['akcja' => 'zwrot', 'powod' => 'test raportu']));
    $rt2 = $bonRT(200);
    $pdo->prepare("UPDATE $tabB SET expires_at = ? WHERE code = ?")->execute([bon_teraz()->modify('+10 days')->format('Y-m-d'), $rt2]);
    $wObie('przedłużenie o 3 mies. (okno 30 dni)', $rt2, static fn($c) => bon_panel_przedluz($k, $pdo, $sEwa, $rt2, $c));
    $wObie('rezygnacja w panelu (mail)', $rt2, $admin($rt2, ['akcja' => 'rezygnacja', 'kanal' => 'mail', 'data' => bon_teraz()->format('Y-m-d')]));
    $wObie('cofnięcie rezygnacji', $rt2, $admin($rt2, ['akcja' => 'cofnij-rezygnacje', 'powod' => 'test raportu']));
    $wObie('rezygnacja ponownie', $rt2, $admin($rt2, ['akcja' => 'rezygnacja', 'kanal' => 'mail', 'data' => bon_teraz()->format('Y-m-d')]));
    $wObie('Oznacz zwrot przy rezygnacji („withdrawn → zwrócony”)', $rt2, $admin($rt2, ['akcja' => 'zwrot']));
    $rt3 = $bonRT(200);
    $pdo->prepare("UPDATE $tabB SET status = 'held', status_uwagi = 'test raportu' WHERE code = ?")->execute([$rt3]);
    $wObie('zatwierdzenie bonu wstrzymanego', $rt3, $admin($rt3, ['akcja' => 'zatwierdz']));

    // Strażnik źródeł: saldo na koniec okresu jest dobre, dopóki KAŻDA zmiana statusu, daty ważności i salda bonu ma zdarzenie,
    // które bon_raport_stan_na umie cofnąć. Nowy UPDATE tych pól w kodzie PHP = ten test czerwony → dopisz obsługę zdarzenia.
    $znane = ['lib/bon.php' => 1, 'lib/panel.php' => 7, 'lib/rezygnacja.php' => 1, 'lib/zwroty.php' => 1];
    // bon.php: przedłużenie; panel.php: realizacja, waznosc, zatwierdz, zwrot, rezygnacja-cofnieta, cofnij-realizacje, rezygnacja;
    // rezygnacja.php: rezygnacja ze strony; zwroty.php: zwrot-potwierdzony
    $katalog = dirname(__DIR__);
    $znalezione = [];
    foreach (array_merge(glob($katalog . '/*.php'), glob($katalog . '/lib/*.php')) as $plik) {
        if (preg_match('#/testy[^/]*\.php$#', $plik)) continue;
        preg_match_all('/UPDATE\b[^;]*?\bSET\b(.*?)(?:\bWHERE\b|["\']\s*\))/s', (string)file_get_contents($plik), $m);
        $n = count(array_filter($m[1], static fn($set) => preg_match('/\b(status|expires_at|balance_pln)\s*=/', $set) === 1));
        if ($n) $znalezione[substr($plik, strlen($katalog) + 1)] = $n;
    }
    ksort($znalezione);
    $t('saldo strażnik: zmiany status / expires_at / balance_pln bonu tylko w znanych miejscach (każde z wpisem w historii)',
        $znalezione === $znane, 'nowe miejsce zmiany statusu/ważności/salda bonu – dopisz obsługę w bon_raport_stan_na i liczbę w $znane; znalezione: '
        . json_encode($znalezione) . ', znane: ' . json_encode($znane));
    // --- uprawnienia ---
    [$kK, $oK] = bon_raport($k, $pdo, $sKasa, []);
    [$kE] = bon_raport($k, $pdo, $sEwa, []);
    [$kA] = bon_raport($k, $pdo, $sAdmin, []);
    $t('raport uprawnienia: poziom Bon (Karolina) → 403 bez kwoty; Gabinet (Ewa) i admin → 200',
        $kK === 403 && !isset($oK['zrealizowano']) && !isset($oK['lista']) && !isset($oK['lista_wszystkich']) && !isset($oK['saldo']) &&$kE === 200 && $kA === 200, json_encode($oK, JSON_UNESCAPED_UNICODE));
    [$kZ] = bon_raport($k, $pdo, $sKasa, ['okres' => 'kwartal']);
    $t('raport uprawnienia: poziom Bon ze złym okresem dalej 403 (uprawnienia przed walidacją)', $kZ === 403);
    return $wyniki;
}
