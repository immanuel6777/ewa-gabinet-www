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
        && ($h['zdarzenia'][0]['kto'] ?? '') === 'bartek' && str_contains((string)($h['zdarzenia'][0]['szczegoly'] ?? ''), 'zgoda Ewy'), json_encode($h['zdarzenia'] ?? []));
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

    return array_merge($wyniki, bon_testy_kwoty_kasy($k, $pdo));
}

/**
 * Wpis 19:33 – ZASADA KWOT DLA KASY (Karolina), rola × stan. Kasa: tylko kwoty do realizacji (bon ważny: saldo i wartość),
 * bon nieaktywny bez kwot; nigdy „zapłacono”, „rabat”, „zwrot”, kwota zapłacona – w całej odpowiedzi JSON (widok bonu i karty).
 * Ewa i admin: wszystkie kwoty jak dotąd.
 */
function bon_testy_kwoty_kasy(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    if (!function_exists('bon_panel_zarejestruj_rezygnacje') && is_file(__DIR__ . '/rezygnacja.php')) require_once __DIR__ . '/rezygnacja.php';
    $tab = bon_tabela($k, 'vouchers');
    $tabW = bon_tabela($k, 'voucher_withdrawals');
    $sKasa = ['login' => 'karolina', 'imie' => 'Karolina', 'rola' => 'realizacja', 'csrf' => 'x'];
    $sEwa = ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'rezygnacje' => true, 'csrf' => 'x'];
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    $email = 'kwoty-' . random_int(10000, 99999) . '@example.com';
    $dzis = bon_teraz()->format('Y-m-d');
    $nowy = static function (int $zl, ?string $zaplacono = null, ?string $promo = null) use ($k, $pdo, $tab, $email): array {
        $p = bon_test_payload(['price_custom_id' => "bon-$zl", 'amount_paid' => $zl, 'customer_email' => $email]);
        bon_test_webhook($k, $pdo, $p);
        $b = bon_test_bony($k, $pdo, $p['order_id'])[0];
        if ($zaplacono !== null) $pdo->prepare("UPDATE $tab SET paid_pln = ?, promo_code = ? WHERE id = ?")->execute([$zaplacono, $promo, $b['id']]);
        return $b;
    };
    $ustaw = static fn(int $id, string $sql, array $w = []) => $pdo->prepare("UPDATE $tab SET $sql WHERE id = ?")->execute([...$w, $id]);
    $rez = static fn(string $kod) => bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kod, 'akcja' => 'rezygnacja', 'kanal' => 'mail', 'data' => $dzis, 'otrzymano' => $dzis]);

    $stany = [];
    $stany['aktywny pełny'] = [$nowy(200), true];
    $b = $nowy(300);
    bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b['code'], 'kwota_reczna' => '100', 'client_token' => bin2hex(random_bytes(12))]);
    $stany['aktywny częściowo'] = [$b, true];
    $stany['aktywny z rabatem (8 zł)'] = [$nowy(200, '8.00', 'KWOTY96'), true];
    $b = $nowy(300, '100.00');
    $ustaw((int)$b['id'], "status = 'held'");
    $stany['wstrzymany'] = [$b, false];
    $b = $nowy(200, '8.00', 'KWOTY96');
    $rez($b['code']);
    $stany['rezygnacja'] = [$b, false];
    $b = $nowy(200, '8.00', 'KWOTY96');
    $rez($b['code']);
    $pdo->prepare("UPDATE $tabW SET zwrot_stan = 'zlecony', zwrot_kwota_pln = '8.00', zwrot_zlecono_at = ? WHERE voucher_id = ?")->execute([bon_teraz()->format('Y-m-d H:i:s'), $b['id']]);
    bon_historia($k, $pdo, (int)$b['id'], 'zwrot-zlecony', 'kwota 8.00 zł', 'ewa');
    $stany['zwrot w toku'] = [$b, false];
    $b = $nowy(200, '8.00', 'KWOTY96');
    $rez($b['code']);
    bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $b['code'], 'akcja' => 'zwrot', 'powod' => 'test']);
    $stany['zwrócony'] = [$b, false];
    $b = $nowy(200);
    $ustaw((int)$b['id'], 'expires_at = ?', [bon_teraz()->modify('-3 days')->format('Y-m-d')]);
    $stany['wygasły'] = [$b, false];
    $b = $nowy(200);
    $ustaw((int)$b['id'], 'przedluzony_at = ?, expires_at_pierwotna = ?', [bon_teraz()->format('Y-m-d H:i:s'), bon_teraz()->modify('-1 day')->format('Y-m-d')]);
    $stany['przedłużony'] = [$b, true];

    $zakazane = '/zap(ł|l)acono|rabat|zwrot|zwrócon|"8\.00"|KWOTY96/iu';
    $zleKasa = [];
    $zleEwa = [];
    foreach ($stany as $nazwa => [$bon, $aktywny]) {
        [$h, $o] = bon_panel_historia($k, $pdo, $sKasa, (string)$bon['code']);
        $json = json_encode($o, JSON_UNESCAPED_UNICODE);
        if ($h !== 200) { $zleKasa[] = "$nazwa: HTTP $h"; continue; }
        if (preg_match($zakazane, $json, $m)) $zleKasa[] = "$nazwa: w odpowiedzi „{$m[0]}”";
        if ($aktywny && ($o['saldo'] === null || $o['wartosc'] === null)) $zleKasa[] = "$nazwa: brak salda przy bonie ważnym";
        if (!$aktywny && ($o['saldo'] !== null || $o['wartosc'] !== null || empty($o['nieaktywny']))) $zleKasa[] = "$nazwa: kwota przy bonie nieaktywnym";
        [, $oE] = bon_panel_historia($k, $pdo, $sEwa, (string)$bon['code']);
        if ($oE['saldo'] === null || $oE['wartosc'] === null) $zleEwa[] = "$nazwa: Ewa bez salda";
        if (str_contains($nazwa, 'rabatem') && ($oE['rabat']['zaplacono'] ?? '') !== '8.00') $zleEwa[] = "$nazwa: Ewa bez rabatu";
        if (in_array($nazwa, ['rezygnacja', 'zwrot w toku'], true) && ($oE['rezygnacja']['kwota'] ?? '') !== '8.00') $zleEwa[] = "$nazwa: Ewa bez kwoty zwrotu";
    }
    $t('kwoty kasy (19:33): ' . count($stany) . ' stanów – bon ważny z saldem i wartością, nieaktywny bez kwot, nigdzie zapłacono/rabat/zwrot/8.00',
        !$zleKasa, implode('; ', $zleKasa));
    $t('kwoty Ewy (19:33): te same stany – saldo zawsze, rabat i kwota zwrotu tam, gdzie są', !$zleEwa, implode('; ', $zleEwa));

    // karty wyszukiwania po e-mailu (to samo dla kasy)
    [, $sk] = bon_panel_szukaj($k, $pdo, $email, null, $sKasa);
    $jsonS = json_encode($sk, JSON_UNESCAPED_UNICODE);
    $karty = $sk['bony'] ?? [];
    $nieakt = array_filter($karty, static fn($c) => $c['status'] !== 'valid');
    $t('kwoty kasy w kartach wyszukiwania: ' . count($karty) . ' kart, nieaktywne bez kwot, nigdzie zapłacono/rabat/zwrot/8.00',
        count($karty) === count($stany) && !preg_match($zakazane, $jsonS)
        && !array_filter($nieakt, static fn($c) => $c['saldo'] !== null || $c['wartosc'] !== null)
        && !array_filter($karty, static fn($c) => $c['status'] === 'valid' && $c['saldo'] === null), $jsonS);
    [, $skA] = bon_panel_szukaj($k, $pdo, $email, null, $sAdmin);
    $t('karty admina: kwoty przy wszystkich, rabat przy bonie z kodem', !array_filter($skA['bony'] ?? [], static fn($c) => $c['saldo'] === null)
        && (bool)array_filter($skA['bony'] ?? [], static fn($c) => ($c['rabat']['kod'] ?? '') === 'KWOTY96'));

    // --- Z3 (REVIEW-SECURITY F1/F5, REVIEW 13): limity logowania atomowe i na sam login ---
    // równoległe żądania: każde kolejne startuje, gdy poprzednie jest w trakcie sprawdzania hasła (porażka jeszcze niezapisana);
    // przez limit login+IP (5) może przejść najwyżej 5 z 30
    $loginR = 'z3-rownolegle-' . random_int(1000, 9999);
    $ipR = '198.51.100.' . random_int(1, 250);
    $doszlo = 0;
    $kR = $k;
    $kR['_test_przed_haslem'] = static function () use (&$kR, $pdo, $loginR, $ipR, &$doszlo): void {
        if (++$doszlo < 30) bon_panel_zaloguj($kR, $pdo, $loginR, 'zle', false, $ipR);
    };
    bon_panel_zaloguj($kR, $pdo, $loginR, 'zle', false, $ipR);
    $t('Z3: 30 „równoległych” prób na ten sam login i IP → do sprawdzenia hasła dochodzi najwyżej 5', $doszlo === 5, (string)$doszlo);

    // sam login z różnych adresów: 10 pomyłek z 10 IP → 11. z nowego IP 429; alert na alerty@ dokładnie raz
    $plikPow = $k['_katalog'] . '/bony-powiadomienia.txt';
    $loginL = 'z3-konto-' . random_int(1000, 9999);
    $powPrzed = strlen((string)@file_get_contents($plikPow));
    $kody = [];
    for ($i = 1; $i <= 13; $i++) [$kody[]] = bon_panel_zaloguj($k, $pdo, $loginL, 'zle', false, '192.0.2.' . $i);
    $pow = substr((string)@file_get_contents($plikPow), $powPrzed);
    $t('Z3: 10 pomyłek na jeden login z 10 różnych IP → kolejne 429 (każde z nowego IP), jeden alert z nazwą konta',
        array_slice($kody, 0, 10) === array_fill(0, 10, 401) && array_slice($kody, 10) === [429, 429, 429]
        && substr_count($pow, 'zablokowane logowanie do panelu – konto ' . $loginL) === 1, json_encode($kody));

    // IPv6: adresy z jednej sieci /64 liczone razem (5 pomyłek na login+sieć → 429 z innego adresu tej sieci)
    $loginV = 'z3-ipv6-' . random_int(1000, 9999);
    for ($i = 1; $i <= 5; $i++) bon_panel_zaloguj($k, $pdo, $loginV, 'zle', false, '2001:db8:1:2::' . dechex($i));
    [$kV] = bon_panel_zaloguj($k, $pdo, $loginV, 'zle', false, '2001:db8:1:2:ffff::9');
    [$kV2] = bon_panel_zaloguj($k, $pdo, $loginV, 'zle', false, '2001:db8:1:3::1');
    $t('Z3: IPv6 – ta sama sieć /64 dzieli limit (429), inna sieć nie (401)', $kV === 429 && $kV2 === 401 && bon_ip_grupa('2001:db8:1:2::a') === bon_ip_grupa('2001:db8:1:2:ffff::9'),
        json_encode([$kV, $kV2]));

    // udane logowanie oddaje rezerwację: 25 udanych logowań z jednego IP nie wyczerpuje limitu 20 na IP
    $ipU = '198.51.100.' . random_int(1, 250);
    $udane = 0;
    for ($i = 0; $i < 25; $i++) {
        [$kU, , $cU] = bon_panel_zaloguj($k, $pdo, 'bartek', 'haslo-bartka-test', false, $ipU) + [2 => null];
        if ($kU === 200) { $udane++; bon_panel_wyloguj($k, $pdo, (string)($cU['wartosc'] ?? '')); }
    }
    $t('Z3: 25 udanych logowań z jednego IP → wszystkie 200 (udane nie zużywają limitu)', $udane === 25, (string)$udane);

    // --- karta #195: akcje wg stanu bonu (Zmień datę, Oznacz zwrot + flagi w historii) ---
    $nowyBon = static function (int $zl, array $nadpisz = []) use ($k, $pdo): string {
        $p = bon_test_payload(array_replace(['price_custom_id' => "bon-$zl", 'amount_paid' => $zl], $nadpisz));
        bon_test_webhook($k, $pdo, $p);
        return (string)(bon_test_bony($k, $pdo, $p['order_id'])[0]['code'] ?? '');
    };
    $stan = static function (string $kod, string $status, ?string $wygasa = null) use ($pdo, $tab): void {
        $pdo->prepare("UPDATE $tab SET status = ?" . ($wygasa ? ', expires_at = ?' : '') . ' WHERE code = ?')
            ->execute($wygasa ? [$status, $wygasa, $kod] : [$status, $kod]);
    };
    $data = bon_teraz()->modify('+60 days')->format('Y-m-d');
    $zmien = static fn(string $kod) => bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kod, 'akcja' => 'waznosc', 'data' => $data, 'powod' => 'test #195'])[0];
    $oznacz = static fn(array $s, string $kod) => bon_panel_admin($k, $pdo, $s, ['kod' => $kod, 'akcja' => 'zwrot', 'powod' => 'test #195'])[0];
    $akcje = static fn(array $s, string $kod) => bon_panel_historia($k, $pdo, $s, $kod)[1]['akcje'] ?? null;
    $bV = $nowyBon(200); $bU = $nowyBon(200); $bW = $nowyBon(200); $bR = $nowyBon(200); $bE = $nowyBon(200); $bH = $nowyBon(300, ['amount_paid' => 100]);
    $stan($bU, 'used'); $stan($bW, 'withdrawn'); $stan($bR, 'refunded');
    $stan($bE, 'valid', bon_teraz()->modify('-3 days')->format('Y-m-d'));
    $kody = ['ważny' => $zmien($bV), 'wstrzymany' => $zmien($bH), 'wykorzystany' => $zmien($bU), 'rezygnacja' => $zmien($bW), 'zwrócony' => $zmien($bR)];
    $t('#195: „Zmień datę” – ważny i wstrzymany 200; wykorzystany, rezygnacja, zwrócony 409',
        $kody === ['ważny' => 200, 'wstrzymany' => 200, 'wykorzystany' => 409, 'rezygnacja' => 409, 'zwrócony' => 409], json_encode($kody, JSON_UNESCAPED_UNICODE));
    $a = ['admin ważny' => $akcje($sAdmin, $bV), 'admin wykorzystany' => $akcje($sAdmin, $bU), 'admin rezygnacja' => $akcje($sAdmin, $bW),
          'admin po terminie' => $akcje($sAdmin, $bE), 'Ewa ważny' => $akcje($sEwa, $bV), 'Ewa rezygnacja' => $akcje($sEwa, $bW)];
    $t('#195: flagi akcji w historii zgodne z tabelą (admin / Ewa × ważny, wykorzystany, rezygnacja, po terminie)',
        $a['admin ważny'] === ['zmien_date' => true, 'oznacz_zwrot' => true]
        && $a['admin wykorzystany'] === ['zmien_date' => false, 'oznacz_zwrot' => false]
        && $a['admin rezygnacja'] === ['zmien_date' => false, 'oznacz_zwrot' => true]
        && $a['admin po terminie'] === ['zmien_date' => true, 'oznacz_zwrot' => false]
        && $a['Ewa ważny'] === ['zmien_date' => false, 'oznacz_zwrot' => false]
        && $a['Ewa rezygnacja'] === ['zmien_date' => false, 'oznacz_zwrot' => true], json_encode($a, JSON_UNESCAPED_UNICODE));
    $kody = ['wykorzystany' => $oznacz($sAdmin, $bU), 'po terminie' => $oznacz($sAdmin, $bE), 'Ewa rezygnacja' => $oznacz($sEwa, $bW), 'admin wstrzymany' => $oznacz($sAdmin, $bH)];
    $t('#195: „Oznacz zwrot” – wykorzystany i po terminie 409; rezygnacja (Ewa) i wstrzymany (admin) 200',
        $kody === ['wykorzystany' => 409, 'po terminie' => 409, 'Ewa rezygnacja' => 200, 'admin wstrzymany' => 200], json_encode($kody, JSON_UNESCAPED_UNICODE));
    $t('#195: Karolina (kasa) nie dostaje flag akcji', !array_key_exists('akcje', bon_panel_historia($k, $pdo, $sKasa, $bV)[1]));

    // --- karta #201: szukanie po fragmencie e-maila / id zamówienia (admin i Ewa; nie Karolina) ---
    $bF = $nowy(200);
    $fr = static fn(?array $s, string $q) => bon_panel_szukaj($k, $pdo, $q, null, $s, true);
    $czesc = substr($email, 2, 8); // środek adresu kupującego (kwoty-NNNNN@example.com)
    [$hK] = $fr($sKasa, $czesc);
    [$h2] = $fr($sEwa, 'kw');
    [$hE, $oE] = $fr($sEwa, strtoupper($czesc));
    $tenBon = array_values(array_filter($oE['bony'] ?? [], static fn($c) => ($c['email'] ?? '') === $email));
    $t('#201: fragment – Karolina 403, 2 znaki 400; Ewa po fragmencie adresu (bez wielkości liter) znajduje bony z polem „email”, numer zamaskowany',
        $hK === 403 && $h2 === 400 && $hE === 200 && ($oE['typ'] ?? '') === 'fragment' && $tenBon && array_key_exists('kod', $tenBon[0]) && $tenBon[0]['kod'] === null
        && !empty($tenBon[0]['uchwyt']), json_encode([$hK, $h2, $hE, $oE['typ'] ?? null, count($oE['bony'] ?? [])]));
    [$hZ, $oZ] = $fr($sAdmin, substr((string)$bF['easytools_order_id'], 2, 6));
    $t('#201: admin po fragmencie id zamówienia → ten bon z pełnym numerem', $hZ === 200
        && in_array($bF['code'], array_column($oZ['bony'] ?? [], 'kod'), true), json_encode([$hZ, count($oZ['bony'] ?? [])]));
    [$hW, $oW] = $fr($sAdmin, '%%%');
    $t('#201: „%” i „_” traktowane dosłownie (nie jako wzorzec SQL) → 0 wyników', $hW === 200 && ($oW['bony'] ?? null) === [], json_encode([$hW, count($oW['bony'] ?? [])]));
    $sLim = ['login' => 'limit-' . random_int(10000, 99999), 'imie' => 'Test', 'rola' => 'admin', 'csrf' => 'x'];
    $kody = [];
    for ($i = 0; $i < 31; $i++) $kody[] = $fr($sLim, 'zzq')[0];
    $t('#201: limit 30 wyszukiwań po fragmencie na konto → 31. = 429', array_slice($kody, 0, 30) === array_fill(0, 30, 200) && $kody[30] === 429, json_encode(array_count_values($kody)));
    return $wyniki;
}
