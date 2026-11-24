<?php
// Testy P-12 (2026-11-24, wpis 16:50): rezygnacja zgłoszona przez kupującego na stronie /bon (lib/rezygnacja.php).
// Woła je bon_testy() w lib/testy.php – te same na SQLite (npm run test:bony) i na OVH (test-ovh.php). Maile tylko w trybie „plik”.

declare(strict_types=1);
require_once __DIR__ . '/rezygnacja.php';
require_once __DIR__ . '/panel.php';

function bon_testy_rezygnacja(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $ip = '192.0.2.' . random_int(1, 250);
    $nowy = static function (int $zl, string $email) use ($k, $pdo): array {
        $p = bon_test_payload(['price_custom_id' => "bon-$zl", 'amount_paid' => $zl, 'customer_email' => $email]);
        bon_test_webhook($k, $pdo, $p);
        return bon_test_bony($k, $pdo, $p['order_id'])[0] ?? [];
    };
    $stan = static function (string $kod) use ($k, $pdo): array {
        $b = bon_panel_znajdz($k, $pdo, $kod);
        $st = $pdo->prepare('SELECT kanal, kto, kwota_pln, zwrot_stan FROM ' . bon_tabela($k, 'voucher_withdrawals') . ' WHERE voucher_id = ?');
        $st->execute([$b['id']]);
        return [$b['status'], $st->fetch() ?: null];
    };

    // verify.php: pole rezygnacja_mozliwa (bez daty zapłaty)
    $b = $nowy(300, 'rez-' . random_int(1000, 9999) . '@example.com');
    [, $v] = bon_obsluz_verify($k, $b['code'], $ip, static fn() => $pdo);
    [, $v15] = bon_obsluz_verify($k, $b['code'], $ip, static fn() => $pdo, bon_teraz()->modify('+15 days'));
    $t('verify: rezygnacja_mozliwa true w 14 dniach, false 15. dnia; bez daty zapłaty', ($v['rezygnacja_mozliwa'] ?? null) === true
        && ($v15['rezygnacja_mozliwa'] ?? null) === false && !isset($v['kupiono']) && !isset($v['created_at']), json_encode($v));

    // pytanie 3c (wpis 17:30): 14 dni w czasie polskim do końca 14. dnia, niezależnie od strefy serwera (OVH: Europe/Paris)
    $strefaSerwera = date_default_timezone_get();
    date_default_timezone_set('America/New_York'); // serwer w zupełnie innej strefie
    try {
        $wwa = new DateTimeZone(BON_STREFA);
        $zakup = ['created_at' => '2026-03-01 23:50:00']; // zapłata w Polsce późnym wieczorem
        $t('termin 14 dni (3c): 14. dzień 23:59 tak, 15. dzień 00:01 nie; „teraz” zawsze w Europe/Warsaw',
            bon_rezygnacja_w_terminie($zakup, (new DateTimeImmutable('2026-03-15 23:59', $wwa))->format('Y-m-d'))
            && !bon_rezygnacja_w_terminie($zakup, (new DateTimeImmutable('2026-03-16 00:01', $wwa))->format('Y-m-d'))
            && bon_teraz()->getTimezone()->getName() === BON_STREFA
            // chwila zapłaty z API (UTC) zapisywana w czasie polskim (data musi być z ostatnich 31 dni – inaczej „teraz”)
            && bon_data_zaplaty(($u = (new DateTimeImmutable('-2 hours', new DateTimeZone('UTC'))))->format('Y-m-d\TH:i:s\Z'), bon_teraz())->format('Y-m-d H:i')
                === $u->setTimezone($wwa)->format('Y-m-d H:i'));
    } finally {
        date_default_timezone_set($strefaSerwera);
    }

    // krok „sprawdz” (wpis 16:47): bez zapisu i maili, ten sam licznik prób; kwota = min(saldo, wpłata)
    $bS = $nowy(200, 'rezs-' . random_int(1000, 9999) . '@example.com');
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . ' SET paid_pln = ? WHERE code = ?')->execute(['8.00', $bS['code']]); // zakup z kodem rabatowym
    [$h, $o, $ctxS] = bon_rezygnacja_ze_strony($k, $pdo, $bS['code'], (string)$bS['buyer_email'], $ip, null, 'sprawdz');
    $t('krok sprawdz: dobry adres → ok z kwotą 8.00 (mniejsza z: saldo 200, wpłata 8), bon nadal ważny, bez rezygnacji i bez maili',
        $h === 200 && $o === ['ok' => true, 'kwota' => '8.00', 'krok' => 'sprawdz'] && $ctxS === null && $stan($bS['code']) === ['valid', null], json_encode([$o, $stan($bS['code'])]));
    [$h, $o] = bon_rezygnacja_ze_strony($k, $pdo, $bS['code'], 'zly@example.com', $ip, null, 'sprawdz');
    $t('krok sprawdz: zły adres → 400 powod email', $h === 400 && ($o['powod'] ?? '') === 'email');
    $bL = $nowy(200, 'rezl-' . random_int(1000, 9999) . '@example.com');
    foreach (['sprawdz', 'zglos', 'sprawdz', 'zglos', 'sprawdz'] as $i => $kr) bon_rezygnacja_ze_strony($k, $pdo, $bL['code'], "zly$i@example.com", '203.0.113.' . (100 + $i), null, $kr);
    [$h, $o] = bon_rezygnacja_ze_strony($k, $pdo, $bL['code'], (string)$bL['buyer_email'], '203.0.113.200', null, 'sprawdz');
    $t('limit wspólny dla obu kroków: 5 złych prób w dowolnej kombinacji → 429 limit', $h === 429 && ($o['powod'] ?? '') === 'limit');
    // liczą się tylko pomyłki (prośba frontu): 4 złe adresy, potem dobry przechodzi przez sprawdz i zglos
    $bP = $nowy(200, 'rezp-' . random_int(1000, 9999) . '@example.com');
    for ($i = 0; $i < 4; $i++) bon_rezygnacja_ze_strony($k, $pdo, $bP['code'], "zly$i@example.com", '203.0.113.' . (150 + $i), null, 'sprawdz');
    [$h1, $o1] = bon_rezygnacja_ze_strony($k, $pdo, $bP['code'], (string)$bP['buyer_email'], '203.0.113.160', null, 'sprawdz');
    [$h2, $o2] = bon_rezygnacja_ze_strony($k, $pdo, $bP['code'], (string)$bP['buyer_email'], '203.0.113.160', null, 'zglos');
    $t('limit liczy tylko pomyłki: po 4 złych adresach dobry przechodzi sprawdz (z polem krok) i zglos (bez pola krok)',
        $h1 === 200 && ($o1['krok'] ?? '') === 'sprawdz' && $h2 === 200 && ($o2['ok'] ?? false) && !isset($o2['krok']), json_encode([$o1, $o2]));

    // zły adres: jawna odmowa, bon bez zmian; wielkość liter i spacje bez znaczenia
    [$h, $o] = bon_rezygnacja_ze_strony($k, $pdo, $b['code'], 'ktos-inny@example.com', $ip);
    $t('rezygnacja: zły adres → 400 powod email, bon nadal ważny', $h === 400 && $o === ['ok' => false, 'powod' => 'email'] && $stan($b['code'])[0] === 'valid');
    [$h, $o, $ctx] = bon_rezygnacja_ze_strony($k, $pdo, strtolower($b['code']), '  ' . strtoupper((string)$b['buyer_email']) . ' ', $ip);
    [$st, $w] = $stan($b['code']);
    $t('rezygnacja: adres wielkimi literami ze spacjami → ok, kwota 300.00, bon withdrawn, kanał strona, kto klient, czeka na zwrot',
        $h === 200 && $o === ['ok' => true, 'kwota' => '300.00'] && $st === 'withdrawn' && $w && $w['kanal'] === 'strona' && $w['kto'] === 'klient'
        && $w['zwrot_stan'] === 'oczekuje' && $ctx !== null, json_encode([$o, $w]));
    [$h, $o] = bon_rezygnacja_ze_strony($k, $pdo, $b['code'], (string)$b['buyer_email'], $ip);
    $t('rezygnacja: drugie zgłoszenie tego samego bonu → 409 powod stan', $h === 409 && ($o['powod'] ?? '') === 'stan');

    // panel: dopisek „zgłoszona na stronie /bon” z godziną, historia z kto = klient
    [, $hP] = bon_panel_historia($k, $pdo, ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'], $b['code']);
    $wpis = array_values(array_filter($hP['zdarzenia'], static fn($z) => $z['akcja'] === 'rezygnacja'))[0] ?? [];
    $t('panel: rezygnacja kanał strona z godziną, wpis w historii od klienta', ($hP['rezygnacja']['kanal'] ?? '') === 'strona'
        && preg_match('/^\d\d:\d\d$/', (string)($hP['rezygnacja']['godzina'] ?? '')) === 1 && ($wpis['kto'] ?? '') === 'klient'
        && str_contains((string)($wpis['szczegoly'] ?? ''), 'zgłoszona na stronie /bon'), json_encode([$hP['rezygnacja'], $wpis]));

    // maile: do kupującego i do obsługi (Reply-To = kupujący, link do panelu)
    $jsonl = $k['_katalog'] . '/bony-mailjet.jsonl';
    $maile = static fn(): array => is_file($jsonl) ? array_values(array_map(static fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($jsonl))))) : [];
    $przed = count($maile());
    $wm = bon_rezygnacja_maile(array_replace($k, ['mail_obsluga' => ['obsluga-test@example.com'], 'telefon_pomoc_bony' => '506 102 340']), $ctx);
    $m = array_slice($maile(), $przed);
    $doK = array_values(array_filter($m, static fn($x) => ($x['To'][0]['Email'] ?? '') === $b['buyer_email']))[0] ?? [];
    $doO = array_values(array_filter($m, static fn($x) => ($x['To'][0]['Email'] ?? '') === 'obsluga-test@example.com'))[0] ?? [];
    $t('maile: potwierdzenie do kupującego (numer, kwota, 14 dni, telefon) i do obsługi (Reply-To kupujący, zamówienie, link do panelu)',
        $wm['kupujacy'] === null && array_key_exists('obsluga', $wm) && $wm['obsluga'] === null && count($m) === 2
        && str_contains((string)($doK['Subject'] ?? ''), 'Rezygnacja z bonu ' . $b['code'] . ' – potwierdzenie')
        && str_contains((string)($doK['TextPart'] ?? ''), "300\u{00A0}zł") && str_contains((string)$doK['TextPart'], 'w ciągu 14 dni') && str_contains((string)$doK['TextPart'], '506 102 340')
        && ($doO['ReplyTo']['Email'] ?? '') === $b['buyer_email'] && str_contains((string)($doO['TextPart'] ?? ''), '/panel-bonow?nr=' . $b['code'])
        && str_contains((string)$doO['TextPart'], (string)$b['easytools_order_id']), json_encode($m, JSON_UNESCAPED_UNICODE));

    // bon częściowo zrealizowany: kwota = saldo (nie więcej niż wpłata)
    $b2 = $nowy(300, 'rez2-' . random_int(1000, 9999) . '@example.com');
    bon_panel_realizuj($k, $pdo, ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'csrf' => 'x'], ['kod' => $b2['code'], 'kwota_reczna' => '180', 'client_token' => bin2hex(random_bytes(12))]);
    [$h, $o] = bon_rezygnacja_ze_strony($k, $pdo, $b2['code'], (string)$b2['buyer_email'], $ip);
    $t('rezygnacja: po realizacji 180 z 300 → kwota 120.00', $h === 200 && ($o['kwota'] ?? '') === '120.00', json_encode($o));

    // saldo 0 i 15. dzień
    $b3 = $nowy(200, 'rez3-' . random_int(1000, 9999) . '@example.com');
    bon_panel_realizuj($k, $pdo, ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'csrf' => 'x'], ['kod' => $b3['code'], 'kwota_reczna' => '200', 'client_token' => bin2hex(random_bytes(12))]);
    [$h, $o] = bon_rezygnacja_ze_strony($k, $pdo, $b3['code'], (string)$b3['buyer_email'], $ip);
    $t('rezygnacja: saldo 0 → 409 powod saldo', $h === 409 && ($o['powod'] ?? '') === 'saldo');
    $b4 = $nowy(200, 'rez4-' . random_int(1000, 9999) . '@example.com');
    [$h, $o] = bon_rezygnacja_ze_strony($k, $pdo, $b4['code'], (string)$b4['buyer_email'], $ip, bon_teraz()->modify('+15 days'));
    $t('rezygnacja: 15. dzień → 409 powod termin, bon ważny', $h === 409 && ($o['powod'] ?? '') === 'termin' && $stan($b4['code'])[0] === 'valid');

    // limit: 5 prób na bon na godzinę (także przy złym adresie), potem limit nawet z dobrym adresem
    $b5 = $nowy(200, 'rez5-' . random_int(1000, 9999) . '@example.com');
    for ($i = 0; $i < 5; $i++) bon_rezygnacja_ze_strony($k, $pdo, $b5['code'], "zly$i@example.com", '198.51.100.' . ($i + 10));
    [$h, $o] = bon_rezygnacja_ze_strony($k, $pdo, $b5['code'], (string)$b5['buyer_email'], '198.51.100.99');
    $t('rezygnacja: po 5 złych próbach w godzinie (różne IP) → 429 powod limit, bon ważny', $h === 429 && ($o['powod'] ?? '') === 'limit' && $stan($b5['code'])[0] === 'valid');
    [$h, $o] = bon_rezygnacja_ze_strony($k, $pdo, 'EWA-ZLY', 'a@example.com', $ip);
    $t('rezygnacja: zły format numeru → 400 powod stan', $h === 400 && ($o['powod'] ?? '') === 'stan');

    // cofnięcie rezygnacji ze strony w panelu (pomyłka) – istniejąca ścieżka
    [$h] = bon_panel_admin($k, $pdo, ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'], ['kod' => $b['code'], 'akcja' => 'cofnij-rezygnacje', 'powod' => 'kupujący się pomylił']);
    $t('panel: rezygnację ze strony da się cofnąć („Cofnij rezygnację”) → bon znów ważny', $h === 200 && $stan($b['code'])[0] === 'valid');

    $wyniki = array_merge($wyniki, bon_testy_potwierdzenia($k, $pdo, $nowy, $maile));
    $wyniki = array_merge($wyniki, bon_testy_zwrot_proporcjonalny($k, $pdo));
    return $wyniki;
}

