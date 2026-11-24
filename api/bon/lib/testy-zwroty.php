<?php
// Testy P-11 (2026-11-24): zwrot pieniędzy z panelu przez API Easytools (lib/zwroty.php). API podstawione ($k['easytools_http']),
// żadnych prawdziwych zwrotów. Woła je bon_testy() – SQLite i OVH (test-ovh.php).

declare(strict_types=1);
require_once __DIR__ . '/zwroty.php';
require_once __DIR__ . '/panel.php';

function bon_testy_zwroty(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $sKasa = ['login' => 'kasa', 'imie' => 'Kasa', 'rola' => 'realizacja', 'csrf' => 'x'];
    $sEwa = ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'rezygnacje' => true, 'csrf' => 'x'];
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    $tabB = bon_tabela($k, 'vouchers');
    $tabW = bon_tabela($k, 'voucher_withdrawals');

    // udawane API: transakcje {total, refunded_amount, remaining_refundable, refunds[]}; tryb POST: 202 | 500 | 422 | wyjatek
    $tr = [];
    $tryb = '202';
    $posty = [];
    $gety = 0; // REVIEW nr 5: liczba GET do Easytools
    $kz = array_replace($k, ['easytools_api_key' => 'klucz-testowy', 'easytools_http' => static function (string $metoda, string $sciezka, array $q, ?array $body) use (&$tr, &$tryb, &$posty, &$gety): array {
        if ($metoda === 'GET') $gety++;
        if ($metoda === 'GET' && preg_match('#^/transactions/([^/]+)$#', $sciezka, $m)) return isset($tr[$m[1]]) ? [200, $tr[$m[1]]] : [404, ['message' => 'not found']];
        if ($metoda === 'POST' && preg_match('#^/transactions/([^/]+)/refunds$#', $sciezka, $m)) {
            $posty[] = ['tr' => $m[1], 'body' => $body];
            if ($tryb === 'wyjatek') throw new RuntimeException('API Easytools: symulowane zerwanie');
            if ($tryb === '500') return [500, ['message' => 'Server Error']];
            if ($tryb === '422') return [422, ['message' => 'Refund amount exceeds the remaining refundable amount 0']];
            $tr[$m[1]]['remaining_refundable'] -= (int)$body['amount']; // zwrot w toku pomniejsza remaining
            // REVIEW nr 2: Easytools przyjął zwrot, odpowiedź zginęła (timeout po wysłaniu)
            if ($tryb === 'przyjete-bez-odpowiedzi') throw new RuntimeException('API Easytools: Operation timed out after 15001 ms');
            return [202, ['message' => 'Refund submitted.', 'amount' => $body['amount'], 'currency' => 'pln']];
        }
        return [404, ['message' => 'nieznana ścieżka']];
    }]);
    // bon z rezygnacją: webhook → transakcja → rezygnacja zarejestrowana w panelu (admin)
    $bonZRezygnacja = static function (int $zl, int $remaining = -1) use ($kz, $pdo, $tabB, $sAdmin, &$tr): array {
        $p = bon_test_payload(['price_custom_id' => "bon-$zl", 'amount_paid' => $zl]);
        bon_test_webhook($kz, $pdo, $p);
        $b = bon_test_bony($kz, $pdo, $p['order_id'])[0];
        $id = bon_test_uuid();
        $tr[$id] = ['id' => $id, 'total' => $zl * 100, 'refunded_amount' => 0, 'remaining_refundable' => $remaining >= 0 ? $remaining : $zl * 100,
            'refund_state' => 'none', 'refundable' => true, 'currency' => 'pln', 'refunds' => []];
        $pdo->prepare("UPDATE $tabB SET easytools_transaction_id = ? WHERE id = ?")->execute([$id, $b['id']]);
        $dzis = bon_teraz()->format('Y-m-d');
        bon_panel_admin($kz, $pdo, $sAdmin, ['kod' => $b['code'], 'akcja' => 'rezygnacja', 'kanal' => 'mail', 'data' => $dzis, 'otrzymano' => $dzis]);
        return ['code' => $b['code'], 'id' => (int)$b['id'], 'tr' => $id];
    };
    $stan = static function (int $id) use ($pdo, $tabW, $tabB): array {
        $st = $pdo->prepare("SELECT w.zwrot_stan, w.zwrot_kwota_pln, w.zwrot_blad, b.status FROM $tabW w JOIN $tabB b ON b.id = w.voucher_id WHERE w.voucher_id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: [];
    };

    // uprawnienia i panel
    $b = $bonZRezygnacja(300);
    [$h] = bon_panel_zwroc($kz, $pdo, $sKasa, ['kod' => $b['code']]);
    [, $hE] = bon_panel_historia($kz, $pdo, $sEwa, $b['code']);
    [, $hK] = bon_panel_historia($kz, $pdo, $sKasa, $b['code']);
    $t('zwrot: kasa 403; Ewa widzi „Zwróć pieniądze” (kwota, zamówienie, e-mail) i linię płatności; kasa nie',
        $h === 403 && ($hE['zwrot']['kwota'] ?? '') === '300.00' && !empty($hE['zwrot']['email']) && ($hE['platnosc']['zaplacono'] ?? '') === '300.00'
        && !isset($hK['zwrot']) && !isset($hK['platnosc']), json_encode([$hE['zwrot'] ?? null, $hE['platnosc'] ?? null]));

    // zlecenie: stan „zlecony” przed API, kwota w groszach, drugi klik 409
    $posty = [];
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $b['code']]);
    $s = $stan($b['id']);
    $t('zwrot: Ewa zleca → 202, stan zlecony, POST amount 30000 groszy, kwota 300.00, bon nadal withdrawn do potwierdzenia',
        $h === 200 && ($o['kwota'] ?? '') === '300.00' && $s['zwrot_stan'] === 'zlecony' && (float)$s['zwrot_kwota_pln'] === 300.0 && $s['status'] === 'withdrawn'
        && count($posty) === 1 && ($posty[0]['body']['amount'] ?? 0) === 30000, json_encode([$o, $s, $posty]));
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sAdmin, ['kod' => $b['code']]);
    $t('zwrot: drugi klik / drugie konto → 409 „Zwrot już zlecony”, bez drugiego POST', $h === 409 && str_contains((string)($o['blad'] ?? ''), 'już zlecony') && count($posty) === 1);

    // potwierdzenie w cronie: zwrot na liście refunds[] po zleceniu
    $tr[$b['tr']]['refunded_amount'] = 30000;
    $tr[$b['tr']]['refunds'][] = ['amount' => 30000, 'refunded_at' => (new DateTimeImmutable('+1 minute'))->format('c')];
    $r = bon_zwroty_potwierdz($kz, $pdo);
    $s = $stan($b['id']);
    $t('zwrot: cron potwierdza (refunds[] z kwotą po zleceniu) → potwierdzony, bon refunded', $r['potwierdzono'] >= 1 && $s['zwrot_stan'] === 'potwierdzony' && $s['status'] === 'refunded', json_encode([$r, $s]));

    // REVIEW nr 1 (24.11): dawniej „kwota = min(kwota z rezygnacji, remaining_refundable) → 50.00”. Ten test utrwalał błąd:
    // 250 zł oddane w Easytools bez zlecenia z panelu to nie „część należnej kwoty”, tylko zwrot, o którym panel nic nie wie.
    // Teraz: 409 z nie_zlecony, bez POST – Ewa sprawdza w Easytools i oznacza zwrot ręcznie.
    $b2 = $bonZRezygnacja(300, 5000);
    $posty = [];
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sAdmin, ['kod' => $b2['code']]);
    $t('zwrot (REVIEW 1): 250 zł zwrócone w Easytools bez zlecenia z panelu → 409 nie_zlecony, bez POST',
        $h === 409 && ($o['nie_zlecony'] ?? false) && !$posty && empty($o['juz_zwrocony']), json_encode([$h, $o, $posty]));

    // już zwrócone w Easytools (remaining 0) → juz_zwrocony, stan bez zmian; potem „Oznacz zwrot” przez Ewę
    $b3 = $bonZRezygnacja(200, 0);
    $posty = [];
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $b3['code']]);
    $t('zwrot (16:19): nic do zwrotu w Easytools → juz_zwrocony, bez POST, stan nadal oczekuje', $h === 409 && ($o['juz_zwrocony'] ?? false) && !$posty && $stan($b3['id'])['zwrot_stan'] === 'oczekuje');
    [$h] = bon_panel_admin($kz, $pdo, $sEwa, ['kod' => $b3['code'], 'akcja' => 'zwrot', 'powod' => 'zwrot zrobiony w Easytools']);
    $t('zwrot: Ewa „Oznacz zwrot” na bonie z rezygnacją → refunded', $h === 200 && $stan($b3['id'])['status'] === 'refunded');

    // błąd API → nieudany; ponowienie tylko po potwierdzeniu „sprawdzone”
    $b4 = $bonZRezygnacja(300);
    $tryb = '500';
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $b4['code']]);
    $s = $stan($b4['id']);
    $t('zwrot: Easytools 500 → 502 z komunikatem, stan nieudany z opisem', $h === 502 && $s['zwrot_stan'] === 'nieudany' && str_contains((string)$s['zwrot_blad'], 'HTTP 500'));
    $tryb = '202';
    [$h1] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $b4['code']]);
    [$h2] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $b4['code'], 'sprawdzone' => true]);
    $t('zwrot: ponowienie bez „sprawdzone” → 409, ze „sprawdzone” → 200', $h1 === 409 && $h2 === 200 && $stan($b4['id'])['zwrot_stan'] === 'zlecony');

    // zerwanie połączenia przy POST → nieudany z ostrzeżeniem „mógł pójść”
    $b5 = $bonZRezygnacja(200);
    $tryb = 'wyjatek';
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sAdmin, ['kod' => $b5['code']]);
    $tryb = '202';
    $t('zwrot: brak odpowiedzi Easytools → 502 „zwrot mógł pójść”, stan nieudany', $h === 502 && str_contains((string)($o['blad'] ?? ''), 'mógł pójść') && $stan($b5['id'])['zwrot_stan'] === 'nieudany');

    // bon bez rezygnacji: zwrot z panelu niemożliwy; Ewa nie oznaczy zwrotu bonu bez rezygnacji
    $p6 = bon_test_payload(['price_custom_id' => 'bon-200', 'amount_paid' => 200]);
    bon_test_webhook($kz, $pdo, $p6);
    $b6 = bon_test_bony($kz, $pdo, $p6['order_id'])[0];
    $id6 = bon_test_uuid();
    $tr[$id6] = ['id' => $id6, 'total' => 20000, 'refunded_amount' => 20000, 'remaining_refundable' => 0, 'refund_state' => 'full', 'refunds' => []];
    $pdo->prepare("UPDATE $tabB SET easytools_transaction_id = ? WHERE id = ?")->execute([$id6, $b6['id']]);
    [$h] = bon_panel_admin($kz, $pdo, $sEwa, ['kod' => $b6['code'], 'akcja' => 'zwrot', 'powod' => 'x']);
    // REVIEW nr 5: przy ważnym bonie płatność dopiero na „Sprawdź płatność” (flaga z historia.php?platnosc=1)
    [, $hA] = bon_panel_historia(array_replace($kz, ['_platnosc_z_api' => true]), $pdo, $sAdmin, $b6['code']);
    $t('zwrot: Ewa nie oznaczy zwrotu bonu bez rezygnacji (403); A1 – pieniądze zwrócone w Easytools, bon ważny → ostrzeżenie w panelu',
        $h === 403 && str_contains((string)($hA['platnosc']['ostrzezenie'] ?? ''), 'bon nadal działa') && array_key_exists('zwrot', $hA) && $hA['zwrot'] === null, json_encode($hA['platnosc'] ?? null));

    // „Oznacz zwrot” admina na bonie bez rezygnacji (wpis 16:47; od 18:00 proporcjonalnie – nieużyty bon 200 za 8 zł nadal 8 zł)
    $p7 = bon_test_payload(['price_custom_id' => 'bon-200', 'amount_paid' => 200]);
    bon_test_webhook($kz, $pdo, $p7);
    $b7 = bon_test_bony($kz, $pdo, $p7['order_id'])[0];
    $pdo->prepare("UPDATE $tabB SET paid_pln = '8.00' WHERE id = ?")->execute([$b7['id']]);
    [$h, $o] = bon_panel_admin($kz, $pdo, $sAdmin, ['kod' => $b7['code'], 'akcja' => 'zwrot', 'powod' => 'awaryjnie']);
    $t('Oznacz zwrot bez rezygnacji (admin): w historii „do zwrotu 8.00 zł” (saldo 200, wpłata 8)', $h === 200 && str_contains((string)($o['opis'] ?? ''), 'do zwrotu 8.00 zł'), (string)($o['opis'] ?? ''));

    // wpis 18:00: zwrot proporcjonalny do zapłaconej ceny – bon 200 zł kupiony za 180 zł, wykorzystane 100 zł → 90 zł, nie 100 zł
    $bonRabat = static function () use ($kz, $pdo, $tabB, $sEwa, &$tr): array {
        $p = bon_test_payload(['price_custom_id' => 'bon-200', 'amount_paid' => 180, 'promo_code' => 'RABAT10']);
        bon_test_webhook(array_replace($kz, ['prog_wstrzymania' => 0]), $pdo, $p);
        $b = bon_test_bony($kz, $pdo, $p['order_id'])[0];
        $id = bon_test_uuid();
        $tr[$id] = ['id' => $id, 'total' => 18000, 'refunded_amount' => 0, 'remaining_refundable' => 18000, 'refund_state' => 'none', 'refundable' => true, 'currency' => 'pln', 'refunds' => []];
        $pdo->prepare("UPDATE $tabB SET easytools_transaction_id = ? WHERE id = ?")->execute([$id, $b['id']]);
        bon_panel_realizuj($kz, $pdo, $sEwa, ['kod' => $b['code'], 'kwota_reczna' => '100', 'client_token' => bin2hex(random_bytes(12))]);
        return ['code' => $b['code'], 'id' => (int)$b['id']];
    };
    $b8 = $bonRabat();
    [, $h8] = bon_panel_historia($kz, $pdo, $sEwa, $b8['code']);
    $dzis8 = bon_teraz()->format('Y-m-d');
    bon_panel_admin($kz, $pdo, $sEwa, ['kod' => $b8['code'], 'akcja' => 'rezygnacja', 'kanal' => 'mail', 'data' => $dzis8, 'otrzymano' => $dzis8]);
    [, $h8r] = bon_panel_historia($kz, $pdo, $sEwa, $b8['code']);
    $posty = [];
    $tryb = '202';
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $b8['code']]);
    $t('proporcjonalnie (panel): podgląd 90.00 z „przeliczone”, rezygnacja 90.00 z saldem 100.00, „Zwróć pieniądze” → POST 9000 groszy',
        ($h8['rezygnacja_kwota'] ?? '') === '90.00' && ($h8['rezygnacja_kwota_przeliczona'] ?? null) === true
        && ($h8r['rezygnacja']['kwota'] ?? '') === '90.00' && ($h8r['rezygnacja']['saldo'] ?? '') === '100.00' && ($h8r['rezygnacja']['przeliczona'] ?? null) === true
        && ($h8r['zwrot']['kwota'] ?? '') === '90.00' && $h === 200 && ($posty[0]['body']['amount'] ?? 0) === 9000,
        json_encode([$h8['rezygnacja_kwota'] ?? null, $h8r['rezygnacja'] ?? null, $o, $posty]));
    // „Oznacz zwrot” admina bez rezygnacji na takim samym bonie → w historii 90.00 z dopiskiem
    $b9 = $bonRabat();
    [$h, $o] = bon_panel_admin($kz, $pdo, $sAdmin, ['kod' => $b9['code'], 'akcja' => 'zwrot', 'powod' => 'awaryjnie']);
    $t('proporcjonalnie: Oznacz zwrot bez rezygnacji → „do zwrotu 90.00 zł (przeliczone do zapłaconej ceny)”', $h === 200
        && str_contains((string)($o['opis'] ?? ''), 'do zwrotu 90.00 zł (przeliczone do zapłaconej ceny)'), (string)($o['opis'] ?? ''));

    // --- REVIEW nr 1–2 (24.11, testy-dowody R1, R1b, R2 z przeglądu kodu da543d3) ---
    // zamówienie z $ilosc bonami po $zl zł w jednej transakcji Easytools
    $zamowienie = static function (int $zl, int $ilosc) use ($kz, $pdo, $tabB, &$tr): array {
        $p = bon_test_payload(['price_custom_id' => "bon-$zl", 'amount_paid' => $zl * $ilosc, 'order_amount' => $zl * $ilosc, 'quantity' => $ilosc]);
        bon_test_webhook($kz, $pdo, $p);
        $bony = bon_test_bony($kz, $pdo, $p['order_id']);
        $id = bon_test_uuid();
        $tr[$id] = ['id' => $id, 'total' => $zl * $ilosc * 100, 'refunded_amount' => 0, 'remaining_refundable' => $zl * $ilosc * 100,
            'refund_state' => 'none', 'refundable' => true, 'currency' => 'pln', 'refunds' => []];
        foreach ($bony as $b) $pdo->prepare("UPDATE $tabB SET easytools_transaction_id = ? WHERE id = ?")->execute([$id, $b['id']]);
        return ['bony' => array_map(static fn($b) => ['code' => $b['code'], 'id' => (int)$b['id']], $bony), 'tr' => $id];
    };
    $rezygnuj = static function (string $kod) use ($kz, $pdo, $sAdmin) {
        $dzis = bon_teraz()->format('Y-m-d');
        return bon_panel_admin($kz, $pdo, $sAdmin, ['kod' => $kod, 'akcja' => 'rezygnacja', 'kanal' => 'mail', 'data' => $dzis, 'otrzymano' => $dzis]);
    };
    $zlecono = static function (int $id) use ($pdo, $tabW): string {
        $st = $pdo->prepare("SELECT zwrot_zlecono_at FROM $tabW WHERE voucher_id = ?");
        $st->execute([$id]);
        return (string)$st->fetchColumn();
    };

    // R1: bon 300 zł, wykorzystane 100 zł, rezygnacja (200 zł); Ewa oddaje 200 zł ręcznie w Easytools bez „Oznacz zwrot”
    $z = $zamowienie(300, 1);
    $kod = $z['bony'][0]['code'];
    bon_panel_realizuj($kz, $pdo, $sEwa, ['kod' => $kod, 'kwota_reczna' => '100', 'client_token' => bin2hex(random_bytes(12))]);
    $rezygnuj($kod);
    $tr[$z['tr']] = array_replace($tr[$z['tr']], ['refunded_amount' => 20000, 'remaining_refundable' => 10000, 'refund_state' => 'partial',
        'refunds' => [['amount' => 20000, 'refunded_at' => bon_teraz()->format('c')]]]);
    $posty = [];
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $kod]);
    [, $hR1] = bon_panel_historia($kz, $pdo, $sEwa, $kod);
    $t('REVIEW R1: zwrot ręczny 200 zł w Easytools, potem „Zwróć pieniądze” → 409 nie_zlecony, bez drugiego zwrotu; panel ukrywa przycisk z powodem',
        $h === 409 && ($o['nie_zlecony'] ?? false) && !$posty && !empty($hR1['zwrot']['blokada']) && ($hR1['zwrot']['oznacz'] ?? false),
        json_encode([$h, $o, $posty, $hR1['zwrot'] ?? null], JSON_UNESCAPED_UNICODE));

    // R1b: 2 bony po 200 zł, rezygnacja na A, A oddany ręcznie → „Zwróć” na A bez POST
    $z = $zamowienie(200, 2);
    [$bA, $bB] = $z['bony'];
    $rezygnuj($bA['code']);
    $tr[$z['tr']] = array_replace($tr[$z['tr']], ['refunded_amount' => 20000, 'remaining_refundable' => 20000, 'refund_state' => 'partial',
        'refunds' => [['amount' => 20000, 'refunded_at' => bon_teraz()->format('c')]]]);
    $posty = [];
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sAdmin, ['kod' => $bA['code']]);
    $t('REVIEW R1b: 2 bony, A oddany ręcznie, „Zwróć” na A → 409 nie_zlecony, bez POST', $h === 409 && ($o['nie_zlecony'] ?? false) && !$posty,
        json_encode([$h, $o, $posty], JSON_UNESCAPED_UNICODE));
    // po „Oznacz zwrot” na A (zwrot ręczny znany panelowi) zwrot bonu B przyciskiem przechodzi: 200 zł, nie mniej i nie więcej
    bon_panel_admin($kz, $pdo, $sEwa, ['kod' => $bA['code'], 'akcja' => 'zwrot', 'powod' => 'zwrot zrobiony w Easytools']);
    $rezygnuj($bB['code']);
    $posty = [];
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $bB['code']]);
    $t('REVIEW R1b: po „Oznacz zwrot” na A zwrot B przyciskiem → POST 20000', $h === 200 && count($posty) === 1 && ($posty[0]['body']['amount'] ?? 0) === 20000,
        json_encode([$h, $o, $posty], JSON_UNESCAPED_UNICODE));

    // R2: 2 bony, Easytools przyjął zwrot A, odpowiedź zginęła → „Ponów” nie wysyła drugiego POST; stan „zlecony” z pierwotną datą
    $z = $zamowienie(200, 2);
    [$bA, $bB] = $z['bony'];
    $rezygnuj($bA['code']);
    $posty = [];
    $tryb = 'przyjete-bez-odpowiedzi';
    [$h1] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $bA['code']]);
    $tryb = '202';
    $przed = $zlecono($bA['id']);
    [$h2, $o2] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $bA['code'], 'sprawdzone' => true]);
    $t('REVIEW R2: HTTP 0 (zwrot przyjęty), „Ponów” przy 2 bonach → bez drugiego POST, stan zlecony, data zlecenia bez zmian',
        $h1 === 502 && $h2 === 200 && ($o2['przyjety'] ?? false) && count($posty) === 1 && $stan($bA['id'])['zwrot_stan'] === 'zlecony' && $zlecono($bA['id']) === $przed,
        json_encode([$h1, $h2, $o2, $posty, $tr[$z['tr']]['remaining_refundable']], JSON_UNESCAPED_UNICODE));
    // cron potwierdza ten zwrot, gdy Easytools go zaksięguje
    $tr[$z['tr']]['refunded_amount'] = 20000;
    $tr[$z['tr']]['refunds'][] = ['amount' => 20000, 'refunded_at' => (new DateTimeImmutable('+1 minute'))->format('c')];
    bon_zwroty_potwierdz($kz, $pdo);
    $t('REVIEW R2: cron potwierdza przyjęty zwrot → potwierdzony, bon refunded', $stan($bA['id'])['zwrot_stan'] === 'potwierdzony' && $stan($bA['id'])['status'] === 'refunded');

    // R2, jeden bon: ten sam przebieg → bez drugiego POST i stan „zlecony” (dawniej juz_zwrocony → „Oznacz zwrot”, bez potwierdzenia z crona)
    $z = $zamowienie(200, 1);
    $rezygnuj($z['bony'][0]['code']);
    $posty = [];
    $tryb = 'przyjete-bez-odpowiedzi';
    bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $z['bony'][0]['code']]);
    $tryb = '202';
    [$h2, $o2] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $z['bony'][0]['code'], 'sprawdzone' => true]);
    $t('REVIEW R2 (1 bon): HTTP 0 (zwrot przyjęty), „Ponów” → bez drugiego POST, stan zlecony', $h2 === 200 && ($o2['przyjety'] ?? false) && count($posty) === 1
        && $stan($z['bony'][0]['id'])['zwrot_stan'] === 'zlecony', json_encode([$h2, $o2, $posty], JSON_UNESCAPED_UNICODE));

    // 2 bony, HTTP 0, zwrot NIE poszedł → „Ponów” zablokowany (409 ponowienie_zablokowane), panel: tylko „Oznacz zwrot” + „zgłoś Bartkowi”
    $z = $zamowienie(200, 2);
    [$bA, $bB] = $z['bony'];
    $rezygnuj($bA['code']);
    $posty = [];
    $tryb = 'wyjatek';
    bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $bA['code']]);
    $tryb = '202';
    $posty = [];
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $bA['code'], 'sprawdzone' => true]);
    [, $hP] = bon_panel_historia($kz, $pdo, $sEwa, $bA['code']);
    $t('REVIEW nr 2: 2 bony po HTTP 0 → „Ponów” zablokowany (409, bez POST); panel bez „Ponów”, z „Oznacz zwrot” i „zgłoś Bartkowi”',
        $h === 409 && ($o['ponowienie_zablokowane'] ?? false) && !$posty && ($hP['zwrot']['oznacz'] ?? false)
        && str_contains((string)($hP['zwrot']['blokada'] ?? ''), 'Bartk'), json_encode([$h, $o, $hP['zwrot'] ?? null], JSON_UNESCAPED_UNICODE));
    // Ewa zwraca A ręcznie w Easytools i klika „Oznacz zwrot” → rezygnacja zamknięta także ze stanu „nieudany”; B można zwrócić przyciskiem
    $tr[$z['tr']] = array_replace($tr[$z['tr']], ['refunded_amount' => 20000, 'remaining_refundable' => 20000, 'refund_state' => 'partial',
        'refunds' => [['amount' => 20000, 'refunded_at' => bon_teraz()->format('c')]]]);
    [$hO] = bon_panel_admin($kz, $pdo, $sEwa, ['kod' => $bA['code'], 'akcja' => 'zwrot', 'powod' => 'zwrot zrobiony w Easytools']);
    $rezygnuj($bB['code']);
    $posty = [];
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sEwa, ['kod' => $bB['code']]);
    $t('REVIEW nr 2: „Oznacz zwrot” zamyka rezygnację ze stanu nieudany (zlecony, ręcznie); potem zwrot B → POST 20000',
        $hO === 200 && $stan($bA['id'])['zwrot_stan'] === 'zlecony' && $h === 200 && ($posty[0]['body']['amount'] ?? 0) === 20000,
        json_encode([$hO, $stan($bA['id']), $h, $o, $posty], JSON_UNESCAPED_UNICODE));

    // przycisk pokazuje kwotę, która naprawdę wyjdzie (Easytools ma mniej do zwrotu niż kwota z rezygnacji, bez nieznanego zwrotu)
    $z = $zamowienie(200, 1);
    $rezygnuj($z['bony'][0]['code']);
    [, $hK] = bon_panel_historia($kz, $pdo, $sEwa, $z['bony'][0]['code']);
    $t('REVIEW nr 1: przycisk bez nieznanego zwrotu → kwota z rezygnacji, bez blokady', ($hK['zwrot']['kwota'] ?? '') === '200.00' && empty($hK['zwrot']['blokada']),
        json_encode($hK['zwrot'] ?? null, JSON_UNESCAPED_UNICODE));

    // REVIEW nr 5 (R5): otwarcie zwykłego, ważnego bonu przez Ewę nie czeka na Easytools; „Sprawdź płatność” (flaga) i bon z rezygnacją – tak
    $z5 = $zamowienie(200, 1);
    $gety = 0;
    [, $h5] = bon_panel_historia($kz, $pdo, $sEwa, $z5['bony'][0]['code']);
    $bezApi = $gety;
    $gety = 0;
    [, $h5b] = bon_panel_historia(array_replace($kz, ['_platnosc_z_api' => true]), $pdo, $sEwa, $z5['bony'][0]['code']);
    $naZadanie = $gety;
    $rezygnuj($z5['bony'][0]['code']);
    $gety = 0;
    [, $h5c] = bon_panel_historia($kz, $pdo, $sEwa, $z5['bony'][0]['code']);
    $t('REVIEW R5: ważny bon – 0 wywołań API, platnosc.na_zadanie; po „Sprawdź” – 1 wywołanie i kwoty; bon z rezygnacją – płatność od razu (1 wywołanie)',
        $bezApi === 0 && ($h5['platnosc']['na_zadanie'] ?? false) && $naZadanie === 1 && ($h5b['platnosc']['zaplacono'] ?? '') === '200.00'
        && $gety === 1 && ($h5c['platnosc']['zaplacono'] ?? '') === '200.00', json_encode([$bezApi, $h5['platnosc'] ?? null, $naZadanie, $gety]));

    // REVIEW nr 3 (A1, R3): całość zwrócona w Easytools, w panelu nic → alert z crona (raz), po „Oznacz zwrot” cisza
    // osobny katalog: test-ovh.php ma prawdziwy _katalog – kursor, znaczniki i powiadomienia testu nie mogą trafić obok konfigu
    $kA1 = array_replace($kz, ['_katalog' => sys_get_temp_dir() . '/bony-a1-test-' . bin2hex(random_bytes(4))]);
    @mkdir($kA1['_katalog'], 0700, true);
    $plikPow = $kA1['_katalog'] . '/bony-powiadomienia.txt';
    $powiadomienia = static fn() => (string)@file_get_contents($plikPow);
    $z = $zamowienie(300, 1);
    $kodA1 = $z['bony'][0]['code'];
    $tr[$z['tr']] = array_replace($tr[$z['tr']], ['refunded_amount' => 30000, 'remaining_refundable' => 0, 'refund_state' => 'full',
        'refunds' => [['amount' => 30000, 'refunded_at' => bon_teraz()->format('c')]]]);
    $zA1 = $zamowienie(200, 1); // kontrola: bez zwrotu → bez alertu
    $przed = strlen($powiadomienia());
    $r1 = bon_zwroty_a1($kA1, $pdo, null, 100000);
    $nowe = substr($powiadomienia(), $przed);
    $t('REVIEW R3 (A1): transakcja zwrócona w Easytools, bon ważny → alert z numerem bonu i linkiem; bon bez zwrotu bez alertu',
        str_contains($nowe, 'spoza panelu') && str_contains($nowe, $kodA1) && !str_contains($nowe, $zA1['bony'][0]['code']), json_encode([$r1, $nowe], JSON_UNESCAPED_UNICODE));
    $przed = strlen($powiadomienia());
    bon_zwroty_a1($kA1, $pdo, null, 100000);
    $t('REVIEW R3 (A1): drugi przebieg crona → bez drugiego alertu dla tej samej transakcji', !str_contains(substr($powiadomienia(), $przed), $kodA1));
    // rezygnacja + „Oznacz zwrot” (zwrot znany panelowi) → bon nie działa, alertu nie ma; częściowy zwrot przy kilku bonach → alert
    $z2 = $zamowienie(200, 2);
    $tr[$z2['tr']] = array_replace($tr[$z2['tr']], ['refunded_amount' => 20000, 'remaining_refundable' => 20000, 'refund_state' => 'partial']);
    $rezygnuj($z2['bony'][0]['code']); // A: rezygnacja czeka na zwrot, pieniądze oddane ręcznie bez „Oznacz zwrot”
    $przed = strlen($powiadomienia());
    bon_zwroty_a1($kA1, $pdo, null, 100000);
    $nowe2 = substr($powiadomienia(), $przed);
    // temat i przycisk w mailu wskazują bon A (czeka na zwrot), nie działający bon B
    $alert2 = str_contains($nowe2, '== Bon: zwrot w Easytools spoza panelu – ' . $z2['bony'][0]['code']) && str_contains($nowe2, 'Czeka na zwrot (rezygnacja): ' . $z2['bony'][0]['code'])
        && str_contains($nowe2, $z2['bony'][1]['code']);
    bon_panel_admin($kz, $pdo, $sEwa, ['kod' => $z2['bony'][0]['code'], 'akcja' => 'zwrot', 'powod' => 'zwrot zrobiony w Easytools']);
    @array_map('unlink', glob($kA1['_katalog'] . '/bony-a1-alerty/*') ?: []);
    $przed = strlen($powiadomienia());
    bon_zwroty_a1($kA1, $pdo, null, 100000);
    $t('REVIEW R3 (A1): 2 bony, 200 zł zwrócone bez panelu → alert wskazuje bon z rezygnacją; po „Oznacz zwrot” na nim → bez alertu',
        $alert2 && !str_contains(substr($powiadomienia(), $przed), $z2['bony'][1]['code']), $nowe2);
    // hamulec: Easytools nie odpowiada → przebieg kończy się po 3 błędach z rzędu, kursor bez zmian
    $wywolaniaA1 = 0;
    $kHam = array_replace($kA1, ['easytools_http' => static function () use (&$wywolaniaA1): array { $wywolaniaA1++; throw new RuntimeException('API Easytools: timeout'); }]);
    $plikKur = $kA1['_katalog'] . '/bony-a1-kursor.txt';
    @file_put_contents($plikKur, '0');
    $rH = bon_zwroty_a1($kHam, $pdo, null, 100000);
    $t('REVIEW nr 3: Easytools nie odpowiada → stop po 3 błędach z rzędu, kursor zostaje', $wywolaniaA1 === 3 && $rH['przerwano'] && (string)@file_get_contents($plikKur) === '0',
        json_encode([$wywolaniaA1, $rH]));
    return $wyniki;
}
