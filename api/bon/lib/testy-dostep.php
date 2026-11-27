<?php
// Testy #218: odebranie dostępu do produktu w Easytools, gdy WSZYSTKIE bony zamówienia są zwrócone (lib/zwroty.php → bon_zwroty_dostep).
// Udawane API: GET /orders/{id}?include=line_items (access[] typu product) i DELETE /product-access/{id}.

declare(strict_types=1);
require_once __DIR__ . '/zwroty.php';

function bon_testy_dostep(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $tabB = bon_tabela($k, 'vouchers');
    $tabH = bon_tabela($k, 'voucher_admin_log');

    // udawane API: zamówienia {id => line_items}, tryb DELETE: 200 | 500 | wyjatek; tryb GET: 200 | 404 | wyjatek
    $zam = [];
    $usuniete = [];
    $gety = [];
    $trybDel = '200';
    $trybGet = '200';
    $kz = array_replace($k, ['easytools_api_key' => 'klucz-testowy', 'easytools_odbieraj_dostep' => true,
        'easytools_http' => static function (string $metoda, string $sciezka, array $q, ?array $body) use (&$zam, &$usuniete, &$gety, &$trybDel, &$trybGet): array {
            if ($metoda === 'GET' && preg_match('#^/orders/([^/]+)$#', $sciezka, $m)) {
                $gety[] = $m[1];
                if ($trybGet === 'wyjatek') throw new RuntimeException('API Easytools: symulowane zerwanie');
                if ($trybGet === '404' || !isset($zam[$m[1]])) return [404, ['message' => 'Order not found']];
                return [200, ['id' => $m[1], 'status' => 'completed', 'line_items' => $zam[$m[1]]]];
            }
            if ($metoda === 'DELETE' && preg_match('#^/product-access/([^/]+)$#', $sciezka, $m)) {
                if ($trybDel === 'wyjatek') throw new RuntimeException('API Easytools: symulowane zerwanie');
                if ($trybDel === '500') return [500, ['message' => 'Server Error']];
                $usuniete[] = $m[1];
                return [200, ['message' => 'Product access revoked successfully']];
            }
            return [404, ['message' => 'nieznana ścieżka']];
        }]);

    // zamówienie z N bonami (webhook w testach tworzy bony; id zamówienia jak z API – UUID) + pozycja z dostępem + order bump
    $zamowienie = static function (int $ile) use ($kz, $pdo, $tabB, &$zam): array {
        $p = bon_test_payload(['price_custom_id' => 'bon-200', 'amount_paid' => 200 * $ile, 'order_amount' => 200 * $ile, 'quantity' => $ile]);
        bon_test_webhook($kz, $pdo, $p);
        $bony = bon_test_bony($kz, $pdo, $p['order_id']);
        $uuid = bon_test_uuid();
        $pdo->prepare("UPDATE $tabB SET easytools_order_id = ? WHERE easytools_order_id = ?")->execute([$uuid, (string)$p['order_id']]);
        $dostep = bon_test_uuid();
        $zam[$uuid] = [
            ['id' => 'li-1', 'quantity' => $ile, 'is_order_bump' => false, 'access' => [['type' => 'product', 'id' => $dostep], ['type' => 'subscription', 'id' => 'sub-x']]],
            ['id' => 'li-2', 'quantity' => 1, 'is_order_bump' => true, 'access' => [['type' => 'product', 'id' => 'dostep-bumpa']]],
        ];
        return ['uuid' => $uuid, 'dostep' => $dostep, 'ids' => array_map(static fn($b) => (int)$b['id'], $bony)];
    };
    $zwroc = static fn(int $id) => $pdo->prepare("UPDATE $tabB SET status = 'refunded' WHERE id = ?")->execute([$id]);
    $wpisy = static function (array $ids) use ($pdo, $tabH): array {
        $st = $pdo->prepare("SELECT akcja, szczegoly, kto FROM $tabH WHERE akcja = 'dostep-odebrany' AND voucher_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        return $st->fetchAll();
    };

    // 1. dwa bony, jeden zwrócony → dostęp zostaje, bez zapytań do Easytools o to zamówienie
    $z2 = $zamowienie(2);
    $zwroc($z2['ids'][0]);
    $usuniete = $gety = [];
    $r = bon_zwroty_dostep($kz, $pdo);
    $t('#218: jeden z dwóch bonów zwrócony → dostęp zostaje (bez GET i DELETE dla zamówienia)',
        !in_array($z2['uuid'], $gety, true) && !in_array($z2['dostep'], $usuniete, true) && !$wpisy($z2['ids']), json_encode([$r, $gety, $usuniete]));

    // 2. drugi bon też zwrócony → DELETE tylko dostępu produktu bonu (bez bumpa i subskrypcji), wpis w historii pierwszego bonu
    $zwroc($z2['ids'][1]);
    $usuniete = $gety = [];
    $r = bon_zwroty_dostep($kz, $pdo);
    $w = $wpisy($z2['ids']);
    $t('#218: oba bony zwrócone → DELETE /product-access/{dostęp} (bez bumpa i subskrypcji), jeden wpis „dostep-odebrany” (system)',
        $usuniete === [$z2['dostep']] && count($w) === 1 && $w[0]['kto'] === 'system' && $r['odebrano'] === 1, json_encode([$r, $usuniete, $w]));

    // 3. następny cron → zamówienie pominięte (znacznik w historii), bez drugiego DELETE
    $usuniete = $gety = [];
    $r = bon_zwroty_dostep($kz, $pdo);
    $t('#218: kolejny cron – zamówienie z wpisem pominięte (bez GET i DELETE)', !in_array($z2['uuid'], $gety, true) && $usuniete === [], json_encode([$r, $gety]));

    // 4. bon jedyny w zamówieniu, zwrot częściowy po realizacji (status refunded) → dostęp odebrany
    $z1 = $zamowienie(1);
    $pdo->prepare("UPDATE $tabB SET balance_pln = 58.00 WHERE id = ?")->execute([$z1['ids'][0]]);
    $zwroc($z1['ids'][0]);
    $usuniete = [];
    bon_zwroty_dostep($kz, $pdo);
    $t('#218: jedyny bon zamówienia zwrócony (zwrot częściowy po realizacji) → dostęp odebrany', $usuniete === [$z1['dostep']], json_encode($usuniete));

    // 5. bon działający (valid) albo wykorzystany (used) w zamówieniu – nigdy nie odbieramy
    $z3 = $zamowienie(2);
    $zwroc($z3['ids'][0]);
    $pdo->prepare("UPDATE $tabB SET status = 'used', balance_pln = 0 WHERE id = ?")->execute([$z3['ids'][1]]);
    $usuniete = $gety = [];
    bon_zwroty_dostep($kz, $pdo);
    $t('#218: zwrócony + wykorzystany w jednym zamówieniu → dostęp zostaje', !in_array($z3['dostep'], $usuniete, true) && !in_array($z3['uuid'], $gety, true));

    // 6. błąd DELETE (500) → bez wpisu, następny cron ponawia
    $z4 = $zamowienie(1);
    $zwroc($z4['ids'][0]);
    $trybDel = '500';
    $usuniete = [];
    $r = bon_zwroty_dostep($kz, $pdo);
    $t('#218: DELETE 500 → bez wpisu, liczony błąd', !$wpisy($z4['ids']) && $r['bledy'] >= 1, json_encode($r));
    $trybDel = '200';
    bon_zwroty_dostep($kz, $pdo);
    $t('#218: po błędzie następny cron odbiera dostęp i zapisuje wpis', in_array($z4['dostep'], $usuniete, true) && count($wpisy($z4['ids'])) === 1, json_encode($usuniete));

    // 7. zerwanie połączenia przy GET – 3 z rzędu kończą przebieg (hamulec), bez wpisów
    $zz = [$zamowienie(1), $zamowienie(1), $zamowienie(1), $zamowienie(1)];
    foreach ($zz as $z) $zwroc($z['ids'][0]);
    $trybGet = 'wyjatek';
    $gety = [];
    $r = bon_zwroty_dostep($kz, $pdo);
    $bezWpisow = true;
    foreach ($zz as $z) if ($wpisy($z['ids'])) $bezWpisow = false;
    $t('#218: 3 zerwania z rzędu → przerwano, bez wpisów (ponowi następny cron)', $r['przerwano'] === true && count($gety) === 3 && $bezWpisow, json_encode([$r, count($gety)]));
    $trybGet = '200';

    // 8. Easytools nie zna zamówienia (404, np. bon sprzed API) → wpis „nic do odebrania”, bez DELETE i bez ponawiania
    $usuniete = [];
    bon_zwroty_dostep($kz, $pdo); // dokończ zamówienia z punktu 7
    $z5 = $zamowienie(1);
    $zwroc($z5['ids'][0]);
    unset($zam[$z5['uuid']]);
    $usuniete = [];
    bon_zwroty_dostep($kz, $pdo);
    $w = $wpisy($z5['ids']);
    $t('#218: zamówienie 404 w Easytools → jeden wpis bez DELETE (nie ponawiamy)', $usuniete === [] && count($w) === 1 && str_contains((string)$w[0]['szczegoly'], 'nie zna'), json_encode($w));

    // 9. wyłącznik: easytools_odbieraj_dostep = false → nic nie robi
    $z6 = $zamowienie(1);
    $zwroc($z6['ids'][0]);
    $usuniete = $gety = [];
    $r = bon_zwroty_dostep(array_replace($kz, ['easytools_odbieraj_dostep' => false]), $pdo);
    $t('#218: wyłącznik false → bez GET, DELETE i wpisów', $gety === [] && $usuniete === [] && !$wpisy($z6['ids']) && $r['zamowien'] === 0, json_encode($r));

    // 10. domyślnie włączone w lib/ustawienia.php (wyłącznik bez zmiany kodu)
    $t('#218: easytools_odbieraj_dostep = true w lib/ustawienia.php', (bon_ustawienia_repo()['easytools_odbieraj_dostep'] ?? null) === true);

    // 11. nazwa w historii panelu
    $t('#218: akcja „dostep-odebrany” ma nazwę w historii panelu', in_array('dostep-odebrany', BON_HISTORIA_AKCJE, true)
        && isset(BON_PANEL_ZDARZENIA['dostep-odebrany']));

    // sprzątanie: zamówienia z tego pliku nie mogą wpływać na inne testy (bony refunded zostają, ale z wpisem)
    bon_zwroty_dostep($kz, $pdo);
    return $wyniki;
}
