<?php
// Testy karty #227 (Backend 2, 2026-11-27): pole `zwrot` w wynikach wyszukiwania panelu (szukaj.php) – kwota i stan zwrotu
// przy bonie z rezygnacją, liczone jak `rezygnacja` w historia.php. Admin i Gabinet (konto z 'rezygnacje') – tak, kasa – bez pola.
// Woła je bon_testy() w lib/testy.php – SQLite (npm run test:bony) i OVH (test-ovh.php). Asercje tylko na własnych bonach.

declare(strict_types=1);
require_once __DIR__ . '/panel.php';

function bon_testy_zwrot_szukaj(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $tabB = bon_tabela($k, 'vouchers');
    $tabW = bon_tabela($k, 'voucher_withdrawals');
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    $sEwa = ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'rezygnacje' => true, 'csrf' => 'x'];
    $sKasa = ['login' => 'karolina', 'imie' => 'Karolina', 'rola' => 'realizacja', 'csrf' => 'x'];
    $email = 'zwrot227-' . bin2hex(random_bytes(4)) . '@example.com';
    $nowyBon = static function (int $zl) use ($k, $pdo, $email): array {
        $p = bon_test_payload(['price_custom_id' => "bon-$zl", 'amount_paid' => $zl, 'customer_email' => $email]);
        bon_test_webhook($k, $pdo, $p);
        return bon_test_bony($k, $pdo, $p['order_id'])[0] ?? [];
    };
    // rezygnacja wpisana wprost (stan zwrotu nie zależy od tego, jak powstała – tak samo czyta ją historia.php)
    $rezygnacja = static function (array $bon, string $stan, string $saldo, string $kwota, ?string $ref = null) use ($pdo, $tabB, $tabW): void {
        $pdo->prepare("UPDATE $tabB SET status = ? WHERE id = ?")->execute([$stan === 'potwierdzony' ? 'refunded' : 'withdrawn', (int)$bon['id']]);
        $pdo->prepare("INSERT INTO $tabW (voucher_id, zlozono_at, potwierdzono_at, kanal, kto, saldo_pln, kwota_pln, zwrot_stan, zwrot_ref)
                       VALUES (?, '2026-11-20 10:00:00', '2026-11-20 10:00:00', 'gabinet', 'ewa', ?, ?, ?, ?)")
            ->execute([(int)$bon['id'], $saldo, $kwota, $stan, $ref]);
    };

    $bValid = $nowyBon(200);
    $bOczekuje = $nowyBon(200);
    $bZlecony = $nowyBon(300);
    $bPotw = $nowyBon(200);
    $bNieudany = $nowyBon(500);
    $bStary = $nowyBon(200);   // #226: stary wpis „zlecony” + „recznie” = potwierdzony
    $bAnul = $nowyBon(300);    // rezygnacja anulowana → null
    $rezygnacja($bOczekuje, 'oczekuje', '200.00', '200.00');
    $rezygnacja($bZlecony, 'zlecony', '300.00', '300.00', 'easytools-202');
    $rezygnacja($bPotw, 'potwierdzony', '200.00', '5.80', 'recznie'); // kupiony z rabatem – zwrot przeliczony do zapłaconej ceny
    $rezygnacja($bNieudany, 'nieudany', '500.00', '500.00');
    $rezygnacja($bStary, 'zlecony', '200.00', '200.00', 'recznie');
    $rezygnacja($bAnul, 'anulowana', '300.00', '300.00');
    $pdo->prepare("UPDATE $tabB SET status = 'valid' WHERE id = ?")->execute([(int)$bAnul['id']]); // po cofnięciu rezygnacji bon znów ważny

    $poId = static function (array $odp) use ($pdo, $tabB): array {
        // karty po e-mailu: admin ma pełny numer – mapujemy kod → id; poza adminem numer zamaskowany, więc po uchwycie („id.hmac”)
        $wynik = [];
        foreach ($odp['bony'] ?? [] as $c) {
            if (!empty($c['kod'])) {
                $st = $pdo->prepare("SELECT id FROM $tabB WHERE code = ?");
                $st->execute([$c['kod']]);
                $wynik[(int)$st->fetchColumn()] = $c;
            } elseif (!empty($c['uchwyt'])) {
                $wynik[(int)explode('.', (string)$c['uchwyt'])[0]] = $c;
            }
        }
        return $wynik;
    };

    [$hA, $oA] = bon_panel_szukaj($k, $pdo, $email, null, $sAdmin);
    $kA = $poId($oA);
    $z = static fn(array $karty, array $bon) => array_key_exists((int)$bon['id'], $karty) && array_key_exists('zwrot', $karty[(int)$bon['id']])
        ? $karty[(int)$bon['id']]['zwrot'] : 'BRAK POLA';
    $t('#227 szukaj (admin): wszystkie 7 bonów kupującego, pole zwrot w każdej karcie', $hA === 200 && count($kA) === 7
        && count(array_filter($kA, static fn($c) => array_key_exists('zwrot', $c))) === 7, json_encode($oA, JSON_UNESCAPED_UNICODE));
    $t('#227 zwrot: bon bez rezygnacji → null', $z($kA, $bValid) === null, json_encode($z($kA, $bValid)));
    $t('#227 zwrot: oczekuje → {kwota 200.00, stan oczekuje}', $z($kA, $bOczekuje) === ['kwota' => '200.00', 'stan' => 'oczekuje', 'przeliczona' => false],
        json_encode($z($kA, $bOczekuje)));
    $t('#227 zwrot: zlecony (API) → stan zlecony', $z($kA, $bZlecony) === ['kwota' => '300.00', 'stan' => 'zlecony', 'przeliczona' => false],
        json_encode($z($kA, $bZlecony)));
    $t('#227 zwrot: potwierdzony, kwota 5.80 (przeliczona do zapłaconej ceny)',
        $z($kA, $bPotw) === ['kwota' => '5.80', 'stan' => 'potwierdzony', 'przeliczona' => true], json_encode($z($kA, $bPotw)));
    $t('#227 zwrot: nieudany → stan nieudany', $z($kA, $bNieudany) === ['kwota' => '500.00', 'stan' => 'nieudany', 'przeliczona' => false],
        json_encode($z($kA, $bNieudany)));
    $t('#227 zwrot: stary „zlecony” + „recznie” (#226) → potwierdzony', ($z($kA, $bStary)['stan'] ?? '') === 'potwierdzony', json_encode($z($kA, $bStary)));
    $t('#227 zwrot: rezygnacja anulowana → null', $z($kA, $bAnul) === null, json_encode($z($kA, $bAnul)));

    // ta sama kwota i stan co `rezygnacja` w historia.php (karta bonu) – front pokazuje oba tak samo
    $zgodne = true;
    foreach ([$bOczekuje, $bZlecony, $bPotw, $bNieudany, $bStary] as $b) {
        $r = bon_panel_rezygnacja($k, $pdo, (int)$b['id']);
        $c = $z($kA, $b);
        if (!is_array($c) || !$r || $c['kwota'] !== $r['kwota'] || $c['stan'] !== $r['stan']) $zgodne = false;
    }
    $t('#227 zwrot na liście = kwota i stan z bon_panel_rezygnacja (historia.php)', $zgodne);

    [$hE, $oE] = bon_panel_szukaj($k, $pdo, $email, null, $sEwa);
    $kE = $poId($oE);
    $t('#227 szukaj (Gabinet): pole zwrot jak u admina', $hE === 200 && count($kE) === 7 && $z($kE, $bPotw) === $z($kA, $bPotw)
        && $z($kE, $bValid) === null && ($z($kE, $bNieudany)['stan'] ?? '') === 'nieudany', json_encode($oE, JSON_UNESCAPED_UNICODE));

    [$hK, $oK] = bon_panel_szukaj($k, $pdo, $email, null, $sKasa);
    $t('#227 szukaj (kasa, tryb Bon): żadna karta nie ma pola zwrot', $hK === 200 && count($oK['bony'] ?? []) === 7
        && !array_filter($oK['bony'], static fn($c) => array_key_exists('zwrot', $c)), json_encode($oK, JSON_UNESCAPED_UNICODE));

    // fragment (#201, tylko admin i Gabinet) też ma pole
    [$hF, $oF] = bon_panel_szukaj($k, $pdo, substr($email, 0, 14), null, $sAdmin, true);
    $kF = $poId($oF);
    $t('#227 szukaj po fragmencie (admin): pole zwrot', $hF === 200 && ($z($kF, $bZlecony)['stan'] ?? '') === 'zlecony', json_encode($oF, JSON_UNESCAPED_UNICODE));

    // bez tabeli rezygnacji (przed migracją kroku 1) – szukanie działa, zwrot null
    if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $bez = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $bez->exec('CREATE TABLE redemptions (id INTEGER PRIMARY KEY, voucher_id INTEGER, redeemed_at TEXT)');
        $bez->exec('CREATE TABLE voucher_admin_log (id INTEGER PRIMARY KEY, voucher_id INTEGER, akcja TEXT, kiedy TEXT)');
        $dod = bon_panel_karty_dodatki(array_replace($k, ['tabele_przedrostek' => '']), $bez, [1]);
        $t('#227 dodatki kart bez tabeli voucher_withdrawals: bez wyjątku, zwrot null', ($dod[1]['zwrot'] ?? null) === null, json_encode($dod));
    }
    return $wyniki;
}
