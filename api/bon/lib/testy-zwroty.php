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
    $kz = array_replace($k, ['easytools_api_key' => 'klucz-testowy', 'easytools_http' => static function (string $metoda, string $sciezka, array $q, ?array $body) use (&$tr, &$tryb, &$posty): array {
        if ($metoda === 'GET' && preg_match('#^/transactions/([^/]+)$#', $sciezka, $m)) return isset($tr[$m[1]]) ? [200, $tr[$m[1]]] : [404, ['message' => 'not found']];
        if ($metoda === 'POST' && preg_match('#^/transactions/([^/]+)/refunds$#', $sciezka, $m)) {
            $posty[] = ['tr' => $m[1], 'body' => $body];
            if ($tryb === 'wyjatek') throw new RuntimeException('API Easytools: symulowane zerwanie');
            if ($tryb === '500') return [500, ['message' => 'Server Error']];
            if ($tryb === '422') return [422, ['message' => 'Refund amount exceeds the remaining refundable amount 0']];
            $tr[$m[1]]['remaining_refundable'] -= (int)$body['amount']; // zwrot w toku pomniejsza remaining
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

    // kwota ograniczona do remaining_refundable (np. część zwrócona wcześniej ręcznie)
    $b2 = $bonZRezygnacja(300, 5000);
    $posty = [];
    [$h, $o] = bon_panel_zwroc($kz, $pdo, $sAdmin, ['kod' => $b2['code']]);
    $t('zwrot: kwota = min(kwota z rezygnacji, remaining_refundable) → 50.00', $h === 200 && ($o['kwota'] ?? '') === '50.00' && ($posty[0]['body']['amount'] ?? 0) === 5000);

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
    [, $hA] = bon_panel_historia($kz, $pdo, $sAdmin, $b6['code']);
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
    return $wyniki;
}
