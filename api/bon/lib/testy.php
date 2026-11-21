<?php
// Scenariusze testowe backendu bonów – JEDEN zestaw dla dwóch baz (etap 1: 2026-11-21):
//  - lokalnie SQLite: tests/php/bony-test.php,
//  - na OVH MySQL, tabele test_*: /api/bon/test-ovh.php (z tokenem z konfigu).
// Zwraca listę [nazwa, ok, szczegół]. Nic nie wypisuje. Katalog lib/ zablokowany w .htaccess produkcji.

declare(strict_types=1);

/** Payload jak w dokumentacji Easytools (single_product_bought), z nadpisaniami. */
function bon_test_payload(array $nadpisz = []): array {
    return array_replace([
        'event' => 'single_product_bought',
        'timestamp' => time(),
        'success' => true,
        'is_subscription' => false,
        'order_id' => random_int(100000000, 999999999),
        'order_uuid' => '00000000-0000-0000-0000-000000000000',
        'amount_paid' => 200,
        'order_amount' => 200,
        'currency' => 'pln',
        'quantity' => 1,
        'customer_email' => 'test@example.com',
        'customer_name' => 'Test Testowy',
        'price_id' => 'price_test_200',
        'price_custom_id' => 'bon-200',
        'promo_code' => null,
        'custom_fields' => ['bon_dla' => 'Mama', 'bon_od' => 'Ania'],
        'invoice_data' => null,
    ], $nadpisz);
}

function bon_test_konfig(array $k): array {
    return array_replace($k, [
        'webhook_tryb' => 'bon',
        'easytools_webhook_sekret' => 'sekret-testowy-1234567890',
        'kwota_jednostka' => 'pln',
        'ceny' => ['bon-200' => 200, 'bon-300' => 300, 'bon-500' => 500, 'bon-dowolna' => 'dowolna'],
        'prog_wstrzymania' => 0.8,
        'min_dowolna_pln' => 180,
        'waznosc_miesiecy' => 12,
        'kod_prefix' => 'EWA',
        'pole_dla' => ['bon_dla', 'Dla kogo (imię na bonie)'],
        'pole_od' => ['bon_od', 'Od kogo'],
        'verify_limit_na_minute' => 1000,
    ]);
}

/** Wysyła webhook przez bon_obsluz_webhook; $podpis null = poprawny podpis. */
function bon_test_webhook(array $k, PDO $pdo, array $payload, ?string $podpis = null, ?string $raw = null): array {
    $raw ??= json_encode($payload, JSON_UNESCAPED_UNICODE);
    $podpis ??= hash_hmac('sha256', $raw, $k['easytools_webhook_sekret']);
    return bon_obsluz_webhook($k, $raw, ['x-webhook-signature' => $podpis], static fn() => $pdo);
}

function bon_test_bony(array $k, PDO $pdo, $zamowienie): array {
    $st = $pdo->prepare('SELECT * FROM ' . bon_tabela($k, 'vouchers') . ' WHERE easytools_order_id = ? ORDER BY nr_w_zamowieniu');
    $st->execute([(string)$zamowienie]);
    return $st->fetchAll();
}

