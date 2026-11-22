<?php
// Scenariusze testowe panelu realizacji (etap 3: 2026-11-21) – logowanie, realizacja z saldem, historia, admin.
// Wołane z bon_testy() w lib/testy.php: te same na SQLite (npm run test:bony) i na OVH (test-ovh.php). Nic nie wypisuje.

declare(strict_types=1);

function bon_testy_panel(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $tab = bon_tabela($k, 'vouchers');
    $ip = '203.0.113.' . random_int(1, 120);
    $tok = static fn() => bin2hex(random_bytes(12));
    $nowyBon = static function (int $zl, array $nadpisz = []) use ($k, $pdo): string {
        $p = bon_test_payload(array_replace(['price_custom_id' => "bon-$zl", 'amount_paid' => $zl], $nadpisz));
        bon_test_webhook($k, $pdo, $p);
        return (string)(bon_test_bony($k, $pdo, $p['order_id'])[0]['code'] ?? '');
    };
    $saldo = static function (string $kod) use ($pdo, $tab): array {
        $st = $pdo->prepare("SELECT balance_pln, status FROM $tab WHERE code = ?");
        $st->execute([$kod]);
        $r = $st->fetch();
        return $r ? [bon_zl((int)bon_grosze($r['balance_pln'])), (string)$r['status']] : ['', ''];
    };

    // --- logowanie ---
    [$kod, $odp] = bon_panel_zaloguj($k, $pdo, 'ewa', 'zle-haslo', false, $ip);
    $t('panel: złe hasło → 401', $kod === 401, json_encode($odp));
    [$kod] = bon_panel_zaloguj($k, $pdo, 'nieznany', 'cokolwiek', false, $ip);
    $t('panel: nieznany login → 401 (ten sam komunikat)', $kod === 401);
    [$kod, $odp, $ciastko] = bon_panel_zaloguj($k, $pdo, ' EWA ', 'haslo-ewy-test', false, $ip) + [2 => null];
    $t('panel: dobre hasło (login bez wielkości liter) → 200, ciasteczko sesyjne, csrf', $kod === 200 && $ciastko && $ciastko['wygasa'] === 0
        && strlen($odp['csrf'] ?? '') === 64 && ($odp['rola'] ?? '') === 'realizacja', json_encode($odp));
    $tokEwy = (string)($ciastko['wartosc'] ?? '');
    $sEwa = bon_panel_sesja($k, $pdo, $tokEwy);
    $t('panel: sesja z ciasteczka → login ewa, ten sam csrf', $sEwa && $sEwa['login'] === 'ewa' && $sEwa['csrf'] === ($odp['csrf'] ?? ''));
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . bon_tabela($k, 'voucher_sessions') . ' WHERE token_hash = ?');
    $st->execute([$tokEwy]);
    $t('panel: w bazie tylko skrót tokenu, nie sam token', (int)$st->fetchColumn() === 0);
    $t('panel: zły / zmyślony token → brak sesji', bon_panel_sesja($k, $pdo, str_repeat('a', 64)) === null && bon_panel_sesja($k, $pdo, "x' OR 1=1") === null);
    $t('panel: sesja po 13 h (bez „zapamiętaj”) wygasła', bon_panel_sesja($k, $pdo, $tokEwy, bon_teraz()->modify('+13 hours')) === null);

    [, , $c90] = bon_panel_zaloguj($k, $pdo, 'bartek', 'haslo-bartka-test', true, $ip) + [2 => null];
    $tokB = (string)($c90['wartosc'] ?? '');
    $sB = bon_panel_sesja($k, $pdo, $tokB);
    $t('panel: „zapamiętaj” → ciasteczko na ok. 90 dni, sesja żyje po 80 dniach, rola admin', $c90 && $c90['wygasa'] > time() + 89 * 86400
        && bon_panel_sesja($k, $pdo, $tokB, bon_teraz()->modify('+80 days')) !== null && $sB && $sB['rola'] === 'admin');

    $kBez = $k;
    unset($kBez['panel_konta']['ewa']);
    $t('panel: konto skreślone z konfigu → sesja przestaje działać', bon_panel_sesja($kBez, $pdo, $tokEwy) === null);

    $ip2 = '203.0.113.' . random_int(121, 250);
    for ($i = 0; $i < 5; $i++) bon_panel_zaloguj($k, $pdo, 'ewa', 'zle', false, $ip2);
    [$kod] = bon_panel_zaloguj($k, $pdo, 'ewa', 'haslo-ewy-test', false, $ip2);
    $t('panel: po 5 złych próbach → 429 nawet z dobrym hasłem', $kod === 429, (string)$kod);
    [$kod] = bon_panel_zaloguj($k, $pdo, 'bartek', 'haslo-bartka-test', false, $ip2);
    $t('panel: limit na login+IP nie blokuje innego konta z tego IP', $kod === 200, (string)$kod);

    [, , $cTmp] = bon_panel_zaloguj($k, $pdo, 'ewa', 'haslo-ewy-test', false, $ip) + [2 => null];
    bon_panel_wyloguj($k, $pdo, (string)($cTmp['wartosc'] ?? ''));
    $t('panel: wylogowanie usuwa sesję', $cTmp && bon_panel_sesja($k, $pdo, $cTmp['wartosc']) === null);

    if (!$sEwa || !$sB) return $wyniki; // bez sesji dalsze testy nie mają sensu

    // --- realizacja ---
    $b300 = $nowyBon(300);
    $tokA = $tok();
    [$kod, $odp] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b300, 'pozycje' => [['id' => 'pedicure~0', 'cena' => 1]], 'client_token' => $tokA]);
    $t('realizacja częściowa: 180 z 300 → saldo 120, do kasy 180, cena z przeglądarki zignorowana', $kod === 200 && $odp['zdjeto'] === '180.00'
        && $odp['saldo'] === '120.00' && $odp['doplata'] === '0.00' && $odp['do_kasy'] === '180.00' && $saldo($b300) === ['120.00', 'valid'], json_encode($odp));
    [$kod, $odp] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b300, 'pozycje' => [['id' => 'pedicure~0']], 'client_token' => $tokA]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . bon_tabela($k, 'redemptions') . " r JOIN $tab v ON v.id = r.voucher_id WHERE v.code = ?");
    $st->execute([$b300]);
    $t('podwójne kliknięcie (ten sam token): 200 „powtorzona”, saldo nadal 120, jedna realizacja', $kod === 200 && ($odp['powtorzona'] ?? false)
        && $saldo($b300)[0] === '120.00' && (int)$st->fetchColumn() === 1, json_encode($odp));
    $bInny = $nowyBon(200);
    [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $bInny, 'pozycje' => [['id' => 'pedicure~0']], 'client_token' => $tokA]);
    $t('token z innego bonu → 409, bez zmian', $kod === 409 && $saldo($bInny)[0] === '200.00');

    [$kod, $odp] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => strtolower($b300), 'pozycje' => [['id' => 'pedicure~0']], 'client_token' => $tok()]);
    $t('dopłata: 180 przy saldzie 120 → zdjęto 120, dopłata 60, saldo 0, used', $kod === 200 && $odp['zdjeto'] === '120.00' && $odp['doplata'] === '60.00'
        && $odp['saldo'] === '0.00' && $saldo($b300) === ['0.00', 'used'], json_encode($odp));
    [$kod, $odp] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b300, 'kwota_reczna' => '10', 'client_token' => $tok()]);
    $t('bon wykorzystany → 409 used', $kod === 409 && ($odp['status'] ?? '') === 'used');

    $b500 = $nowyBon(500);
    [$kod, $odp] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'kwota_reczna' => '50,50', 'notatka' => 'korekta', 'client_token' => $tok()]);
    $t('kwota ręczna „50,50” → zdjęto 50.50, saldo 449.50', $kod === 200 && $odp['zdjeto'] === '50.50' && $saldo($b500)[0] === '449.50', json_encode($odp));
    [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'pozycje' => [['id' => 'wkladka~0', 'cena' => '300']], 'client_token' => $tok()]);
    $t('pozycja „od–do” (180–250) z ceną 300 → 400', $kod === 400);
    [$kod, $odp] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'pozycje' => [['id' => 'wkladka~0', 'cena' => '220']], 'client_token' => $tok()]);
    $t('pozycja „od–do” z ceną 220 → zdjęto 220', $kod === 200 && $odp['zdjeto'] === '220.00', json_encode($odp));
    [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'pozycje' => [['id' => 'klamra~0', 'cena' => '100']], 'client_token' => $tok()]);
    $t('pozycja „od 180” z ceną 100 → 400', $kod === 400);
    $przedSaldo = $saldo($b500)[0];
    $zle = 0;
    foreach (['1 200', '250,505', '1e3', 'abc'] as $zlaCena) {
        [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'pozycje' => [['id' => 'klamra~0', 'cena' => $zlaCena]], 'client_token' => $tok()]);
        if ($kod === 400) $zle++;
    }
    $t('pozycja „od” z nieczytelną ceną („1 200”, „250,505”, „1e3”, „abc”) → 400, saldo bez zmian', $zle === 4 && $saldo($b500)[0] === $przedSaldo);
    [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'pozycje' => [['id' => 'nie-ma~9']], 'client_token' => $tok()]);
    $t('nieznany zabieg → 400', $kod === 400);
    [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'client_token' => $tok()]);
    $t('nic nie wybrano → 400', $kod === 400);
    [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'kwota_reczna' => '-5', 'client_token' => $tok()]);
    $t('kwota ręczna ujemna → 400', $kod === 400);
    [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'kwota_reczna' => '9000', 'client_token' => $tok()]);
    $t('kwota ponad 5000 zł → 400', $kod === 400);
    [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'kwota_reczna' => '10', 'client_token' => 'krotki']);
    $t('brak / zły client_token → 400', $kod === 400);
    [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => bon_nowy_kod(), 'kwota_reczna' => '10', 'client_token' => $tok()]);
    $t('nieistniejący bon → 404', $kod === 404);

    // drugie urządzenie zmienia saldo między odczytem a zapisem (na MySQL dodatkowo chroni SELECT … FOR UPDATE)
    $przed = $saldo($b500)[0];
    [$kod] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b500, 'kwota_reczna' => '100', 'client_token' => $tok()], null,
        static function (PDO $p, array $bon) use ($tab) { $p->prepare("UPDATE $tab SET balance_pln = '1.00' WHERE id = ?")->execute([$bon['id']]); });
    $t('zmiana salda w trakcie (inne urządzenie) → 409, nic nie schodzi', $kod === 409 && $saldo($b500)[0] === $przed, $kod . ' ' . json_encode($saldo($b500)));

    $bWyg = $nowyBon(200);
    $pdo->prepare("UPDATE $tab SET expires_at = ? WHERE code = ?")->execute([bon_teraz()->modify('-1 day')->format('Y-m-d'), $bWyg]);
    [$kod, $odp] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $bWyg, 'kwota_reczna' => '10', 'client_token' => $tok()]);
    $t('bon wygasły → 409 expired', $kod === 409 && ($odp['status'] ?? '') === 'expired');
    $bHeld = $nowyBon(300, ['amount_paid' => 100]);
    [$kod, $odp] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $bHeld, 'kwota_reczna' => '10', 'client_token' => $tok()]);
    $t('bon wstrzymany → 409 held', $kod === 409 && ($odp['status'] ?? '') === 'held');

    // --- historia ---
    [$kod, $odp] = bon_panel_historia($k, $pdo, $sEwa, $b500);
    $t('historia: saldo, 2 realizacje, kto = imię, bez danych admina i kupującego', $kod === 200 && count($odp['historia']) === 2 && $odp['historia'][0]['kto'] === 'Ewa'
        && !isset($odp['uwagi']) && !isset($odp['zamowienie']) && !str_contains(json_encode($odp), 'example.com'), json_encode($odp));
    [$kod, $odp] = bon_panel_historia($k, $pdo, $sB, $bHeld);
    $t('historia (admin): status_bazy held i powód wstrzymania', $kod === 200 && ($odp['status_bazy'] ?? '') === 'held' && (string)($odp['uwagi'] ?? '') !== '');
    [$kod] = bon_panel_historia($k, $pdo, $sEwa, 'abc');
    $t('historia: zły numer → 400', $kod === 400);

    // --- admin ---
    [$kod] = bon_panel_admin($k, $pdo, $sEwa, ['kod' => $bWyg, 'akcja' => 'waznosc', 'data' => '2099-01-01']);
    $t('admin: konto bez roli admin → 403', $kod === 403);
    $nowa = bon_teraz()->modify('+30 days')->format('Y-m-d');
    [$kod] = bon_panel_admin($k, $pdo, $sB, ['kod' => $bWyg, 'akcja' => 'waznosc', 'data' => $nowa, 'powod' => 'zgoda Ewy']);
    [, $h] = bon_panel_historia($k, $pdo, $sB, $bWyg);
    $t('admin: przedłużenie wygasłego → znów ważny, wpis w dzienniku (kto, co)', $kod === 200 && $h['status'] === 'valid' && $h['wazny_do'] === $nowa
        && ($h['dziennik'][0]['kto'] ?? '') === 'bartek' && str_contains((string)($h['dziennik'][0]['szczegoly'] ?? ''), 'zgoda Ewy'), json_encode($h['dziennik'] ?? []));
    [$kod] = bon_panel_admin($k, $pdo, $sB, ['kod' => $bWyg, 'akcja' => 'waznosc', 'data' => '2020-01-01']);
    $t('admin: data w przeszłości → 400', $kod === 400);
    [$kod] = bon_panel_admin($k, $pdo, $sB, ['kod' => $bWyg, 'akcja' => 'waznosc', 'data' => '2027-02-30']);
    $t('admin: nieistniejąca data → 400', $kod === 400);
    [$kod, , $doMaila] = bon_panel_admin($k, $pdo, $sB, ['kod' => $bHeld, 'akcja' => 'zatwierdz']) + [2 => null];
    $t('admin: zatwierdzenie wstrzymanego → valid i zamówienie do wysłania PDF', $kod === 200 && $saldo($bHeld)[1] === 'valid' && is_string($doMaila) && $doMaila !== '');
    [$kod] = bon_panel_admin($k, $pdo, $sB, ['kod' => $bHeld, 'akcja' => 'zatwierdz']);
    $t('admin: zatwierdzenie bonu, który nie jest wstrzymany → 409', $kod === 409);
    [$kod] = bon_panel_admin($k, $pdo, $sB, ['kod' => $bInny, 'akcja' => 'zwrot', 'powod' => 'odstąpienie 14 dni']);
    [$kodR, $odpR] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $bInny, 'kwota_reczna' => '10', 'client_token' => $tok()]);
    $t('admin: zwrot → refunded, realizacja odrzucona', $kod === 200 && $saldo($bInny)[1] === 'refunded' && $kodR === 409 && ($odpR['status'] ?? '') === 'refunded');
    [$kod] = bon_panel_admin($k, $pdo, $sB, ['kod' => $bInny, 'akcja' => 'kasuj']);
    $t('admin: nieznana akcja → 400', $kod === 400);

    return $wyniki;
}