// Wpis 18:00 (decyzja Bartka 2026-11-24): kwota zwrotu proporcjonalnie do zapłaconej ceny – bon_kwota_zwrotu (lib/bon.php).
// Tabela przypadków + /bon (oba kroki) + koszyk mieszany z rabatem. Panel, „Zwróć pieniądze” i „Oznacz zwrot”: testy-zwroty.php.
function bon_testy_zwrot_proporcjonalny(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    // [wartość, zapłacono, saldo, oczekiwany zwrot] w zł
    $przypadki = [
        ['200.00', '180.00', '100.00', '90.00'],   // rabat 10%, wykorzystane 100
        ['200.00', '8.00', '200.00', '8.00'],      // rabat 96%, nieużyty
        ['200.00', '200.00', '150.00', '150.00'],  // bez rabatu, wykorzystane 50
        ['300.00', '100.00', '150.00', '50.00'],   // zatwierdzony mimo niedopłaty, wykorzystane 150
        ['200.00', '179.99', '33.33', '29.99'],    // grosze: 33,33 × 179,99 / 200 = 29,995… → w dół 29,99
        ['250.00', '250.00', '75.50', '75.50'],    // bon dowolnej kwoty (wartość = zapłacono)
        ['300.00', '310.00', '300.00', '300.00'],  // nadpłata: nigdy więcej niż saldo
        ['200.00', '180.00', '0.00', '0.00'],      // saldo 0
        ['500.00', '450.00', '0.01', '0.00'],      // 1 grosz × 0,9 → 0
    ];
    $zle = [];
    foreach ($przypadki as [$w, $z, $s, $oczek]) {
        $wynik = bon_zl(bon_kwota_zwrotu(['initial_pln' => $w, 'paid_pln' => $z, 'balance_pln' => $s]));
        if ($wynik !== $oczek) $zle[] = "$w/$z saldo $s → $wynik (oczekiwano $oczek)";
    }
    $t('bon_kwota_zwrotu: ' . count($przypadki) . ' przypadków z wpisu 18:00 (rabat, niedopłata, grosze w dół, dowolna kwota, nadpłata)', !$zle, implode('; ', $zle));

    // /bon: bon 200 kupiony za 180, wykorzystane 100 → oba kroki podają 90.00, zapis 90.00, mail kupującego z dopiskiem
    $p = bon_test_payload(['price_custom_id' => 'bon-200', 'amount_paid' => 180, 'promo_code' => 'RABAT10', 'customer_email' => 'prop-' . random_int(1000, 9999) . '@example.com']);
    bon_test_webhook(array_replace($k, ['prog_wstrzymania' => 0]), $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id'])[0];
    bon_panel_realizuj($k, $pdo, ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'csrf' => 'x'], ['kod' => $b['code'], 'kwota_reczna' => '100', 'client_token' => bin2hex(random_bytes(12))]);
    [$h1, $o1] = bon_rezygnacja_ze_strony($k, $pdo, $b['code'], (string)$b['buyer_email'], '192.0.2.201', null, 'sprawdz');
    [$h2, $o2, $ctx] = bon_rezygnacja_ze_strony($k, $pdo, $b['code'], (string)$b['buyer_email'], '192.0.2.201');
    $st = $pdo->prepare('SELECT saldo_pln, kwota_pln FROM ' . bon_tabela($k, 'voucher_withdrawals') . ' WHERE voucher_id = ?');
    $st->execute([$b['id']]);
    $w = $st->fetch();
    $jsonl = $k['_katalog'] . '/bony-mailjet.jsonl';
    $przed = is_file($jsonl) ? count(file($jsonl)) : 0;
    bon_rezygnacja_maile($k, $ctx, $pdo);
    $nowe = is_file($jsonl) ? array_map(static fn($l) => json_decode($l, true), array_slice(file($jsonl, FILE_IGNORE_NEW_LINES), $przed)) : [];
    $doK = array_values(array_filter($nowe, static fn($m) => ($m['To'][0]['Email'] ?? '') === $b['buyer_email']))[0] ?? [];
    $t('proporcjonalnie (/bon): sprawdz i zglos → 90.00, zapis saldo 100 / kwota 90, mail: „90 zł (saldo bonu przeliczone do zapłaconej ceny)”',
        $h1 === 200 && ($o1['kwota'] ?? '') === '90.00' && $h2 === 200 && ($o2['kwota'] ?? '') === '90.00'
        && (float)$w['saldo_pln'] === 100.0 && (float)$w['kwota_pln'] === 90.0
        && str_contains((string)($doK['TextPart'] ?? ''), "90\u{00A0}zł (saldo bonu przeliczone do zapłaconej ceny)"),
        json_encode([$o1, $o2, $w, $doK['TextPart'] ?? null], JSON_UNESCAPED_UNICODE));

    // koszyk mieszany z rabatem 10%: 200 + 2×500, zapłacono 180 + 900 → bony 180/450/450; wykorzystane 100 z 500 → zwrot 360
    $zam = bon_test_uuid();
    bon_utworz_bony(array_replace($k, ['prog_wstrzymania' => 0.8]), $pdo, ['zamowienie' => $zam, 'email' => 'koszyk-' . random_int(1000, 9999) . '@example.com',
        'waluta' => 'pln', 'promo' => 'RABAT10', 'pozycje' => [
            ['wariant' => 'Bon Szmaragdowy', 'nominal' => 200, 'ilosc' => 1, 'zaplacono' => 18000],
            ['wariant' => 'Bon Bursztynowy', 'nominal' => 500, 'ilosc' => 2, 'zaplacono' => 90000],
        ]]);
    $bm = bon_test_bony($k, $pdo, $zam);
    usort($bm, static fn($a, $c) => $a['nr_w_zamowieniu'] <=> $c['nr_w_zamowieniu']);
    $b500 = $bm[1] ?? [];
    if ($b500) bon_panel_realizuj($k, $pdo, ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'csrf' => 'x'], ['kod' => $b500['code'], 'kwota_reczna' => '100', 'client_token' => bin2hex(random_bytes(12))]);
    $b500 = $b500 ? bon_panel_znajdz($k, $pdo, $b500['code']) : [];
    $t('koszyk mieszany z rabatem: bony zapłacone 180/450/450, ważne; bon 500 po wykorzystaniu 100 → zwrot 360.00 (nie 400)',
        count($bm) === 3 && array_map(static fn($x) => (float)$x['paid_pln'], $bm) === [180.0, 450.0, 450.0] && array_unique(array_column($bm, 'status')) === ['valid']
        && $b500 && bon_zl(bon_kwota_zwrotu($b500)) === '360.00', json_encode(array_map(static fn($x) => [$x['initial_pln'], $x['paid_pln'], $x['status']], $bm)));
    return $wyniki;
}

