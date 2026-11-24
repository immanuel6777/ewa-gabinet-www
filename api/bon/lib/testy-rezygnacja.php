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
    return $wyniki;
}
