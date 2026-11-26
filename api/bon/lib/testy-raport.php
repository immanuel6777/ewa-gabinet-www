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
    $dodaj = static function (string $kiedy, string $kwota) use ($pdo, $tabR, $bon): int {
        $pdo->prepare("INSERT INTO $tabR (voucher_id, amount_pln, doplata_pln, typ, pozycje, notatka, redeemed_at, redeemed_by, client_token)
                       VALUES (?, ?, '0.00', 'reczna', '[]', NULL, ?, 'test-raport', ?)")->execute([$bon['id'], $kwota, $kiedy, bin2hex(random_bytes(12))]);
        return (int)$pdo->lastInsertId();
    };
    $dodaj('2031-03-30 23:59:59', '5.00');   // niedziela przed tygodniem 31.03
    $dodaj('2031-03-31 23:59:59', '50.00');  // ostatnia sekunda marca
    $dodaj('2031-04-01 00:00:00', '30.00');  // pierwsza sekunda kwietnia
    $cof = $dodaj('2031-04-15 12:00:00', '100.00');
    bon_historia($k, $pdo, (int)$bon['id'], 'realizacja-cofnieta', "realizacja #$cof: −100.00 zł (kwota ręczna, 2031-04-15 12:00), saldo 0.00 → 100.00 zł", 'ewa', $chwila('2031-04-16 09:00:00'));
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

    // --- uprawnienia ---
    [$kK, $oK] = bon_raport($k, $pdo, $sKasa, []);
    [$kE] = bon_raport($k, $pdo, $sEwa, []);
    [$kA] = bon_raport($k, $pdo, $sAdmin, []);
    $t('raport uprawnienia: poziom Bon (Karolina) → 403 bez kwoty; Gabinet (Ewa) i admin → 200',
        $kK === 403 && !isset($oK['zrealizowano']) && $kE === 200 && $kA === 200, json_encode($oK, JSON_UNESCAPED_UNICODE));
    [$kZ] = bon_raport($k, $pdo, $sKasa, ['okres' => 'kwartal']);
    $t('raport uprawnienia: poziom Bon ze złym okresem dalej 403 (uprawnienia przed walidacją)', $kZ === 403);
    return $wyniki;
}