function bon_testy(array $kBaza, PDO $pdo): array {
    $k = bon_test_konfig($kBaza);
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $zl = static fn($v) => bon_zl((int)bon_grosze($v));
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

    // --- funkcje pomocnicze ---
    $t('kwoty: 0.10 + 0.20 = 0.30 w groszach', bon_zl(bon_grosze('0.10') + bon_grosze('0.20')) === '0.30');
    $t('kwoty: 50 zł w groszach z Easytools („grosze”)', bon_grosze(5000, 'grosze') === 5000 && bon_grosze(50) === 5000);
    $t('kwoty: zła wartość → null', bon_grosze('abc') === null && bon_grosze(-5) === null && bon_grosze(null) === null);
    $strefa = new DateTimeZone(BON_STREFA);
    $t('ważność: 2026-11-21 + 12 mies.', bon_waznosc(new DateTimeImmutable('2026-11-21', $strefa), 12) === '2027-11-21');
    $t('ważność: 29.02.2028 + 12 mies. → 28.02.2029', bon_waznosc(new DateTimeImmutable('2028-02-29', $strefa), 12) === '2029-02-28');
    $t('ważność: 31.01 + 1 mies. → 28.02', bon_waznosc(new DateTimeImmutable('2027-01-31', $strefa), 1) === '2027-02-28');
    $kod = bon_nowy_kod();
    $t('kod: format EWA-XXXX-XXXX bez 0/O/1/I', (bool)preg_match('/^EWA-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $kod), $kod);
    $t('kod: wpisany małymi, bez myślników → ten sam', bon_normalizuj_kod(strtolower(str_replace('-', ' ', $kod))) === $kod);
    $t('kod: zły format → null', bon_normalizuj_kod('EWA-2026-00042') === null && bon_normalizuj_kod('EWA-OOOO-1111') === null);

    // --- webhook ---
    $p = bon_test_payload();
    [$kodHttp, $odp] = bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $oczekWaznosc = bon_waznosc(bon_teraz(), 12);
    $t('webhook poprawny: 200 i jeden bon', $kodHttp === 200 && count($b) === 1, json_encode($odp));
    $t('webhook poprawny: saldo = wartość = zapłacono = 200.00', $b && $zl($b[0]['initial_pln']) === '200.00' && $zl($b[0]['balance_pln']) === '200.00' && $zl($b[0]['paid_pln']) === '200.00');
    $t('webhook poprawny: status valid, ważność = zakup + 12 mies.', $b && $b[0]['status'] === 'valid' && substr((string)$b[0]['expires_at'], 0, 10) === $oczekWaznosc, $b ? $b[0]['expires_at'] . ' vs ' . $oczekWaznosc : '');
    $t('webhook poprawny: Dla/Od z pól własnych, e-mail', $b && $b[0]['dla'] === 'Mama' && $b[0]['od'] === 'Ania' && $b[0]['buyer_email'] === 'test@example.com');
    $kodBonu = $b[0]['code'] ?? '';

    [$kodHttp, $odp] = bon_test_webhook($k, $pdo, $p);
    $t('webhook powtórzony: 200, „powtorzony”, nadal jeden bon', $kodHttp === 200 && ($odp['powtorzony'] ?? false) === true && count(bon_test_bony($k, $pdo, $p['order_id'])) === 1, json_encode($odp));

    $p2 = bon_test_payload();
    [$kodHttp] = bon_test_webhook($k, $pdo, $p2, str_repeat('0', 64));
    $t('webhook zły podpis: 401, bon nie powstaje', $kodHttp === 401 && !bon_test_bony($k, $pdo, $p2['order_id']));
    [$kodHttp] = bon_test_webhook($k, $pdo, $p2, '');
    $t('webhook bez podpisu: 401', $kodHttp === 401);
    $raw = json_encode($p2);
    [$kodHttp] = bon_test_webhook($k, $pdo, $p2, hash_hmac('sha256', $raw, $k['easytools_webhook_sekret']), $raw . ' ');
    $t('webhook body zmienione po podpisaniu: 401', $kodHttp === 401);

    $p = bon_test_payload(['price_custom_id' => 'bon-dowolna', 'amount_paid' => 237.5]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('kwota spoza progów (dowolna 237.50): bon na kwotę zapłaconą', $b && $zl($b[0]['initial_pln']) === '237.50' && $b[0]['status'] === 'valid');

    $p = bon_test_payload(['price_custom_id' => 'bon-dowolna', 'amount_paid' => 150]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('dowolna poniżej 180 zł: wstrzymany (held)', $b && $b[0]['status'] === 'held');

    $p = bon_test_payload(['price_custom_id' => 'bon-300', 'amount_paid' => 270, 'promo_code' => 'SWIETA10']);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('rabat 10%: wartość = nominał 300, zapłacono 270, kod zapisany', $b && $zl($b[0]['initial_pln']) === '300.00' && $zl($b[0]['paid_pln']) === '270.00' && $b[0]['promo_code'] === 'SWIETA10' && $b[0]['status'] === 'valid');

    $p = bon_test_payload(['price_custom_id' => 'bon-300', 'amount_paid' => 200, 'promo_code' => 'DUZO']);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('rabat > 20% (200 przy 300): held z powodem', $b && $b[0]['status'] === 'held' && str_contains((string)$b[0]['status_uwagi'], 'nominale 300.00'), $b[0]['status_uwagi'] ?? '');

    $p = bon_test_payload(['price_id' => 'price_nieznany', 'price_custom_id' => null, 'amount_paid' => 250]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('nieznany wariant ceny: held, wartość = zapłacono', $b && $b[0]['status'] === 'held' && $zl($b[0]['initial_pln']) === '250.00');

    $p = bon_test_payload(['price_id' => 'bon-500', 'price_custom_id' => null, 'amount_paid' => 500]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('identyfikator wariantu w price_id (bon-500): nominał 500', $b && $zl($b[0]['initial_pln']) === '500.00' && $b[0]['status'] === 'valid');

    $p = bon_test_payload(['quantity' => 3, 'amount_paid' => 600]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('quantity 3: trzy bony po 200, różne kody, te same Dla/Od', count($b) === 3
        && count(array_unique(array_column($b, 'code'))) === 3
        && array_map($zl, array_column($b, 'initial_pln')) === ['200.00', '200.00', '200.00']
        && array_unique(array_column($b, 'dla')) === ['Mama']);
    [, $odp] = bon_test_webhook($k, $pdo, $p);
    $t('quantity 3 powtórzony: nadal trzy bony', ($odp['powtorzony'] ?? false) && count(bon_test_bony($k, $pdo, $p['order_id'])) === 3);

    $p = bon_test_payload(['quantity' => '2', 'amount_paid' => 400]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('quantity jako tekst "2": dwa bony po 200', count($b) === 2 && $b[0]['status'] === 'valid');

    $p = bon_test_payload(['quantity' => 'trzy', 'amount_paid' => 600]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('quantity nieczytelne przy 600 zł: 1 bon held (nic nie znika bez śladu)', count($b) === 1 && $b[0]['status'] === 'held', $b[0]['status_uwagi'] ?? '');

    $p = bon_test_payload(['quantity' => 1, 'amount_paid' => 600]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('zapłacono więcej niż nominał (600 za bon 200): held', $b && $b[0]['status'] === 'held' && str_contains((string)$b[0]['status_uwagi'], 'więcej niż nominał'));

    $p = bon_test_payload(['quantity' => 60, 'amount_paid' => 12000]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('quantity 60 > 50: 50 bonów held z informacją', count($b) === 50 && $b[0]['status'] === 'held' && str_contains((string)$b[0]['status_uwagi'], 'ilość 60'));

    $p = bon_test_payload(['event' => 'product_assigned', 'amount_paid' => null]);
    [$kodHttp, $odp] = bon_test_webhook($k, $pdo, $p);
    $t('product_assigned: pominięty, bez bonu', $kodHttp === 200 && ($odp['pominieto'] ?? '') === 'product_assigned' && !bon_test_bony($k, $pdo, $p['order_id']));

    $p = bon_test_payload(['success' => false]);
    bon_test_webhook($k, $pdo, $p);
    $t('success = false: bez bonu', !bon_test_bony($k, $pdo, $p['order_id']));

    $p = bon_test_payload(['custom_fields' => [['name' => 'Dla kogo (imię na bonie)', 'value' => 'Tata'], ['label' => 'Od kogo', 'value' => 'Zosia']]]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('pola własne jako lista {name,value}: Dla/Od odczytane', $b && $b[0]['dla'] === 'Tata' && $b[0]['od'] === 'Zosia');

    // --- verify ---
    $ip = '192.0.2.' . random_int(1, 250);
    [$kodHttp, $odp] = bon_obsluz_verify($k, $kodBonu, $ip, static fn() => $pdo);
    $t('verify ważny: status valid, saldo 200.00, data', $kodHttp === 200 && $odp['status'] === 'valid' && $odp['saldo'] === '200.00' && $odp['wazny_do'] === $oczekWaznosc, json_encode($odp));
    $t('verify: brak danych osobowych w odpowiedzi', !array_intersect(array_keys($odp), ['buyer_email', 'buyer_name', 'dla', 'od', 'buyer_nip', 'email']) && !str_contains(json_encode($odp), 'example.com'));
    [$kodHttp, $odp2] = bon_obsluz_verify($k, strtolower(str_replace('-', '', $kodBonu)), $ip, static fn() => $pdo);
    $t('verify: kod małymi literami bez myślników działa', $kodHttp === 200 && ($odp2['kod'] ?? '') === $kodBonu);

    $wczoraj = bon_teraz()->modify('-1 day')->format('Y-m-d');
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . ' SET expires_at = ? WHERE code = ?')->execute([$wczoraj, $kodBonu]);
    [$kodHttp, $odp] = bon_obsluz_verify($k, $kodBonu, $ip, static fn() => $pdo);
    $t('verify wygasły (expires_at wczoraj): status expired, bez salda', $kodHttp === 200 && $odp['status'] === 'expired' && !isset($odp['saldo']), json_encode($odp));
    $dzis = bon_teraz()->format('Y-m-d');
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . ' SET expires_at = ? WHERE code = ?')->execute([$dzis, $kodBonu]);
    [, $odp] = bon_obsluz_verify($k, $kodBonu, $ip, static fn() => $pdo);
    $t('verify w ostatnim dniu ważności: nadal valid', $odp['status'] === 'valid');

    [$kodHttp, $odp] = bon_obsluz_verify($k, bon_nowy_kod(), $ip, static fn() => $pdo);
    $t('verify nieistniejący: 404', $kodHttp === 404 && $odp['znaleziono'] === false);
    [$kodHttp] = bon_obsluz_verify($k, "EWA-2026-00042' OR 1=1", $ip, static fn() => $pdo);
    $t('verify zły format: 400 (bez zapytania do bazy)', $kodHttp === 400);

    $kLimit = array_replace($k, ['verify_limit_na_minute' => 3]);
    $ip2 = '198.51.100.' . random_int(1, 250);
    $kody = [];
    for ($i = 0; $i < 4; $i++) $kody[] = bon_obsluz_verify($kLimit, $kodBonu, $ip2, static fn() => $pdo)[0];
    $t('verify limit 3/min: czwarte zapytanie 429', $kody === [200, 200, 200, 429], implode(',', $kody));

    // --- baza ---
    $tab = bon_tabela($k, 'vouchers');
    $pdo->prepare("UPDATE $tab SET balance_pln = ? WHERE code = ?")->execute(['199.99', $kodBonu]);
    $st = $pdo->prepare("SELECT balance_pln FROM $tab WHERE code = ?");
    $st->execute([$kodBonu]);
    $t('DECIMAL: 199.99 zapisane i odczytane bez zaokrągleń', $zl($st->fetchColumn()) === '199.99');
    if ($mysql) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT balance_pln FROM $tab WHERE code = ? FOR UPDATE");
        $st->execute([$kodBonu]);
        $pdo->commit();
        $t('MySQL: SELECT … FOR UPDATE w transakcji działa', true);
        $tz = $pdo->query("SELECT NOW() AS n, @@session.time_zone AS s, @@system_time_zone AS sys")->fetch();
        $t('MySQL: strefa czasu (informacyjnie – daty liczy PHP)', true, "NOW()={$tz['n']} session={$tz['s']} system={$tz['sys']} PHP=" . bon_teraz()->format('Y-m-d H:i:s'));
    }
    return $wyniki;
}
