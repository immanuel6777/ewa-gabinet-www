<?php
// Testy synchronizacji zamówień z API Easytools (bony bez webhooka, 2026-11-23). API podstawione ($k['easytools_http']) –
// żadnych prawdziwych wywołań ani maili (mail_tryb = plik z bon_test_konfig). Woła je bon_testy() w lib/testy.php.

declare(strict_types=1);
require_once __DIR__ . '/easytools.php';

/** Udawane API: zamówienia i transakcje jak w dokumentacji (kwoty w groszach). Zwraca [callable, &licznik wywołań]. */
function bon_test_api(array &$zamowienia, array &$transakcje, array &$wywolania): callable {
    return static function (string $metoda, string $sciezka, array $q, ?array $body) use (&$zamowienia, &$transakcje, &$wywolania): array {
        $wywolania[] = "$metoda $sciezka";
        if ($sciezka === '/orders') {
            $lista = array_values(array_filter($zamowienia, static fn($z) => $z['_produkt'] === ($q['product'] ?? '') && $z['status'] === ($q['status'] ?? $z['status'])));
            usort($lista, static fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
            return [200, ['items' => array_map(static fn($z) => array_intersect_key($z, array_flip(['id', 'status', 'amount', 'currency', 'quantity', 'product_name', 'customer_email', 'created_at'])), $lista),
                'pagination' => ['current_page' => 1, 'total_pages' => 1, 'per_page' => 100, 'total' => count($lista)]]];
        }
        if (preg_match('#^/orders/([0-9a-f-]+)$#', $sciezka, $m)) {
            $z = $zamowienia[$m[1]] ?? null;
            if (!$z) return [404, ['message' => 'not found']];
            unset($z['_produkt']);
            return [200, $z];
        }
        if ($sciezka === '/transactions') return [200, ['items' => array_values(array_map(static fn($t) => array_diff_key($t, ['order' => 1]), $transakcje))]];
        if (preg_match('#^/transactions/([0-9a-f-]+)$#', $sciezka, $m)) return isset($transakcje[$m[1]]) ? [200, $transakcje[$m[1]]] : [404, []];
        return [404, ['message' => 'nieznana ścieżka w teście']];
    };
}

function bon_test_uuid(): string {
    $h = bin2hex(random_bytes(16));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
}

function bon_testy_sync(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $produkt = 'e12be4ae-2d3a-47f3-b220-437fc5b46277';
    $teraz = bon_teraz();
    $zamowienia = $transakcje = $wywolania = [];
    $dodaj = static function (array $z = []) use (&$zamowienia, &$transakcje, $produkt, $teraz): array {
        $id = bon_test_uuid();
        $z = array_replace_recursive([
            'id' => $id, 'status' => 'completed', 'amount' => 20000, 'currency' => 'pln', 'quantity' => 1,
            'product_name' => 'Bon podarunkowy na zabiegi podologiczne', 'customer_email' => 'sync@example.com',
            'created_at' => $teraz->modify('-5 minutes')->format('c'), '_produkt' => $produkt,
            'line_items' => [['product_name' => 'Bon podarunkowy na zabiegi podologiczne', 'variant_name' => 'Bon 200 zł', 'amount' => 20000,
                'amount_total' => 20000, 'currency' => 'pln', 'quantity' => 1, 'is_order_bump' => false, 'access' => []]],
            'customer' => ['id' => bon_test_uuid(), 'email' => 'sync@example.com', 'name' => 'Sync Testowy'],
        ], $z);
        $z['id'] = $id;
        $zamowienia[$id] = $z;
        $tid = bon_test_uuid();
        $transakcje[$tid] = ['id' => $tid, 'total' => $z['amount'], 'currency' => 'pln', 'customer_email' => $z['customer_email'],
            'product_name' => $z['product_name'], 'date' => $z['created_at'], 'remaining_refundable' => $z['amount'], 'order' => ['id' => $id]];
        return ['id' => $id, 'transakcja' => $tid];
    };
    // zamówienia testowe mają datę „teraz − 5 min”: bez tego wynik zależałby od zegara (okno publikacji nazw 25.11 22:38–22:40);
    // daty z ustawień sprawdzają osobne testy „22:39” niżej ($ksD)
    $ks = array_replace($k, ['warianty_zmiana' => ['stare_przed' => '2000-01-01 00:00:00', 'nowe_od' => '2000-01-01 00:00:00'],
        'easytools_api_key' => 'klucz-testowy', 'easytools_produkt_uuid' => $produkt, 'sync_tryb' => 'bon',
        'sync_od' => $teraz->modify('-1 day')->format('Y-m-d H:i:s'), 'sync_odstep_s' => 20,
        'easytools_http' => bon_test_api($zamowienia, $transakcje, $wywolania),
    ]);
    $ile = static function (string $zam) use ($k, $pdo): int {
        $st = $pdo->prepare('SELECT COUNT(*) FROM ' . bon_tabela($k, 'vouchers') . ' WHERE easytools_order_id = ?');
        $st->execute([$zam]);
        return (int)$st->fetchColumn();
    };
    $plikOdstepu = $k['_katalog'] . '/bony-sync-strona.txt';
    @unlink($plikOdstepu);

    // tryb raport: nic nie powstaje
    $a = $dodaj();
    [$r, $zad] = bon_synchronizuj(array_replace($ks, ['sync_tryb' => 'raport']), $pdo, 'cron');
    $t('sync raport: bon nie powstaje, zamówienie w raporcie', $ile($a['id']) === 0 && !$zad && count($r['nowe'] ?? []) >= 1, json_encode($r, JSON_UNESCAPED_UNICODE));
    $t('sync bez sync_od: pominięte', (bon_synchronizuj(array_replace($ks, ['sync_od' => '']), $pdo, 'cron')[0]['pominieto'] ?? '') === 'brak sync_od');
    $t('sync bez klucza API: pominięte', (bon_synchronizuj(array_replace($ks, ['easytools_api_key' => 'WKLEJ_KLUCZ']), $pdo, 'cron')[0]['pominieto'] ?? '') === 'brak easytools_api_key');

    // tryb bon: bon z API
    [$r, $zad] = bon_synchronizuj($ks, $pdo, 'cron');
    $b = bon_test_bony($k, $pdo, $a['id']);
    $t('sync: bon z zamówienia z API (200 zł, valid, bez Dla/Od)', count($b) === 1 && $b[0]['status'] === 'valid' && $b[0]['initial_pln'] == 200 && $b[0]['paid_pln'] == 200
        && $b[0]['dla'] === null && $b[0]['od'] === null && $b[0]['buyer_email'] === 'sync@example.com' && $b[0]['price_id'] === 'Bon 200 zł', json_encode($r, JSON_UNESCAPED_UNICODE));
    $t('sync: id transakcji zapisane przy bonie', $b && $b[0]['easytools_transaction_id'] === $a['transakcja']);
    $t('sync: zadanie wysyłki PDF', count($zad) >= 1);
    foreach ($zad as $z) $z();
    $b = bon_test_bony($k, $pdo, $a['id']);
    $t('sync: po wysyłce mail_wyslany_at ustawione', $b && $b[0]['mail_wyslany_at'] !== null);
    [, $tekstMaila] = bon_mail_tresc($k, $b);
    $t('mail bonu bez Dla/Od: informacja o pustych liniach do wpisania', str_contains($tekstMaila, 'puste linie „Dla” i „Od”'));
    [, $tekstMaila] = bon_mail_tresc($k, [array_replace($b[0], ['dla' => 'Mama', 'od' => 'Ania'])]);
    $t('mail bonu z Dla/Od: bez informacji o pustych liniach', !str_contains($tekstMaila, 'puste linie'));
    [, $bezTel] = bon_mail_tresc(array_replace($k, ['telefon_pomoc_bony' => '']), $b);
    [, $zTel, $zTelHtml] = bon_mail_tresc(array_replace($k, ['telefon_pomoc_bony' => '506 102 340', 'telefon_pomoc_bony_godziny' => 'pon.–pt. 9–17']), $b);
    $t('mail: telefon pomocy z konfigu (tekst i HTML), puste = bez linii', !str_contains($bezTel, 'Pytania o bon')
        && str_contains($zTel, 'Pytania o bon lub kłopot z plikiem? Zadzwoń: 506 102 340 (pon.–pt. 9–17) albo odpisz na tego maila.')
        && str_contains($zTelHtml, '506 102 340'));
    // wpis 17:47: zdanie o rezygnacji za przełącznikiem, przedostatnie (przed „Pytania o bon…”); ważność „od dnia zapłaty”
    $zdanie = 'Rezygnacja w ciągu 14 dni od zakupu: najszybciej na stronie ewa-gabinet.pl/bon, pod wynikiem sprawdzenia bonu.';
    $kTel = array_replace($k, ['telefon_pomoc_bony' => '506 102 340']);
    [, $bezRez] = bon_mail_tresc(array_replace($kTel, ['mail_zdanie_rezygnacja' => false]), $b);
    [, $zRez, $zRezHtml] = bon_mail_tresc(array_replace($kTel, ['mail_zdanie_rezygnacja' => true]), $b);
    $t('mail: zdanie o rezygnacji tylko z przełącznikiem, w tekście i HTML, tuż przed „Pytania o bon”; ważny „od dnia zakupu” (wpis 10:10)',
        !str_contains($bezRez, 'Rezygnacja w ciągu') && str_contains($zRez, $zdanie . "\nPytania o bon")
        && str_contains($zRezHtml, 'ewa-gabinet.pl/bon, pod wynikiem') && str_contains($zRez, 'od dnia zakupu') && !str_contains($zRez, 'zapłaty'));

    // drugi przebieg: bez duplikatu i bez ponownego pobierania szczegółów
    $wywolania = [];
    [$r, $zad] = bon_synchronizuj($ks, $pdo, 'cron');
    $t('sync drugi przebieg: nadal 1 bon, bez GET zamówienia i bez wysyłki', $ile($a['id']) === 1 && !in_array('GET /orders/' . $a['id'], $wywolania, true) && !isset($zad[$a['id']]), implode(', ', $wywolania));

    // webhook z tym samym order_uuid po synchronizacji → bez duplikatu
    [$kodHttp, $odp] = bon_test_webhook($k, $pdo, bon_test_payload(['order_uuid' => $a['id']]));
    $t('webhook po synchronizacji (ten sam order_uuid): powtórzony, 1 bon', $kodHttp === 200 && ($odp['powtorzony'] ?? false) && $ile($a['id']) === 1, json_encode($odp));
    $b = bon_test_bony($k, $pdo, $a['id']);
    $t('webhook po synchronizacji: puste Dla/Od uzupełnione z webhooka', $b && $b[0]['dla'] === 'Mama' && $b[0]['od'] === 'Ania');
    $jsonl = $k['_katalog'] . '/bony-mailjet.jsonl';
    $przed = is_file($jsonl) ? count(file($jsonl)) : 0;
    [, , $zadanie] = bon_test_webhook($k, $pdo, bon_test_payload(['order_uuid' => $a['id'], 'custom_fields' => ['bon-dla' => 'Inna', 'bon-od' => 'Inny']]));
    $w = $zadanie ? $zadanie() : [];
    $b = bon_test_bony($k, $pdo, $a['id']);
    $t('webhook powtórzony: wpisanych Dla/Od nie nadpisuje, maila nie wysyła ponownie', $b && $b[0]['dla'] === 'Mama' && ($w['wyslane'] ?? 0) === 0
        && (is_file($jsonl) ? count(file($jsonl)) : 0) === $przed, json_encode($w));

    // webhook pierwszy, synchronizacja później → bez duplikatu, uzupełnia id transakcji
    $c = $dodaj();
    bon_test_webhook($k, $pdo, bon_test_payload(['order_uuid' => $c['id'], 'customer_email' => 'sync@example.com']));
    bon_synchronizuj($ks, $pdo, 'cron');
    $b = bon_test_bony($k, $pdo, $c['id']);
    $t('webhook przed synchronizacją: 1 bon, id transakcji uzupełnione', count($b) === 1 && $b[0]['easytools_transaction_id'] === $c['transakcja'] && $b[0]['dla'] === 'Mama');

    // 2026-11-25: webhook_tryb log+bon – zakup jak prawdziwy z 25.11 00:29 (zamówienie a0a30770…): single_product_bought,
    // 4 s później product_assigned, 3 s później synchronizacja ze strony /bony/dziekujemy. Payload bez custom_fields (tak przyszedł),
    // kwota w zł, price_id ze Stripe, price_custom_id bon-200, kod rabatowy, próg 0 (testy). Wynik: 1 bon, 1 mail, transakcja z API.
    $kLB = array_replace($k, ['webhook_tryb' => 'log+bon', 'prog_wstrzymania' => 0, 'ceny' => $k['ceny'] + ['price_1UeMn8DJkxNsH4K3wXFIBCh4' => 200]]);
    $ksLB = array_replace($ks, ['webhook_tryb' => 'log+bon', 'prog_wstrzymania' => 0]);
    [, $zaleglosci] = bon_synchronizuj($ksLB, $pdo, 'cron'); // zaległe wysyłki z testów wyżej – żeby liczyć tylko maile tego scenariusza
    foreach ($zaleglosci as $z) $z();
    $prawdziwy = $dodaj(['amount' => 800, 'line_items' => [['variant_name' => 'Bon 200 zł', 'amount' => 800, 'amount_total' => 800]]]);
    $payloadLB = bon_test_payload(['order_uuid' => $prawdziwy['id'], 'order_id' => 1604262, 'amount_paid' => 8, 'order_amount' => 8,
        'price_id' => 'price_1UeMn8DJkxNsH4K3wXFIBCh4', 'price_custom_id' => 'bon-200', 'promo_code' => 'TEST966', 'customer_email' => 'sync@example.com']);
    unset($payloadLB['custom_fields']);
    $mailiPrzed = is_file($jsonl) ? count(file($jsonl)) : 0;
    $logPrzed = count(glob($k['_katalog'] . '/bony-webhook-log/*') ?: []) ? strlen(implode('', array_map('file_get_contents', glob($k['_katalog'] . '/bony-webhook-log/*')))) : 0;
    [$h1, $o1, $zad1] = bon_test_webhook($kLB, $pdo, $payloadLB);
    $b = bon_test_bony($k, $pdo, $prawdziwy['id']);
    $t('log+bon: webhook tworzy bon od razu (200 zł nominał, zapłacono 8 zł, kod rabatowy, bez Dla/Od, bez transakcji)', $h1 === 200
        && ($o1['bonow'] ?? 0) === 1 && count($b) === 1 && $b[0]['status'] === 'valid' && $b[0]['initial_pln'] == 200 && $b[0]['paid_pln'] == 8
        && $b[0]['promo_code'] === 'TEST966' && $b[0]['dla'] === null && $b[0]['easytools_transaction_id'] === null && $zad1, json_encode([$o1, $b[0] ?? null]));
    $logPo = strlen(implode('', array_map('file_get_contents', glob($k['_katalog'] . '/bony-webhook-log/*') ?: [])));
    $t('log+bon: surowy webhook nadal trafia do logu', $logPo > $logPrzed);
    [$h2, $o2, $zad2] = bon_test_webhook($kLB, $pdo, array_replace($payloadLB, ['event' => 'product_assigned', 'amount_paid' => null]));
    $t('log+bon: product_assigned pominięty (bez bonu i bez maila)', $h2 === 200 && ($o2['pominieto'] ?? '') === 'product_assigned' && $zad2 === null && $ile($prawdziwy['id']) === 1);
    $w1 = $zad1();
    [, $zadS] = bon_synchronizuj($ksLB, $pdo, 'cron');
    $wS = array_map(static fn($z) => $z(), $zadS);
    $b = bon_test_bony($k, $pdo, $prawdziwy['id']);
    $mailiPo = is_file($jsonl) ? count(file($jsonl)) : 0;
    $t('log+bon: webhook + synchronizacja tego samego zamówienia → 1 bon, 1 mail, transakcja uzupełniona z API',
        count($b) === 1 && ($w1['wyslane'] ?? 0) === 1 && array_sum(array_column($wS, 'wyslane')) === 0 && $mailiPo === $mailiPrzed + 1
        && $b[0]['easytools_transaction_id'] === $prawdziwy['transakcja'] && $b[0]['mail_wyslany_at'] !== null, json_encode([$w1, $wS, $mailiPo - $mailiPrzed]));
    // 2026-11-25: webhook_tryb dzwonek (Bartek 24.11 23:52 pkt 3) – podpisany zakup uruchamia synchronizację z API; treść
    // webhooka (kwota, e-mail, wariant, kod) NIE trafia do bonu; niepodpisany / zły podpis / inne zdarzenie → nic
    $kD = array_replace($ks, ['webhook_tryb' => 'dzwonek', 'prog_wstrzymania' => 0]);
    $dz = $dodaj(['amount' => 800, 'line_items' => [['variant_name' => 'Bon 200 zł', 'amount' => 800, 'amount_total' => 800]]]);
    $payloadD = bon_test_payload(['order_uuid' => $dz['id'], 'amount_paid' => 999, 'customer_email' => 'inny@example.com',
        'price_custom_id' => 'bon-500', 'promo_code' => 'Z-WEBHOOKA']);
    unset($payloadD['custom_fields']);
    $mailiPrzed = is_file($jsonl) ? count(file($jsonl)) : 0;
    [$h, $o, $zad] = bon_test_webhook($kD, $pdo, $payloadD, 'zly-podpis');
    $t('dzwonek: zły podpis → 200, podpis_ok false, bez synchronizacji i bez bonu', $h === 200 && ($o['podpis_ok'] ?? null) === false && $zad === null && $ile($dz['id']) === 0, json_encode($o));
    $raw = json_encode($payloadD);
    [$h, $o, $zad] = array_pad(bon_obsluz_webhook($kD, $raw, [], static fn() => $pdo), 3, null); // #276
    $t('dzwonek: bez podpisu → 200, bez synchronizacji i bez bonu', $h === 200 && ($o['podpis_ok'] ?? null) === false && $zad === null && $ile($dz['id']) === 0, json_encode($o));
    [$h, $o, $zad] = bon_test_webhook($kD, $pdo, array_replace($payloadD, ['event' => 'product_assigned']));
    $t('dzwonek: podpisany product_assigned → pominięty, bez synchronizacji', $h === 200 && ($o['pominieto'] ?? '') === 'product_assigned' && $zad === null, json_encode($o));
    $wywolania = [];
    [$h, $o, $zad] = bon_test_webhook($kD, $pdo, $payloadD);
    $t('dzwonek: podpisany zakup → 200 od razu, synchronizacja dopiero po odpowiedzi', $h === 200 && ($o['dzwonek'] ?? false) === true && $zad && $ile($dz['id']) === 0 && !$wywolania, json_encode($o));
    $wD = $zad();
    $listy = count(array_filter($wywolania, static fn($w) => $w === 'GET /orders'));
    $b = bon_test_bony($k, $pdo, $dz['id']);
    $t('dzwonek: synchronizacja wywołana raz, bon z danych API (200 zł, 8 zł, e-mail i wariant z API, bez kodu z webhooka), 1 mail',
        $listy === 1 && count($b) === 1 && $b[0]['initial_pln'] == 200 && $b[0]['paid_pln'] == 8 && $b[0]['buyer_email'] === 'sync@example.com'
        && $b[0]['price_id'] === 'Bon 200 zł' && $b[0]['promo_code'] === null && $b[0]['easytools_transaction_id'] === $dz['transakcja']
        && ($wD['bonow_zamowienia'] ?? 0) === 1 && (is_file($jsonl) ? count(file($jsonl)) : 0) === $mailiPrzed + 1, json_encode([$wD, $b[0] ?? null]));
    // dwa takie same dzwonki (Easytools ponawia) – drugi nic nie dokłada (na MySQL dodatkowo GET_LOCK synchronizacji i wysyłki)
    [, , $zad1] = bon_test_webhook($kD, $pdo, $payloadD);
    [, , $zad2] = bon_test_webhook($kD, $pdo, $payloadD);
    $zad1();
    $w2 = $zad2();
    $t('dzwonek: dwa takie same → jeden komplet bonów, bez drugiego maila', $ile($dz['id']) === 1 && ($w2['nowe'] ?? 1) === 0
        && (is_file($jsonl) ? count(file($jsonl)) : 0) === $mailiPrzed + 1, json_encode($w2));
    // dzwonek, gdy API jeszcze nie ma zamówienia (np. status nie „completed”) – bez błędu, bonow_zamowienia 0; cron/strona dokończą
    $brak = bon_test_uuid();
    [, , $zadB] = bon_test_webhook($kD, $pdo, bon_test_payload(['order_uuid' => $brak]));
    $wB = $zadB();
    $t('dzwonek: zamówienia jeszcze nie ma w API → bez błędu, 0 bonów (dokończy strona albo cron)', ($wB['bonow_zamowienia'] ?? null) === 0 && $ile($brak) === 0, json_encode($wB));

    // odwrotna kolejność (cron/strona przed webhookiem, np. webhook spóźniony) – też jeden mail
    $odwrotny = $dodaj();
    $mailiPrzed = is_file($jsonl) ? count(file($jsonl)) : 0;
    [, $zadS] = bon_synchronizuj($ksLB, $pdo, 'cron');
    foreach ($zadS as $z) $z();
    [, , $zadW] = bon_test_webhook($kLB, $pdo, bon_test_payload(['order_uuid' => $odwrotny['id'], 'customer_email' => 'sync@example.com']));
    $wW = $zadW ? $zadW() : [];
    $t('log+bon: synchronizacja przed webhookiem → 1 bon, 1 mail, Dla/Od dopisane bez drugiego maila',
        $ile($odwrotny['id']) === 1 && ($wW['wyslane'] ?? 0) === 0 && (is_file($jsonl) ? count(file($jsonl)) : 0) === $mailiPrzed + 1
        && (bon_test_bony($k, $pdo, $odwrotny['id'])[0]['dla'] ?? null) === 'Mama', json_encode($wW));

    // inne produkty i zamówienia sprzed sync_od pomijane
    $testowy = $dodaj(['_produkt' => bon_test_uuid(), 'product_name' => 'TEST webhook – nie kupować']);
    $stare = $dodaj(['created_at' => $teraz->modify('-2 days')->format('c')]);
    bon_synchronizuj($ks, $pdo, 'cron');
    $t('sync: produkt „TEST webhook” i zamówienie sprzed sync_od bez bonu', $ile($testowy['id']) === 0 && $ile($stare['id']) === 0);

    // rabat (zapłacono < 80% nominału) i nieznany wariant → wstrzymany, bez maila
    $rabat = $dodaj(['amount' => 800, 'line_items' => [['amount' => 800, 'amount_total' => 800]]]);
    $nieznany = $dodaj(['line_items' => [['variant_name' => 'Bon 250 zł']]]);
    [, $zad] = bon_synchronizuj($ks, $pdo, 'cron');
    $br = bon_test_bony($k, $pdo, $rabat['id']);
    $bn = bon_test_bony($k, $pdo, $nieznany['id']);
    $t('sync: kod rabatowy (8 zł za 200) → held, paid 8.00', $br && $br[0]['status'] === 'held' && $br[0]['paid_pln'] == 8 && $br[0]['initial_pln'] == 200);
    $t('sync: nieznany wariant → held z uwagą o „warianty”', $bn && $bn[0]['status'] === 'held' && str_contains((string)$bn[0]['status_uwagi'], 'warianty'));
    $t('sync: wstrzymane bez zadania wysyłki', !isset($zad[$rabat['id']]) && !isset($zad[$nieznany['id']]));

    // dowolna kwota i 2 sztuki
    $dow = $dodaj(['amount' => 25000, 'line_items' => [['variant_name' => 'Dowolna kwota (od 180 zł)', 'amount' => 25000, 'amount_total' => 25000]]]);
    $dwa = $dodaj(['amount' => 40000, 'quantity' => 2, 'line_items' => [['quantity' => 2, 'amount_total' => 40000]]]);
    bon_synchronizuj($ks, $pdo, 'cron');
    $bd = bon_test_bony($k, $pdo, $dow['id']);
    $b2 = bon_test_bony($k, $pdo, $dwa['id']);
    $t('sync: dowolna kwota 250 zł → bon 250 zł valid', $bd && $bd[0]['initial_pln'] == 250 && $bd[0]['status'] === 'valid');
    $t('sync: 2 sztuki → 2 bony po 200 zł', count($b2) === 2 && $b2[0]['initial_pln'] == 200 && $b2[1]['paid_pln'] == 200);

    // koszyk mieszany (2026-11-24, wpis 17:30): każda pozycja osobno, numery kolejno, jeden mail; order bump pomijany
    $linia = static fn(string $wariant, int $ilosc, int $razem, bool $bump = false) => ['product_name' => 'Bon podarunkowy na zabiegi podologiczne',
        'variant_name' => $wariant, 'amount' => intdiv($razem, $ilosc), 'amount_total' => $razem, 'currency' => 'pln', 'quantity' => $ilosc, 'is_order_bump' => $bump, 'access' => []];
    $jsonlM = $k['_katalog'] . '/bony-mailjet.jsonl';
    $ileMaili = static fn() => is_file($jsonlM) ? count(file($jsonlM)) : 0;
    $mieszany = $dodaj(['amount' => 125000, 'quantity' => 3, 'line_items' => [
        $linia('Bon Topazowy', 1, 20000), $linia('Bon Szmaragdowy', 2, 100000), $linia('Dodatek', 1, 5000, true)]]);
    $przedM = $ileMaili();
    [$rM, $zad] = bon_synchronizuj($ks, $pdo, 'cron');
    foreach ($zad as $z) $z();
    $bm = bon_test_bony($k, $pdo, $mieszany['id']);
    usort($bm, static fn($a, $b) => $a['nr_w_zamowieniu'] <=> $b['nr_w_zamowieniu']);
    $t('koszyk mieszany 200 + 2×500 (+ order bump): 3 bony 200/500/500, numery 1–3, wszystkie ważne, zapłacono po pozycjach',
        count($bm) === 3 && array_map(static fn($b) => (float)$b['initial_pln'], $bm) === [200.0, 500.0, 500.0]
        && array_column($bm, 'nr_w_zamowieniu') == [1, 2, 3] && array_unique(array_column($bm, 'status')) === ['valid']
        && (float)$bm[1]['paid_pln'] === 500.0 && $bm[0]['price_id'] === 'Bon Topazowy' && $bm[2]['price_id'] === 'Bon Szmaragdowy',
        json_encode([$bm, $rM], JSON_UNESCAPED_UNICODE));
    // tylko maile z bonami tego zamówienia (w tym samym przebiegu mogą wyjść zaległe maile innych testów)
    $zBonami = static function (array $kody) use ($jsonlM, &$przedM): array {
        $linie = is_file($jsonlM) ? array_slice(file($jsonlM, FILE_IGNORE_NEW_LINES), $przedM) : [];
        return array_values(array_filter(array_map(static fn($l) => json_decode($l, true) ?: [], $linie),
            static fn($m) => array_intersect(array_map(static fn($a) => preg_replace('/^bon-|\.pdf$/', '', (string)($a['Filename'] ?? $a['nazwa'] ?? '')), (array)($m['Attachments'] ?? [])), $kody)));
    };
    $mm = $zBonami(array_column($bm, 'code'));
    $t('koszyk mieszany: jeden mail ze wszystkimi 3 PDF-ami', count($mm) === 1 && count($mm[0]['Attachments'] ?? []) === 3, json_encode(array_map(static fn($m) => $m['Attachments'] ?? null, $mm)));
    bon_synchronizuj($ks, $pdo, 'cron');
    $t('koszyk mieszany: drugi przebieg synchronizacji – nadal 3 bony', count(bon_test_bony($k, $pdo, $mieszany['id'])) === 3);

    $czesc = $dodaj(['amount' => 55000, 'quantity' => 2, 'line_items' => [$linia('Bon Szafirowy', 1, 30000), $linia('Bon Diamentowy', 1, 25000)]]);
    $plikPowM = $k['_katalog'] . '/bony-powiadomienia.txt';
    $powPrzed = (string)@file_get_contents($plikPowM);
    $przedM = $ileMaili();
    [, $zad] = bon_synchronizuj($ks, $pdo, 'cron');
    foreach ($zad as $z) $z();
    $bc = bon_test_bony($k, $pdo, $czesc['id']);
    usort($bc, static fn($a, $b) => $a['nr_w_zamowieniu'] <=> $b['nr_w_zamowieniu']);
    $powNowe = substr((string)@file_get_contents($plikPowM), strlen($powPrzed));
    $mc = $zBonami(array_column($bc, 'code'));
    $t('koszyk z nieznanym wariantem: znany ważny i wysłany (1 PDF), nieznany wstrzymany; alert z listą pozycji',
        count($bc) === 2 && $bc[0]['status'] === 'valid' && $bc[1]['status'] === 'held' && str_contains((string)$bc[1]['status_uwagi'], 'zamówienie z 2 pozycji')
        && count($mc) === 1 && count($mc[0]['Attachments'] ?? []) === 1
        && str_contains($powNowe, 'Część bonów') && str_contains($powNowe, 'Bon Diamentowy: 1 szt.') // #220: tabelka zamiast „•”
        && str_contains($powNowe, 'panel-bonow?nr=' . $bc[1]['code']), $powNowe); // #220: przycisk = wstrzymany bon, nie ważny

    // REVIEW nr 10 (R9): suma pozycji ≠ kwota zamówienia (rabat na koszyk, którego pozycje nie pokazują) → wszystkie bony held + alert
    $rozjazd = $dodaj(['amount' => 45000, 'quantity' => 2, 'line_items' => [$linia('Bon Topazowy', 1, 20000), $linia('Bon Szafirowy', 1, 30000)]]);
    $powPrzed = (string)@file_get_contents($plikPowM);
    $przedM = $ileMaili();
    [, $zad] = bon_synchronizuj($ks, $pdo, 'cron');
    foreach ($zad as $z) $z();
    $br = bon_test_bony($k, $pdo, $rozjazd['id']);
    $powNowe = substr((string)@file_get_contents($plikPowM), strlen($powPrzed));
    $t('REVIEW nr 10: koszyk 200+300, zamówienie 450 zł → oba bony wstrzymane z uwagą o sumie, alert, bez maila z bonami',
        count($br) === 2 && array_unique(array_column($br, 'status')) === ['held'] && str_contains((string)$br[0]['status_uwagi'], 'suma pozycji 500.00 zł')
        && str_contains($powNowe, 'Bon WSTRZYMANY') && str_contains($powNowe, "kwota zamówienia 450\u{00A0}zł") && !str_contains($powNowe, '450.00') && !$zBonami(array_column($br, 'code')),
        json_encode([$br, $powNowe], JSON_UNESCAPED_UNICODE));
    // kontrola: zgodna suma z order bumpem (125000 = 20000 + 100000 + 5000 bump) nie wstrzymuje – test „koszyk mieszany” wyżej

    // nazwy wariantów po zmianie z 25.11 (wpis 21:47): Szafirowy = 300 zł, dowolna kwota pod nazwą bez kamienia; stara nazwa
    // „Bon Rubinowy” (do 25.11 = 300 zł) koliduje z nowym kamieniem, więc jej nie ma w mapie – taki bon czeka (held) na sprawdzenie
    $rub = $dodaj(['amount' => 30000, 'line_items' => [['variant_name' => 'Bon Szafirowy', 'amount' => 30000, 'amount_total' => 30000]]]);
    $szaf = $dodaj(['amount' => 50000, 'line_items' => [['variant_name' => 'dowolna kwota', 'amount' => 50000, 'amount_total' => 50000]]]);
    $staraRub = $dodaj(['amount' => 30000, 'line_items' => [['variant_name' => 'Bon Rubinowy', 'amount' => 30000, 'amount_total' => 30000]]]);
    $twoja = $dodaj(['amount' => 42000, 'line_items' => [['variant_name' => 'Twoja kwota', 'amount' => 42000, 'amount_total' => 42000]]]); // Bartek 26.11 17:59
    $nowaNieznana = $dodaj(['line_items' => [['variant_name' => 'Bon Diamentowy']]]);
    [, $zad] = bon_synchronizuj($ks, $pdo, 'cron');
    $brub = bon_test_bony($k, $pdo, $rub['id']);
    $bsz = bon_test_bony($k, $pdo, $szaf['id']);
    $bnn = bon_test_bony($k, $pdo, $nowaNieznana['id']);
    $bsr = bon_test_bony($k, $pdo, $staraRub['id']);
    $btw = bon_test_bony($k, $pdo, $twoja['id']);
    $t('wariant „Twoja kwota” (nowa nazwa 26.11) → dowolna kwota 420 zł valid', $btw && $btw[0]['initial_pln'] == 420 && $btw[0]['status'] === 'valid', json_encode($btw[0] ?? null, JSON_UNESCAPED_UNICODE));
    $t('wariant „Bon Szafirowy” → bon 300 zł valid', $brub && $brub[0]['initial_pln'] == 300 && $brub[0]['status'] === 'valid');
    $t('wariant „dowolna kwota” (małe litery) → dowolna kwota 500 zł valid', $bsz && $bsz[0]['initial_pln'] == 500 && $bsz[0]['status'] === 'valid');
    $t('stara nazwa „Bon Rubinowy” w zamówieniu po publikacji nowych nazw → nieznany wariant, wstrzymany', $bsr && $bsr[0]['status'] === 'held');
    // --- wpis czatu 22:39: nowe nazwy LIVE od 25.11 22:39; rozstrzyga chwila utworzenia zamówienia (nie kwota – rabat) ---
    $en = static fn(string $nazwa, ?string $kiedy) => bon_et_nominal($k, $nazwa, $kiedy);
    $po = '2026-11-25T23:00:00+01:00'; $przed = '2026-11-24T12:00:00+01:00'; $okno = '2026-11-25T22:39:00+01:00';
    $t('22:39: po publikacji „Bon Szafirowy” = 300, „Bon Szmaragdowy” = 500, „Bon Topazowy” = 200; przed – stare (Szafirowy = dowolna, Szmaragdowy = 200, Rubinowy = 300)',
        $en('Bon Szafirowy', $po) === 300 && $en('Bon Szmaragdowy', $po) === 500 && $en('Bon Topazowy', $po) === 200 && $en('Bon Rubinowy', $po) === null
        && $en('Bon Szafirowy', $przed) === 'dowolna' && $en('Bon Szmaragdowy', $przed) === 200 && $en('Bon Rubinowy', $przed) === 300
        && $en('Bon 500 zł', $przed) === 500 && $en('Dowolna kwota (od 180 zł)', $po) === 'dowolna' && $en('Dowolna kwota (od 180 zł)', $przed) === 'dowolna');
    $t('22:39: zamówienie z okna 22:38–22:40 → brak nominału (wstrzymany); bez daty → nowe nazwy',
        $en('Bon Szafirowy', $okno) === null && bon_et_okno_zmiany($k, $okno) && !bon_et_okno_zmiany($k, $po) && $en('Bon Szafirowy', null) === 300);
    $ksD = array_replace($ks, ['sync_od' => '2026-11-24 00:00:00', 'warianty_zmiana' => $k['warianty_zmiana']]); // prawdziwe daty publikacji
    $s270 = $dodaj(['created_at' => $po, 'amount' => 27000, 'line_items' => [['variant_name' => 'Bon Szafirowy', 'amount' => 27000, 'amount_total' => 27000]]]);
    $s250 = $dodaj(['created_at' => '2026-11-24T18:00:00+01:00', 'amount' => 25000, 'line_items' => [['variant_name' => 'Bon Szafirowy', 'amount' => 25000, 'amount_total' => 25000]]]);
    $d600n = $dodaj(['created_at' => $po, 'amount' => 60000, 'line_items' => [['variant_name' => 'Dowolna kwota (od 180 zł)', 'amount' => 60000, 'amount_total' => 60000]]]);
    $sOkno = $dodaj(['created_at' => $okno, 'amount' => 30000, 'line_items' => [['variant_name' => 'Bon Szafirowy', 'amount' => 30000, 'amount_total' => 30000]]]);
    bon_synchronizuj($ksD, $pdo, 'cron');
    $b270 = bon_test_bony($k, $pdo, $s270['id'])[0] ?? [];
    $b250s = bon_test_bony($k, $pdo, $s250['id'])[0] ?? [];
    $b600n = bon_test_bony($k, $pdo, $d600n['id'])[0] ?? [];
    $bOkno = bon_test_bony($k, $pdo, $sOkno['id'])[0] ?? [];
    $t('22:39: zamówienie z 23:00 „Bon Szafirowy” 300 zł zapłacone 270 zł → bon 300 zł, Szafirowy, ważny',
        $b270 && $b270['initial_pln'] == 300 && $b270['paid_pln'] == 270 && $b270['status'] === 'valid' && bon_nazwa($k, $b270) === 'Bon Szafirowy', json_encode($b270, JSON_UNESCAPED_UNICODE));
    $t('22:39: zamówienie z 24.11 „Bon Szafirowy” 250 zł → dowolna 250 zł (kamień wg progów: Szafirowy)',
        $b250s && $b250s['initial_pln'] == 250 && $b250s['status'] === 'valid' && bon_nazwa($k, $b250s) === 'Bon Szafirowy', json_encode($b250s, JSON_UNESCAPED_UNICODE));
    $t('22:39: zamówienie z 23:00 „Dowolna kwota (od 180 zł)” 600 zł → Rubinowy, 12 mies.',
        $b600n && $b600n['initial_pln'] == 600 && bon_nazwa($k, $b600n) === 'Bon Rubinowy' && (int)bon_kamien_bonu($k, $b600n)['waznosc_miesiecy'] === 12);
    $t('22:39: zamówienie z okna 22:39 → wstrzymany z uwagą o oknie zmiany nazw',
        $bOkno && $bOkno['status'] === 'held' && str_contains((string)$bOkno['status_uwagi'], 'okna zmiany nazw'), json_encode($bOkno, JSON_UNESCAPED_UNICODE));

    // --- wpis czatu 22:34: kamień i ważność ustalone RAZ z wartości początkowej – saldo, realizacje, cofnięcia, przedłużenie ich nie zmieniają ---
    $d600 = $dodaj(['amount' => 60000, 'line_items' => [['variant_name' => 'Dowolna kwota (od 180 zł)', 'amount' => 60000, 'amount_total' => 60000]]]);
    $d400 = $dodaj(['amount' => 40000, 'line_items' => [['variant_name' => 'Dowolna kwota (od 180 zł)', 'amount' => 40000, 'amount_total' => 40000]]]);
    bon_synchronizuj($ks, $pdo, 'cron');
    $tabV = bon_tabela($k, 'vouchers');
    $wiersz = static function (string $kod) use ($pdo, $tabV) { $s = $pdo->prepare("SELECT * FROM $tabV WHERE code = ?"); $s->execute([$kod]); return $s->fetch(); };
    $b6 = bon_test_bony($k, $pdo, $d600['id'])[0] ?? [];
    $b4 = bon_test_bony($k, $pdo, $d400['id'])[0] ?? [];
    $ver = static fn(string $kod) => bon_obsluz_verify($k, $kod, '203.0.113.' . random_int(1, 250), static fn() => $pdo)[1];
    $przed6 = [bon_nazwa($k, $b6), bon_kolor($k, $b6), $b6['expires_at'] ?? null];
    $przed4 = [bon_nazwa($k, $b4), bon_kolor($k, $b4), $b4['expires_at'] ?? null];
    // realizacje do salda 150 zł i 50 zł (zapis jak po redeem: zmienia się tylko balance_pln)
    $pdo->prepare("UPDATE $tabV SET balance_pln = '150.00' WHERE code = ?")->execute([$b6['code']]);
    $pdo->prepare("UPDATE $tabV SET balance_pln = '50.00' WHERE code = ?")->execute([$b4['code']]);
    $p6 = $wiersz($b6['code']);
    $p4 = $wiersz($b4['code']);
    $v6 = $ver($b6['code']);
    $t('22:34: bon 600 zł → Rubinowy; po realizacji 450 zł (saldo 150) dalej Rubinowy, czerwony, ta sama ważność (12 mies.) – także w verify',
        $przed6[0] === 'Bon Rubinowy' && [bon_nazwa($k, $p6), bon_kolor($k, $p6), $p6['expires_at']] === $przed6
        && ($v6['nazwa'] ?? '') === 'Bon Rubinowy' && ($v6['kolor'] ?? '') === 'czerwony' && ($v6['saldo'] ?? '') === '150.00'
        && substr((string)$p6['expires_at'], 0, 10) === bon_waznosc(new DateTimeImmutable(substr((string)$p6['created_at'], 0, 10), new DateTimeZone(BON_STREFA)), 12),
        json_encode([$przed6, $v6], JSON_UNESCAPED_UNICODE));
    $t('22:34: bon 400 zł (Szmaragdowy, 12 mies.) → saldo 50 zł → dalej Szmaragdowy, data bez zmian; mail i PDF z tym samym kamieniem',
        $przed4[0] === 'Bon Szmaragdowy' && [bon_nazwa($k, $p4), bon_kolor($k, $p4), $p4['expires_at']] === $przed4
        && str_contains(bon_mail_tresc($k, [$p4])[1], 'Bon Szmaragdowy o wartości 400') && bon_pdf_kolor($k, $p4) === 'zielen');
    // cofnięcie realizacji (saldo wraca) i przedłużenie (+3 mies. do daty bonu) – kamień bez zmian; 12-mies. bon → 15 mies. od zakupu
    $pdo->prepare("UPDATE $tabV SET balance_pln = '600.00' WHERE code = ?")->execute([$b6['code']]);
    $nowaData = bon_waznosc(new DateTimeImmutable(substr((string)$p6['expires_at'], 0, 10), new DateTimeZone(BON_STREFA)), 3);
    $pdo->prepare("UPDATE $tabV SET expires_at = ? WHERE code = ?")->execute([$nowaData . ' 00:00:00', $b6['code']]);
    $p6b = $wiersz($b6['code']);
    $t('22:34: cofnięcie realizacji i przedłużenie nie zmieniają kamienia; przedłużenie 12-mies. bonu = 15 mies. od zakupu',
        bon_nazwa($k, $p6b) === 'Bon Rubinowy' && bon_kolor($k, $p6b) === 'czerwony'
        && substr((string)$p6b['expires_at'], 0, 10) === bon_waznosc(new DateTimeImmutable(substr((string)$p6b['created_at'], 0, 10), new DateTimeZone(BON_STREFA)), 15));
    $pR = bon_test_payload(['price_custom_id' => 'bon-200', 'amount_paid' => 180, 'order_amount' => 180]);
    bon_test_webhook($k, $pdo, $pR);
    $bR = bon_test_bony($k, $pdo, $pR['order_id'])[0] ?? [];
    $t('22:34: bon 200 zł kupiony za 180 zł (rabat) → Topazowy z wartości bonu, 6 mies.', $bR && $bR['initial_pln'] == 200 && $bR['paid_pln'] == 180
        && bon_nazwa($k, $bR) === 'Bon Topazowy' && (int)bon_kamien_bonu($k, $bR)['waznosc_miesiecy'] === 6, json_encode($bR, JSON_UNESCAPED_UNICODE));
    $zrodlo = static function (string $f): string { $r = new ReflectionFunction($f); return implode('', array_slice(file($r->getFileName()), $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1)); };
    $t('22:34: w kodzie progów (bon_kamien, bon_kamien_bonu, bon_nazwa, bon_kolor) nie ma odwołania do balance_pln',
        !str_contains($zrodlo('bon_kamien') . $zrodlo('bon_kamien_bonu') . $zrodlo('bon_nazwa') . $zrodlo('bon_kolor'), 'balance'));

    // webhook (gdyby wrócił tryb tworzenia bonów z webhooka): price_id rozstrzyga, nazwa wariantu nie ma znaczenia
    $pW300 = bon_test_payload(['price_id' => 'price_1UeHCtDJkxNsH4K38JGuXhbj', 'price_custom_id' => null, 'amount_paid' => 300, 'order_amount' => 300]);
    bon_test_webhook($k, $pdo, $pW300);
    $pWdow = bon_test_payload(['price_id' => 'price_1UeMn8DJkxNsH4K3Y4D7uIaE', 'price_custom_id' => null, 'amount_paid' => 250, 'order_amount' => 250]);
    bon_test_webhook($k, $pdo, $pWdow);
    $bW300 = bon_test_bony($k, $pdo, $pW300['order_id']);
    $bWdow = bon_test_bony($k, $pdo, $pWdow['order_id']);
    $t('webhook: price_id 300 zł → bon 300 zł (Szafirowy); price_id dowolnej + 250 zł → dowolna 250 zł (Szafirowy z kwoty)',
        $bW300 && $bW300[0]['initial_pln'] == 300 && bon_nazwa($k, $bW300[0]) === 'Bon Szafirowy'
        && $bWdow && $bWdow[0]['initial_pln'] == 250 && bon_nazwa($k, $bWdow[0]) === 'Bon Szafirowy', json_encode([$bW300, $bWdow], JSON_UNESCAPED_UNICODE));
    $t('nieznana nowa nazwa → held', $bnn && $bnn[0]['status'] === 'held' && !isset($zad[$nowaNieznana['id']]));
    // --- kamień z wartości (wpis 21:47): progi = górne granice włącznie; nazwa, kolor i ważność z initial_pln, nie z wariantu ---
    $nz = static fn(string $zl, ?string $id = '') => bon_nazwa($k, ['price_id' => $id, 'initial_pln' => $zl]);
    $mc = static fn(string $zl) => (int)bon_kamien($k, (int)bon_grosze($zl))['waznosc_miesiecy'];
    $t('kamień: 180 i 200 → Topazowy; 200,01 i 300 → Szafirowy; 300,01 i 500 → Szmaragdowy; 500,01 i 750 → Rubinowy',
        $nz('180.00', 'bon-dowolna') === 'Bon Topazowy' && $nz('200.00', 'bon-200') === 'Bon Topazowy' && $nz('200.01', 'bon-dowolna') === 'Bon Szafirowy'
        && $nz('300.00', 'bon-300') === 'Bon Szafirowy' && $nz('300.01', 'bon-dowolna') === 'Bon Szmaragdowy' && $nz('500.00', 'bon-500') === 'Bon Szmaragdowy'
        && $nz('500.01', 'bon-dowolna') === 'Bon Rubinowy' && $nz('750.00', 'Dowolna kwota (od 180 zł)') === 'Bon Rubinowy');
    $t('kamień: stałe 200/300/500 jak dowolna kwota tej samej wartości; stara nazwa wariantu nie zmienia kamienia (Bon Rubinowy 300 → Szafirowy)',
        $nz('200.00', 'Bon 200 zł') === $nz('200.00', 'bon-dowolna') && $nz('500.00', 'Bon Szmaragdowy') === $nz('500.00', 'bon-dowolna')
        && $nz('300.00', 'Bon Rubinowy') === 'Bon Szafirowy' && bon_nazwa($k, $b2[0]) === 'Bon Topazowy' && bon_nazwa($k, $brub[0]) === 'Bon Szafirowy');
    $t('kamień: poniżej najniższego progu (8 zł, brak wartości) → najniższy kamień, bez błędu',
        $nz('8.00') === 'Bon Topazowy' && bon_nazwa($k, ['price_id' => null, 'initial_pln' => null]) === 'Bon Topazowy');
    $t('ważność z kamienia: 200 i 300 zł → 6 mies.; 300,01 / 500 / 750 zł → 12 mies.',
        $mc('200.00') === 6 && $mc('300.00') === 6 && $mc('300.01') === 12 && $mc('500.00') === 12 && $mc('750.00') === 12);
    $kProgi = array_replace($k, ['kamienie' => [['do_pln' => 1000, 'nazwa' => 'Bon Próbny', 'kolor' => 'granat', 'waznosc_miesiecy' => 9, 'nowy_stat' => 'x'],
        ['do_pln' => null, 'nazwa' => 'Bon Duży', 'kolor' => 'zloty', 'waznosc_miesiecy' => 24]]]);
    $t('progi z konfiguracji: inne progi i nowe pole bez zmiany kodu', bon_nazwa($kProgi, ['initial_pln' => '999.00']) === 'Bon Próbny'
        && bon_kamien($kProgi, 100001)['waznosc_miesiecy'] === 24 && bon_kamien($kProgi, 500)['nowy_stat'] === 'x');
    // data ważności przy tworzeniu bonu – osobno dla każdego bonu (koszyk mieszany wyżej: 200 + 2×500)
    $plus = static fn(array $b, int $m) => substr((string)$b['expires_at'], 0, 10) === bon_waznosc(new DateTimeImmutable(substr((string)$b['created_at'], 0, 10), new DateTimeZone(BON_STREFA)), $m);
    $t('ważność przy tworzeniu: koszyk 200 + 2×500 → +6 i +12 mies. (osobno dla każdego bonu); dowolna 250 → +6; dowolna 500 → +12',
        $plus($bm[0], 6) && $plus($bm[1], 12) && $plus($bm[2], 12) && $plus($bd[0], 6) && $plus($bsz[0], 12),
        json_encode([$bm[0]['expires_at'], $bm[1]['expires_at'], $bd[0]['expires_at'], $bsz[0]['expires_at']]));
    $koniec = static fn(array $b) => new DateTimeImmutable(substr((string)$b['expires_at'], 0, 10), new DateTimeZone(BON_STREFA));
    $nowa = static fn(array $b) => bon_przedluzenie_okno($b + ['przedluzony_at' => null], $koniec($b))['nowa'] ?? null; // „dziś” = koniec ważności
    $t('przedłużenie +3 mies. tak samo dla bonu 6- i 12-miesięcznego',
        $nowa($bm[0]) === bon_waznosc($koniec($bm[0]), 3) && $nowa($bm[1]) === bon_waznosc($koniec($bm[1]), 3), json_encode([$nowa($bm[0]), $nowa($bm[1])]));
    [, $tekstMaila, $htmlMaila] = bon_mail_tresc($k, $brub);
    $t('mail: „Bon Szafirowy o wartości 300 zł”, ważny 6 miesięcy – w tekście i HTML', str_contains($tekstMaila, "Bon Szafirowy o wartości 300\u{00A0}zł, numer " . $brub[0]['code'])
        && str_contains($htmlMaila, "Bon Szafirowy o wartości 300\u{00A0}zł") && str_contains($tekstMaila, 'ważny 6 miesięcy'), $tekstMaila);
    $b250 = array_replace($bd[0], ['initial_pln' => '250.00', 'price_id' => 'Dowolna kwota (od 180 zł)']);
    $b400 = array_replace($bd[0], ['initial_pln' => '400.00', 'price_id' => 'Dowolna kwota (od 180 zł)', 'code' => 'EWA-CCCC-4444']);
    [, $t250] = bon_mail_tresc($k, [$b250]);
    [, $t400] = bon_mail_tresc($k, [$b400]);
    [, $tMix] = bon_mail_tresc($k, [$b250, $b400]);
    $t('mail: dowolna 250 zł → Bon Szafirowy 6 mies.; 400 zł → Bon Szmaragdowy 12 mies.; koszyk obu → „6 albo 12 miesięcy”',
        str_contains($t250, 'Bon Szafirowy o wartości 250') && str_contains($t250, 'ważny 6 miesięcy')
        && str_contains($t400, 'Bon Szmaragdowy o wartości 400') && str_contains($t400, 'ważny 12 miesięcy')
        && str_contains($tMix, 'ważny 6 albo 12 miesięcy'), $tMix);
    $t('PDF: dowolna 400 zł → zielone tło (Szmaragdowy), zdanie „12 miesięcy”; PDF powstaje',
        bon_pdf_kolor($k, $b400) === 'zielen' && bon_miesiace_tekst((int)bon_kamien_bonu($k, $b400)['waznosc_miesiecy']) === '12 miesięcy'
        && str_starts_with(bon_pdf(array_replace($b400, ['expires_at' => '2027-11-25 00:00:00']), $k), '%PDF'));
    foreach ($zad as $z) $z();

    // nieudana wcześniej wysyłka: kolejny przebieg ją dokańcza
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . ' SET mail_wyslany_at = NULL WHERE easytools_order_id = ?')->execute([$dow['id']]);
    [, $zad] = bon_synchronizuj($ks, $pdo, 'cron');
    $t('sync: bon ważny bez maila → zadanie wysyłki w kolejnym przebiegu', isset($zad[$dow['id']]));
    foreach ($zad as $z) $z();

    // odstęp minimalny dla wywołań ze strony
    @unlink($plikOdstepu);
    $r1 = bon_synchronizuj($ks, $pdo, 'strona')[0];
    $r2 = bon_synchronizuj($ks, $pdo, 'strona')[0];
    $r3 = bon_synchronizuj($ks, $pdo, 'panel')[0];
    $t('sync ze strony: drugie wywołanie w 20 s pominięte, panel bez odstępu', !isset($r1['pominieto']) && ($r2['pominieto'] ?? '') === 'odstęp' && !isset($r3['pominieto']));
    @unlink($plikOdstepu);

    // błąd API → wyjątek (endpoint loguje), nic nie powstaje
    $zle = array_replace($ks, ['easytools_http' => static fn() => [401, ['message' => 'Unauthenticated.']]]);
    $wyjatek = '';
    try { bon_synchronizuj($zle, $pdo, 'cron'); } catch (RuntimeException $e) { $wyjatek = $e->getMessage(); }
    $t('sync: API 401 → wyjątek z kodem, bez klucza w treści', str_contains($wyjatek, 'HTTP 401') && !str_contains($wyjatek, 'klucz-testowy'), $wyjatek);

    // raport dla panelu: bez e-maili
    [$r] = bon_synchronizuj(array_replace($ks, ['sync_tryb' => 'raport']), $pdo, 'panel');
    $p = bon_synchronizuj_dla_panelu($r);
    $t('raport panelu: tekst, bez e-maili', isset($p['tekst']) && !str_contains(json_encode($p, JSON_UNESCAPED_UNICODE), '@'), json_encode($p, JSON_UNESCAPED_UNICODE));
    // #333: powody techniczne (nazwy pól konfigu, „dziennik”) tylko dla admina; Gabinet i Bon – ogólne zdanie
    $techniczne = '/sync_tryb|easytools_|sync_od|konfigurac|dzienni|Tryb raportu/u';
    $pp = [];
    foreach (['sync_tryb = wylaczony', 'brak easytools_api_key', 'brak easytools_produkt_uuid', 'brak sync_od'] as $pow) {
        $pp[$pow] = [bon_synchronizuj_dla_panelu(['pominieto' => $pow], true)['tekst'], bon_synchronizuj_dla_panelu(['pominieto' => $pow], false)['tekst']];
    }
    $pRaport = [bon_synchronizuj_dla_panelu($r, true)['tekst'], bon_synchronizuj_dla_panelu($r, false)['tekst']];
    $zBledem = ['tryb' => 'bon', 'zamowien' => 2, 'nowe' => [['wynik' => ['bonow' => 1]], ['blad' => 'HTTP 500']]];
    $pBlad = [bon_synchronizuj_dla_panelu($zBledem, true)['tekst'], bon_synchronizuj_dla_panelu($zBledem, false)['tekst']];
    $okAdmin = $okInni = true;
    foreach (array_merge(array_values($pp), [$pRaport, $pBlad]) as [$tA, $tG]) {
        $okAdmin = $okAdmin && preg_match($techniczne, $tA) === 1;
        $okInni = $okInni && $tG !== '' && preg_match($techniczne, $tG) !== 1;
    }
    $t('#333: raport panelu – admin widzi powód techniczny (konfig, tryb raportu, dziennik), Gabinet i Bon ogólne zdanie bez nazw pól',
        $okAdmin && $okInni && str_contains($pBlad[1], 'Nowe bony: 1'), json_encode([$pp, $pRaport, $pBlad], JSON_UNESCAPED_UNICODE));
    $t('#333: „pobieranie już trwa” i liczby nowych bonów takie same dla wszystkich ról',
        bon_synchronizuj_dla_panelu(['pominieto' => 'synchronizacja w toku'], false) === bon_synchronizuj_dla_panelu(['pominieto' => 'synchronizacja w toku'], true)
        && bon_synchronizuj_dla_panelu(['tryb' => 'bon', 'zamowien' => 1, 'nowe' => [['wynik' => ['bonow' => 2]]]], false)
        === bon_synchronizuj_dla_panelu(['tryb' => 'bon', 'zamowien' => 1, 'nowe' => [['wynik' => ['bonow' => 2]]]], true));
    // stan zamówienia dla /bony/dziekujemy (TA-10): pełny adres do 60 min od zapłaty, potem maska; tylko UUID; osobny limit
    $ip = '203.0.113.' . random_int(1, 250);
    $s = bon_stan_zamowienia($k, $pdo, $a['id'], $ip);
    $t('strona podziękowania: bon wysłany → stan wyslany, pełny adres (zapłata 5 min temu), liczba bonów',
        ($s['stan'] ?? '') === 'wyslany' && ($s['adres'] ?? '') === 'sync@example.com' && ($s['adres_pelny'] ?? null) === true && ($s['bonow'] ?? 0) === 1, json_encode($s));
    $s = bon_stan_zamowienia($k, $pdo, strtoupper($a['id']) === $a['id'] ? $a['id'] : $a['id'], $ip, bon_teraz()->modify('+2 hours'));
    $t('strona podziękowania: po 60 min adres zamaskowany, adres_pelny false', ($s['adres'] ?? '') === 's…c@example.com' && ($s['adres_pelny'] ?? null) === false, json_encode($s));
    $s = bon_stan_zamowienia($k, $pdo, $a['id'], $ip, bon_teraz()->modify('+8 days'));
    $t('strona podziękowania: zamówienie starsze niż 7 dni → nieznane, bez adresu', $s === ['stan' => 'nieznane'], json_encode($s));
    $s = bon_stan_zamowienia($k, $pdo, $rabat['id'], $ip);
    $t('strona podziękowania: bon wstrzymany → stan wstrzymany, bez adresu', $s === ['stan' => 'wstrzymany'], json_encode($s));
    $t('strona podziękowania: nieznany UUID → nieznane; zły format → null (bez zapytania)', bon_stan_zamowienia($k, $pdo, bon_test_uuid(), $ip) === ['stan' => 'nieznane']
        && bon_stan_zamowienia($k, $pdo, "x' OR 1=1 --", $ip) === null);
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . ' SET mail_wyslany_at = NULL WHERE easytools_order_id = ?')->execute([$dwa['id']]);
    $s = bon_stan_zamowienia($k, $pdo, $dwa['id'], $ip);
    $t('strona podziękowania: mail jeszcze nie wyszedł → w_toku z adresem, 2 bony', ($s['stan'] ?? '') === 'w_toku' && ($s['bonow'] ?? 0) === 2 && isset($s['adres']), json_encode($s));
    $ip2 = '198.51.100.' . random_int(1, 250);
    $ok = 0;
    $t('limit pytań o stan bonu z ustawień: 30 na minutę z jednego IP (Bartek 26.11)', (int)(bon_ustawienia_repo()['adres_limit_na_minute'] ?? 0) === 30);
    for ($i = 0; $i < 12; $i++) if (bon_stan_zamowienia(array_replace($k, ['adres_limit_na_minute' => 10]), $pdo, $a['id'], $ip2) !== null) $ok++;
    $t('strona podziękowania: limit 10 zapytań na minutę z jednego IP, osobny od verify', $ok === 10 && bon_limit_ok($pdo, $k, $ip2), (string)$ok);
    $t('maska adresu: jedna litera przed @, brak @', bon_adres_maska('k@wp.pl') === 'k…@wp.pl' && bon_adres_maska('zle') === '…');
    $zdarzenia = implode('', array_map('file_get_contents', glob($k['_katalog'] . '/bony-zdarzenia/*.log') ?: []));
    $t('strona podziękowania: adres kupującego nie trafia do dziennika zdarzeń', !str_contains($zdarzenia, 'sync@example.com'));

    $t('nominał po nazwie wariantu: wielkość liter i twarda spacja bez znaczenia', bon_et_nominal($k, "bon 300\u{00A0}ZŁ") === 300 && bon_et_nominal($k, 'Bon 999 zł') === null);

    // --- szablon HTML maila z bonem (wpis 22:47, front #101): wypełnienie jak scripts/mail-bon-podglad.mjs → wypelnij() ---
    $katS = sys_get_temp_dir() . '/bony-szablon-' . bin2hex(random_bytes(4));
    @mkdir($katS, 0700, true);
    $wzor = '<html><head><!-- OBRAZY_BONOW {"kamien:granat":"https://x.test/szaf.png","kamien:zielen":"https://x.test/szm.png","Bon Szmaragdowy":"https://x.test/stary-200.png"} --><title>{{TYTUL}}</title></head><body>'
        . '<span>{{PREHEADER}}</span><h1>{{TYTUL}}</h1><p>{{WSTEP}}</p>'
        . '<!--BON--><div class="bon"><img src="{{OBRAZ}}" alt="{{NAZWA}} {{KWOTA}}"><b>{{NAZWA}}</b> {{KWOTA}} <i>{{KOD}}</i> do {{WAZNY_DO}}<!--DLA--> dla {{DLA}}<!--/DLA--></div><!--/BON-->'
        . '<!--PUSTE_DLA_OD--><!--PUSTE_1-->Na bonie są puste linie<!--/PUSTE_1--><!--PUSTE_N-->Na bonach są puste linie<!--/PUSTE_N--><!--/PUSTE_DLA_OD-->'
        . '<p>ważny {{WAZNOSC}}</p><!--REZYGNACJA--><p>Rezygnacja na /bon</p><!--/REZYGNACJA--></body></html>';
    file_put_contents($katS . '/mail-bon.tpl', $wzor);
    $kS = array_replace($k, ['mail_szablon_html' => true, 'mail_szablon_plik' => $katS . '/mail-bon.tpl', 'mail_zdanie_rezygnacja' => true]);
    $bonS = array_replace($b[0], ['code' => 'EWA-AAAA-1111', 'initial_pln' => '300.00', 'price_id' => 'Bon Rubinowy', 'dla' => 'Ola <b>&', 'od' => 'Ania']);
    $bonS2 = array_replace($b[0], ['code' => 'EWA-BBBB-2222', 'initial_pln' => '400.00', 'price_id' => 'bon-dowolna', 'dla' => null, 'od' => null]);
    [, $tekstS, $htmlS] = bon_mail_tresc($kS, [$bonS]);
    [, , $htmlS2] = bon_mail_tresc(array_replace($kS, ['mail_zdanie_rezygnacja' => false]), [$bonS, $bonS2]);
    [, , $htmlWyl] = bon_mail_tresc(array_replace($kS, ['mail_szablon_html' => false]), [$bonS]);
    [, , $htmlBrak] = bon_mail_tresc(array_replace($kS, ['mail_szablon_plik' => $katS . '/nie-ma.tpl']), [$bonS]);
    $bezZnacznikow = static fn(string $h) => !preg_match('/\{\{[A-Z_]+\}\}|<!--\/?(BON|DLA|PUSTE|REZYGNACJA)|OBRAZY_BONOW/', $h);
    $t('szablon maila: 1 bon – obraz wg kamienia, kwota, numer, „dla” zescape’owane, bez pustych linii, z rezygnacją, bez znaczników',
        $bezZnacznikow($htmlS) && str_contains($htmlS, 'src="https://x.test/szaf.png"') && str_contains($htmlS, 'EWA-AAAA-1111')
        && str_contains($htmlS, 'dla Ola &lt;b&gt;&amp;') && !str_contains($htmlS, 'puste linie') && str_contains($htmlS, 'Rezygnacja na /bon')
        && str_contains($htmlS, 'Twój bon podarunkowy') && str_contains($htmlS, 'ważny 6 miesięcy'), $htmlS);
    $t('szablon maila: 2 bony – dwa bloki, drugi bez „dla”, „Na bonach…”, bez rezygnacji (przełącznik), Szmaragdowy 400 zł z obrazem kamienia (nie starym 200 zł), „6 albo 12”',
        $bezZnacznikow($htmlS2) && substr_count($htmlS2, 'class="bon"') === 2 && substr_count($htmlS2, ' dla ') === 1
        && str_contains($htmlS2, 'Na bonach są puste linie') && !str_contains($htmlS2, 'Na bonie są') && !str_contains($htmlS2, 'Rezygnacja')
        && str_contains($htmlS2, 'https://x.test/szm.png') && !str_contains($htmlS2, 'stary-200') && str_contains($htmlS2, 'Twoje bony podarunkowe')
        && str_contains($htmlS2, 'ważny 6 albo 12 miesięcy'), $htmlS2);
    $wzorStary = str_replace('"kamien:granat":"https://x.test/szaf.png","kamien:zielen":"https://x.test/szm.png",', '', $wzor);
    file_put_contents($katS . '/stary.tpl', $wzorStary);
    [, , $htmlStary] = bon_mail_tresc(array_replace($kS, ['mail_szablon_plik' => $katS . '/stary.tpl']), [array_replace($bonS2, ['initial_pln' => '500.00'])]);
    $t('szablon maila bez kluczy „kamien:” (stara mapa po nazwach) → HTML zapasowy, bez złego obrazu, mail wychodzi',
        !str_contains($htmlStary, 'x.test') && str_contains($htmlStary, 'EWA-BBBB-2222'), $htmlStary);
    @unlink($katS . '/stary.tpl');
    $t('szablon maila: przełącznik wyłączony albo brak pliku → dotychczasowy HTML; tekst maila bez zmian',
        !str_contains($htmlWyl, 'x.test') && !str_contains($htmlBrak, 'x.test') && str_contains($htmlWyl, 'EWA-AAAA-1111') && str_contains($tekstS, 'EWA-AAAA-1111'));
    // prawdziwy szablon z buildu (dist lokalnie, obok lib/ na OVH) – gdy jest
    $prawdziwy = is_file(__DIR__ . '/mail-bon.tpl') ? __DIR__ . '/mail-bon.tpl' : dirname(__DIR__, 4) . '/dist/api/bon/lib/mail-bon.tpl';
    if (is_file($prawdziwy)) {
        [, , $hP] = bon_mail_tresc(array_replace($kS, ['mail_szablon_plik' => $prawdziwy]), [$bonS, $bonS2]);
        // wpis 21:47: obrazy po kamieniu („kamien:<kolor>”) dodaje Front 1 w mail-bon.tpl.ts; do tego czasu prawdziwy szablon = HTML zapasowy
        if (str_contains((string)file_get_contents($prawdziwy), '"kamien:')) {
            $t('szablon maila (prawdziwy z buildu): 2 bony wypełnione, bez znaczników, obrazy z img/bon-mail', $bezZnacznikow($hP)
                && substr_count($hP, '/img/bon-mail/') >= 2 && str_contains($hP, 'EWA-BBBB-2222') && str_contains($hP, 'Bon Szmaragdowy'), mb_substr($hP, 0, 300));
        } else {
            $t('szablon maila (prawdziwy z buildu, jeszcze bez kluczy „kamien:” – czeka na Front 1) → HTML zapasowy, mail wychodzi',
                str_contains($hP, 'EWA-BBBB-2222') && str_contains($hP, 'Bon Szmaragdowy') && !str_contains($hP, '{{'), mb_substr($hP, 0, 300));
        }
    }
    @unlink($katS . '/mail-bon.tpl');
    @rmdir($katS);

    return $wyniki;
}
