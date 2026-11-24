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
    $ks = array_replace($k, [
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
    $zdanie = 'Rezygnacja w ciągu 14 dni od zapłaty: najszybciej na stronie ewa-gabinet.pl/bon, pod wynikiem sprawdzenia bonu.';
    $kTel = array_replace($k, ['telefon_pomoc_bony' => '506 102 340']);
    [, $bezRez] = bon_mail_tresc(array_replace($kTel, ['mail_zdanie_rezygnacja' => false]), $b);
    [, $zRez, $zRezHtml] = bon_mail_tresc(array_replace($kTel, ['mail_zdanie_rezygnacja' => true]), $b);
    $t('mail: zdanie o rezygnacji tylko z przełącznikiem, w tekście i HTML, tuż przed „Pytania o bon”; ważny „od dnia zapłaty”',
        !str_contains($bezRez, 'Rezygnacja w ciągu') && str_contains($zRez, $zdanie . "\nPytania o bon")
        && str_contains($zRezHtml, 'ewa-gabinet.pl/bon, pod wynikiem') && str_contains($zRez, 'od dnia zapłaty') && !str_contains($zRez, 'od dnia zakupu'));

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
        $linia('Bon Szmaragdowy', 1, 20000), $linia('Bon Bursztynowy', 2, 100000), $linia('Dodatek', 1, 5000, true)]]);
    $przedM = $ileMaili();
    [$rM, $zad] = bon_synchronizuj($ks, $pdo, 'cron');
    foreach ($zad as $z) $z();
    $bm = bon_test_bony($k, $pdo, $mieszany['id']);
    usort($bm, static fn($a, $b) => $a['nr_w_zamowieniu'] <=> $b['nr_w_zamowieniu']);
    $t('koszyk mieszany 200 + 2×500 (+ order bump): 3 bony 200/500/500, numery 1–3, wszystkie ważne, zapłacono po pozycjach',
        count($bm) === 3 && array_map(static fn($b) => (float)$b['initial_pln'], $bm) === [200.0, 500.0, 500.0]
        && array_column($bm, 'nr_w_zamowieniu') == [1, 2, 3] && array_unique(array_column($bm, 'status')) === ['valid']
        && (float)$bm[1]['paid_pln'] === 500.0 && $bm[0]['price_id'] === 'Bon Szmaragdowy' && $bm[2]['price_id'] === 'Bon Bursztynowy',
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

    $czesc = $dodaj(['amount' => 55000, 'quantity' => 2, 'line_items' => [$linia('Bon Rubinowy', 1, 30000), $linia('Bon Diamentowy', 1, 25000)]]);
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
        && str_contains($powNowe, 'Część bonów') && str_contains($powNowe, '• Bon Diamentowy: 1 szt.'), $powNowe);

    // nowe nazwy wariantów (Bartek 2026-11-24): ten sam bon co pod starą nazwą; nazwa w mailu wg rodzaju bonu
    $rub = $dodaj(['amount' => 30000, 'line_items' => [['variant_name' => 'Bon Rubinowy', 'amount' => 30000, 'amount_total' => 30000]]]);
    $szaf = $dodaj(['amount' => 50000, 'line_items' => [['variant_name' => 'bon szafirowy', 'amount' => 50000, 'amount_total' => 50000]]]);
    $nowaNieznana = $dodaj(['line_items' => [['variant_name' => 'Bon Diamentowy']]]);
    [, $zad] = bon_synchronizuj($ks, $pdo, 'cron');
    $brub = bon_test_bony($k, $pdo, $rub['id']);
    $bsz = bon_test_bony($k, $pdo, $szaf['id']);
    $bnn = bon_test_bony($k, $pdo, $nowaNieznana['id']);
    $t('nowa nazwa „Bon Rubinowy” → bon 300 zł valid', $brub && $brub[0]['initial_pln'] == 300 && $brub[0]['status'] === 'valid');
    $t('nowa nazwa „bon szafirowy” (małe litery) → dowolna kwota 500 zł valid', $bsz && $bsz[0]['initial_pln'] == 500 && $bsz[0]['status'] === 'valid');
    $t('nieznana nowa nazwa → held', $bnn && $bnn[0]['status'] === 'held' && !isset($zad[$nowaNieznana['id']]));
    $t('nazwa bonu: stara i nowa nazwa wariantu dają tę samą', bon_nazwa($k, $b2[0]) === 'Bon Szmaragdowy' && bon_nazwa($k, $brub[0]) === 'Bon Rubinowy'
        && bon_nazwa($k, ['price_id' => 'Bon 300 zł', 'initial_pln' => '300.00']) === 'Bon Rubinowy');
    $t('nazwa bonu: dowolna kwota → Szafirowy także przy 500 zł i 300 zł (stara nazwa)', bon_nazwa($k, $bsz[0]) === 'Bon Szafirowy' && bon_nazwa($k, $bd[0]) === 'Bon Szafirowy'
        && bon_nazwa($k, ['price_id' => 'Dowolna kwota (od 180 zł)', 'initial_pln' => '300.00']) === 'Bon Szafirowy');
    $t('nazwa bonu: webhook (bon-500, bon-dowolna) i nieznany rodzaj', bon_nazwa($k, ['price_id' => 'bon-500', 'initial_pln' => '500.00']) === 'Bon Bursztynowy'
        && bon_nazwa($k, ['price_id' => 'bon-dowolna', 'initial_pln' => '200.00']) === 'Bon Szafirowy'
        && bon_nazwa($k, ['price_id' => '', 'initial_pln' => '250.00']) === 'Bon podarunkowy');
    [, $tekstMaila, $htmlMaila] = bon_mail_tresc($k, $brub);
    $t('mail: „Bon Rubinowy o wartości 300 zł” w tekście i HTML', str_contains($tekstMaila, "Bon Rubinowy o wartości 300\u{00A0}zł, numer " . $brub[0]['code'])
        && str_contains($htmlMaila, "Bon Rubinowy o wartości 300\u{00A0}zł"), $tekstMaila);
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
    for ($i = 0; $i < 12; $i++) if (bon_stan_zamowienia(array_replace($k, ['adres_limit_na_minute' => 10]), $pdo, $a['id'], $ip2) !== null) $ok++;
    $t('strona podziękowania: limit 10 zapytań na minutę z jednego IP, osobny od verify', $ok === 10 && bon_limit_ok($pdo, $k, $ip2), (string)$ok);
    $t('maska adresu: jedna litera przed @, brak @', bon_adres_maska('k@wp.pl') === 'k…@wp.pl' && bon_adres_maska('zle') === '…');
    $zdarzenia = implode('', array_map('file_get_contents', glob($k['_katalog'] . '/bony-zdarzenia/*.log') ?: []));
    $t('strona podziękowania: adres kupującego nie trafia do dziennika zdarzeń', !str_contains($zdarzenia, 'sync@example.com'));

    $t('nominał po nazwie wariantu: wielkość liter i twarda spacja bez znaczenia', bon_et_nominal($k, "bon 300\u{00A0}ZŁ") === 300 && bon_et_nominal($k, 'Bon 999 zł') === null);

    return $wyniki;
}
