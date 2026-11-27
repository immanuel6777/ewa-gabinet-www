<?php
// Testy szukania po segmencie numeru bonu (Bartek 27.11 przez Front 1; Backend 2): POST szukaj.php {q, fragment: true, segment: 1|2}.
// Segment 1 = pierwsze 4 znaki po prefiksie, segment 2 = ostatnie 4; tylko kolumna `code`, bez e-maila i numeru zamówienia.
// Dostęp jak przy fragmencie (admin i Gabinet), limit wspólny z fragmentem. Woła je bon_testy() w lib/testy.php.

declare(strict_types=1);
require_once __DIR__ . '/panel.php';

function bon_testy_segment(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $tab = bon_tabela($k, 'vouchers');
    $prefix = (string)$k['kod_prefix'];
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    $sEwa = ['login' => 'ewa-seg-' . bin2hex(random_bytes(3)), 'imie' => 'Ewa', 'rola' => 'realizacja', 'rezygnacje' => true, 'csrf' => 'x'];
    $sKasa = ['login' => 'karolina-seg-' . bin2hex(random_bytes(3)), 'imie' => 'Karolina', 'rola' => 'realizacja', 'csrf' => 'x'];
    $los = static function (): string {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= BON_ALFABET[random_int(0, strlen(BON_ALFABET) - 1)];
        return $s;
    };
    // bon z wybranym numerem, e-mailem i id zamówienia
    $bon = static function (string $kod, ?string $email = null, ?string $zamowienie = null) use ($k, $pdo, $tab): array {
        $p = bon_test_payload(['price_custom_id' => 'bon-200', 'amount_paid' => 200, 'customer_email' => $email ?? 'segment-' . random_int(10000, 99999) . '@example.com']);
        bon_test_webhook($k, $pdo, $p);
        $b = bon_test_bony($k, $pdo, $p['order_id'])[0];
        $pdo->prepare("UPDATE $tab SET code = ?" . ($zamowienie !== null ? ', easytools_order_id = ?' : '') . ' WHERE id = ?')
            ->execute($zamowienie !== null ? [$kod, $zamowienie, $b['id']] : [$kod, $b['id']]);
        return ['id' => (int)$b['id'], 'kod' => $kod];
    };
    $seg = static fn(?array $s, string $q, $segment) => bon_panel_szukaj($k, $pdo, $q, null, $s, true, $segment);
    $ids = static fn(array $odp): array => array_map(static fn($c) => $c['kod'], $odp['bony'] ?? []);

    do { $s1 = $los(); $s2 = $los(); $s3 = $los(); } while (count(array_unique([$s1, $s2, $s3])) < 3);
    $bA = $bon("$prefix-$s1-$s2");                      // S1 w segmencie 1, S2 w segmencie 2
    $bB = $bon("$prefix-$s2-$s3");                      // S2 w segmencie 1
    $bC = $bon("$prefix-$s3-$s3", strtolower($s1) . '-' . random_int(100, 999) . '@example.com', strtolower($s1) . bin2hex(random_bytes(6))); // S1 tylko w e-mailu i zamówieniu

    [$h1, $o1] = $seg($sAdmin, $s1, 1);
    $t('segment 1: admin po 4 pierwszych znakach → bon z S1 na początku; bon z S1 tylko w e-mailu i zamówieniu nie',
        $h1 === 200 && ($o1['typ'] ?? '') === 'fragment' && in_array($bA['kod'], $ids($o1), true) && !in_array($bC['kod'], $ids($o1), true),
        json_encode([$h1, $o1['typ'] ?? null, count($o1['bony'] ?? [])]));
    [$h2, $o2] = $seg($sAdmin, strtolower($s2), 2);
    $t('segment 2: po 4 ostatnich znakach (małe litery) → bon z S2 na końcu; bon z S2 w segmencie 1 nie',
        $h2 === 200 && in_array($bA['kod'], $ids($o2), true) && !in_array($bB['kod'], $ids($o2), true), json_encode([$h2, count($o2['bony'] ?? [])]));
    [$h3, $o3] = $seg($sAdmin, $s2, 1);
    $t('segment 1 z S2 → bon z S2 na początku, nie bon z S2 na końcu',
        $h3 === 200 && in_array($bB['kod'], $ids($o3), true) && !in_array($bA['kod'], $ids($o3), true), json_encode([$h3, count($o3['bony'] ?? [])]));
    [$hE, $oE] = $seg($sEwa, $s1, 1);
    $ewa = array_values(array_filter($oE['bony'] ?? [], static fn($c) => ($c['kod_maska'] ?? '') !== '' && !empty($c['uchwyt'])));
    $t('segment: Gabinet (Ewa) dostaje wynik w kształcie fragmentu – numer zamaskowany z uchwytem, pole „email”',
        $hE === 200 && ($oE['typ'] ?? '') === 'fragment' && count($ewa) === count($oE['bony'] ?? []) && count($ewa) >= 1
        && $ewa[0]['kod'] === null && array_key_exists('email', $ewa[0]), json_encode([$hE, count($oE['bony'] ?? [])]));
    [$hK] = $seg($sKasa, $s1, 1);
    $t('segment: tryb Bon (Karolina) odmowa jak przy fragmencie (403)', $hK === 403, (string)$hK);

    $zle = [];
    foreach ([[$s1, 3], [$s1, 0], [$s1, '1'], ['AB1C', 1], ['ABO2', 1], [substr($s1, 0, 3), 1], [$s1 . 'A', 2], ["$s1-", 1], ['', 2]] as [$q, $sg]) {
        $zle[] = $seg($sAdmin, $q, $sg)[0];
    }
    $t('segment: zły segment (3, 0, „1”) albo q spoza alfabetu / nie 4 znaki → 400', $zle === array_fill(0, 9, 400), json_encode($zle));
    $bezFragmentu = bon_panel_szukaj($k, $pdo, $s1, null, $sAdmin, false, 1)[0];
    $t('segment bez fragment: true → 400', $bezFragmentu === 400, (string)$bezFragmentu);
    // dosłowność: prefiks z „_” w konfigu nie działa jak wzorzec LIKE
    $kP = ['kod_prefix' => 'E_A'] + $k;
    [$hP, $oP] = bon_panel_szukaj($kP, $pdo, $s1, null, $sAdmin, true, 1);
    $t('segment: prefiks z konfigu dosłownie (E_A nie łapie EWA-…)', $hP === 200 && !in_array($bA['kod'], $ids($oP), true), json_encode([$hP, count($oP['bony'] ?? [])]));

    // limit wspólny z fragmentem: 15 × fragment + 15 × segment = 30 → 31. (segment) 429
    $sLim = ['login' => 'limit-seg-' . random_int(10000, 99999), 'imie' => 'Test', 'rola' => 'admin', 'csrf' => 'x'];
    $kody = [];
    for ($i = 0; $i < 15; $i++) $kody[] = bon_panel_szukaj($k, $pdo, 'zzq', null, $sLim, true)[0];
    for ($i = 0; $i < 15; $i++) $kody[] = $seg($sLim, $s1, 1)[0];
    $kody[] = $seg($sLim, $s2, 2)[0];
    $t('segment: limit wspólny z fragmentem – 15 + 15 = 200, 31. = 429', array_slice($kody, 0, 30) === array_fill(0, 30, 200) && $kody[30] === 429,
        json_encode(array_count_values($kody)));
    return $wyniki;
}