// Wpisy 17:33/17:35 (2026-11-24): potwierdzenie rezygnacji zarejestrowanej w panelu, ponowna wysyłka, mail po cofnięciu;
// P-15: rabat w panelu; verify.php: wartość przy każdym statusie.
function bon_testy_potwierdzenia(array $k, PDO $pdo, callable $nowy, callable $maile): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    $sEwa = ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'rezygnacje' => true, 'csrf' => 'x'];
    $sKarolina = ['login' => 'karolina', 'imie' => 'Karolina', 'rola' => 'realizacja', 'csrf' => 'x'];
    $dzis = bon_teraz()->format('Y-m-d');
    $powiadomienia = static fn(): string => (string)@file_get_contents($k['_katalog'] . '/bony-powiadomienia.txt');
    $potwHist = static function (string $kod) use ($k, $pdo): array {
        $b = bon_panel_znajdz($k, $pdo, $kod);
        $st = $pdo->prepare('SELECT szczegoly, kto FROM ' . bon_tabela($k, 'voucher_admin_log') . " WHERE voucher_id = ? AND akcja = 'potwierdzenie' ORDER BY id");
        $st->execute([$b['id']]);
        return $st->fetchAll();
    };
    // to samo, co robi admin.php po udanym „Zarejestruj rezygnację”
    $zarejestruj = static function (array $kk, array $s, string $kod) use ($pdo, $dzis): array {
        [$h, $o] = bon_panel_admin($kk, $pdo, $s, ['kod' => $kod, 'akcja' => 'rezygnacja', 'kanal' => 'mail', 'data' => $dzis, 'otrzymano' => $dzis]);
        if ($h !== 200) return [$h, $o, null];
        [, $p] = bon_panel_wyslij_potwierdzenie($kk, $pdo, $s, $kod);
        return [$h, $o, $p];
    };

    // 1. rejestracja w panelu (Ewa) → mail do kupującego, wynik w odpowiedzi i w historii, stała linia w panelu
    $b = $nowy(200, 'potw-' . random_int(1000, 9999) . '@example.com');
    $przed = count($maile());
    [$h, , $p] = $zarejestruj($k, $sEwa, $b['code']);
    $m = array_slice($maile(), $przed);
    $hist = $potwHist($b['code']);
    [, $hP] = bon_panel_historia($k, $pdo, $sEwa, $b['code']);
    $t('panel rezygnacja (Ewa): potwierdzenie do kupującego – wynik z adresem i godziną, mail z numerem i „(mailem)”, wpis w historii',
        $h === 200 && ($p['potwierdzenie']['wyslane'] ?? null) === true && ($p['potwierdzenie']['adres'] ?? '') === $b['buyer_email']
        && preg_match('/^\d\d:\d\d$/', (string)($p['potwierdzenie']['godzina'] ?? '')) === 1 && count($m) === 1
        && ($m[0]['To'][0]['Email'] ?? '') === $b['buyer_email'] && str_contains((string)($m[0]['Subject'] ?? ''), 'Rezygnacja z bonu ' . $b['code'] . ' – potwierdzenie')
        && str_contains((string)($m[0]['TextPart'] ?? ''), '(mailem)') && count($hist) === 1 && str_starts_with((string)$hist[0]['szczegoly'], 'wysłane na adres ' . $b['buyer_email'])
        && $hist[0]['kto'] === 'ewa' && ($hP['rezygnacja']['potwierdzenie']['wyslane'] ?? null) === true,
        json_encode([$p, $hist, $hP['rezygnacja'] ?? null], JSON_UNESCAPED_UNICODE));
    $t('panel: bon z historii z typem zdarzenia „Mail z potwierdzeniem rezygnacji”', in_array('potwierdzenie', array_column($hP['zdarzenia'] ?? [], 'akcja'), true));

    // 2. Karolina nie wysyła, bon bez rezygnacji → 409
    [$hK] = bon_panel_wyslij_potwierdzenie($k, $pdo, $sKarolina, $b['code']);
    $bBez = $nowy(100, 'potwb-' . random_int(1000, 9999) . '@example.com');
    [$hB] = bon_panel_wyslij_potwierdzenie($k, $pdo, $sAdmin, $bBez['code']);
    $t('ponowna wysyłka: Karolina → 403, bon bez rezygnacji → 409', $hK === 403 && $hB === 409, json_encode([$hK, $hB]));

    // 3. nieudana wysyłka: wynik „NIE wyszło”, wpis w historii, alert; potem „Wyślij ponownie” się udaje
    $kZly = array_replace($k, ['mailjet_test_blad' => true]);
    $b2 = $nowy(200, 'potwz-' . random_int(1000, 9999) . '@example.com');
    $alertyPrzed = strlen($powiadomienia());
    [$h, , $p] = $zarejestruj($kZly, $sAdmin, $b2['code']);
    $alert = substr($powiadomienia(), $alertyPrzed);
    [, $hP] = bon_panel_historia($k, $pdo, $sAdmin, $b2['code']);
    $t('nieudane potwierdzenie: rezygnacja zapisana, wynik wyslane=false z błędem, historia „NIE wysłane”, alert do gabinetu, panel pokazuje „nie wyszło”',
        $h === 200 && ($p['potwierdzenie']['wyslane'] ?? null) === false && str_starts_with((string)($p['blad'] ?? ''), 'Potwierdzenie NIE wyszło')
        && str_starts_with((string)($potwHist($b2['code'])[0]['szczegoly'] ?? ''), 'NIE wysłane:') && str_contains($alert, 'potwierdzenie rezygnacji NIE wyszło – ' . $b2['code'])
        && ($hP['rezygnacja']['potwierdzenie']['wyslane'] ?? null) === false, json_encode([$p, $hP['rezygnacja'] ?? null], JSON_UNESCAPED_UNICODE));
    [$h, $p] = bon_panel_wyslij_potwierdzenie($k, $pdo, $sEwa, $b2['code']);
    [, $hP] = bon_panel_historia($k, $pdo, $sAdmin, $b2['code']);
    $t('„Wyślij potwierdzenie ponownie” po błędzie → wysłane, panel pokazuje ostatni wynik (wysłane)',
        $h === 200 && ($p['ok'] ?? false) && ($p['potwierdzenie']['wyslane'] ?? null) === true && ($hP['rezygnacja']['potwierdzenie']['wyslane'] ?? null) === true,
        json_encode([$p, $hP['rezygnacja'] ?? null], JSON_UNESCAPED_UNICODE));

    // 4. cofnięcie rezygnacji: mail „bon znów działa” tylko, gdy kupujący dostał potwierdzenie
    $zlozono = static function (string $kod) use ($k, $pdo): string {
        $bb = bon_panel_znajdz($k, $pdo, $kod);
        $st = $pdo->prepare('SELECT zlozono_at FROM ' . bon_tabela($k, 'voucher_withdrawals') . " WHERE voucher_id = ? AND zwrot_stan <> 'anulowana'");
        $st->execute([$bb['id']]);
        return (string)$st->fetchColumn();
    };
    $zl = $zlozono($b['code']);
    bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $b['code'], 'akcja' => 'cofnij-rezygnacje', 'powod' => 'test']);
    $przed = count($maile());
    $w = bon_rezygnacja_wycofanie_mail($k, $pdo, bon_panel_znajdz($k, $pdo, $b['code']), $zl, 'bartek');
    $m = array_slice($maile(), $przed);
    [, $hP] = bon_panel_historia($k, $pdo, $sAdmin, $b['code']);
    $t('cofnięcie po wysłanym potwierdzeniu → mail „znów działa”, wpis „wycofanie:” w historii, bez linii rezygnacji w panelu',
        ($w['wyslane'] ?? null) === true && count($m) === 1 && str_contains((string)($m[0]['Subject'] ?? ''), 'znów działa')
        && str_starts_with((string)(array_slice($potwHist($b['code']), -1)[0]['szczegoly'] ?? ''), 'wycofanie: wysłane') && empty($hP['rezygnacja']),
        json_encode([$w, $m], JSON_UNESCAPED_UNICODE));
    // nowa rezygnacja tego samego bonu: wpis „wycofanie:” nie udaje stanu potwierdzenia nowej rezygnacji
    $b3 = $nowy(200, 'potwc-' . random_int(1000, 9999) . '@example.com');
    $zarejestruj($kZly, $sAdmin, $b3['code']);
    $zl3 = $zlozono($b3['code']);
    bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $b3['code'], 'akcja' => 'cofnij-rezygnacje', 'powod' => 'test']);
    $przed = count($maile());
    $w3 = bon_rezygnacja_wycofanie_mail($k, $pdo, bon_panel_znajdz($k, $pdo, $b3['code']), $zl3, 'bartek');
    $t('cofnięcie, gdy potwierdzenie nie wyszło → bez maila (null)', $w3 === null && count($maile()) === $przed, json_encode($w3));

    // 5. P-15: rabat tylko przy wpłacie poniżej nominału; Karolina nie widzi
    $bR = $nowy(200, 'rab-' . random_int(1000, 9999) . '@example.com');
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . " SET paid_pln = '8.00', promo_code = 'TEST96' WHERE code = ?")->execute([$bR['code']]);
    [, $hA] = bon_panel_historia($k, $pdo, $sAdmin, $bR['code']);
    [, $hKa] = bon_panel_historia($k, $pdo, $sKarolina, $bR['code']);
    [, $hPelny] = bon_panel_historia($k, $pdo, $sAdmin, $bBez['code']);
    $t('P-15: rabat „zapłacono 8 · rabat 192 · kod” u admina, brak u Karoliny, null przy pełnej wpłacie',
        ($hA['rabat'] ?? null) === ['zaplacono' => '8.00', 'rabat' => '192.00', 'kod' => 'TEST96'] && !array_key_exists('rabat', $hKa)
        && array_key_exists('rabat', $hPelny) && $hPelny['rabat'] === null, json_encode([$hA['rabat'] ?? null, $hPelny['rabat'] ?? 'brak']));
    // rezygnacja bonu z rabatem: kwota przeliczona do zapłaconej ceny (wpis 18:00), znacznik „przeliczona”
    $zarejestruj($k, $sAdmin, $bR['code']);
    [, $hA] = bon_panel_historia($k, $pdo, $sEwa, $bR['code']);
    $t('rezygnacja bonu za 8 zł z nominałem 200 → kwota 8.00 i przeliczona', ($hA['rezygnacja']['kwota'] ?? '') === '8.00'
        && ($hA['rezygnacja']['przeliczona'] ?? null) === true, json_encode($hA['rezygnacja'] ?? null));
    // wpis 17:55: bon wstrzymany za niedopłatę – „Zapłacono 100 zł z 300 zł”, bez rabatu i kodu
    $bH = $nowy(300, 'held-' . random_int(1000, 9999) . '@example.com');
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . " SET paid_pln = '100.00', promo_code = 'X', status = 'held' WHERE code = ?")->execute([$bH['code']]);
    [, $hH] = bon_panel_historia($k, $pdo, $sAdmin, $bH['code']);
    $t('rabat przy bonie held: niedopłata „zapłacono 100 z 300”, bez rabatu i kodu', ($hH['rabat'] ?? null) === ['zaplacono' => '100.00', 'wartosc' => '300.00', 'niedoplata' => true],
        json_encode($hH['rabat'] ?? null));

    // 6. verify.php: wartość przy każdym statusie (wpis 17:26 pkt 3), saldo tylko przy valid/used
    $bE = $nowy(150, 'ver-' . random_int(1000, 9999) . '@example.com');
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . " SET status = 'expired' WHERE code = ?")->execute([$bE['code']]);
    [, $v] = bon_obsluz_verify($k, $bE['code'], '192.0.2.77', static fn() => $pdo);
    [, $vW] = bon_obsluz_verify($k, $bR['code'], '192.0.2.77', static fn() => $pdo);
    $t('verify: wartosc przy expired i withdrawn, bez salda', ($v['wartosc'] ?? null) === '150.00' && !array_key_exists('saldo', $v)
        && ($vW['wartosc'] ?? null) === '200.00' && !array_key_exists('saldo', $vW), json_encode([$v, $vW], JSON_UNESCAPED_UNICODE));
    return $wyniki;
}
