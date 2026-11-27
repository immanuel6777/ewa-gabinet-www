<?php
// Testy karty #219 (Backend 2, 2026-11-27): numer zamówienia Easytools przy bonie – zapis z dzwonka, uzupełnienie z logu webhooków
// w cronie, szukanie w panelu, pole w historii, zachowanie bez kolumny (przed migracją). Woła je bon_testy() w lib/testy.php.
// Bony tworzy webhook w trybie „bon” (bon_test_konfig); dzwonek z sync_tryb = wylaczony – bez API Easytools (także na OVH).
// Asercje tylko na własnych bonach: katalog logu webhooków jest wspólny z innymi testami (i na OVH z prawdziwym logiem).

declare(strict_types=1);
require_once __DIR__ . '/numer-zamowienia.php';
require_once __DIR__ . '/panel.php';

function bon_testy_numer_zamowienia(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $nrBonu = static function (string $uuid) use ($k, $pdo): array {
        return array_map(static fn($b) => $b['easytools_order_nr'] ?? null, bon_test_bony($k, $pdo, $uuid));
    };
    $nowyNr = static fn(): int => random_int(1000000, 9999999);
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    $sEwa = ['login' => 'ewa-219-' . bin2hex(random_bytes(3)), 'imie' => 'Ewa', 'rola' => 'realizacja', 'rezygnacje' => true, 'csrf' => 'x'];
    $sKasa = ['login' => 'karolina', 'imie' => 'Karolina', 'rola' => 'realizacja', 'csrf' => 'x'];

    // --- pomocnicze: odczyt numeru z webhooka i z wpisu w panelu ---
    $t('numer: order_id z webhooka – liczba i tekst z cyfr tak; 0, ujemny, „12a”, 21 cyfr, null nie',
        bon_numer_z_webhooka(1604262) === '1604262' && bon_numer_z_webhooka('1604262') === '1604262'
        && bon_numer_z_webhooka(0) === null && bon_numer_z_webhooka(-5) === null && bon_numer_z_webhooka('12a') === null
        && bon_numer_z_webhooka(str_repeat('1', 21)) === null && bon_numer_z_webhooka(null) === null && bon_numer_z_webhooka(1.5) === null);
    $t('numer: wpis w panelu „#1604262”, „1604262”, „ # 1604262 ” tak; „#1604” (4 cyfry), „EWA-…”, UUID, „#16a” nie',
        bon_numer_z_wpisu('#1604262') === '1604262' && bon_numer_z_wpisu('1604262') === '1604262' && bon_numer_z_wpisu(' # 1604262 ') === '1604262'
        && bon_numer_z_wpisu('#1604') === null && bon_numer_z_wpisu('EWA-AB2C-D3EF') === null
        && bon_numer_z_wpisu('94baa1f4-398a-43b1-a0dd-5d51431a0d5e') === null && bon_numer_z_wpisu('#16a') === null);

    // --- bez kolumny (przed migracją): osobna baza SQLite bez easytools_order_nr ---
    if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $bez = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $bez->exec('CREATE TABLE vouchers (id INTEGER PRIMARY KEY, code TEXT, easytools_order_id TEXT, created_at TEXT, buyer_email TEXT)');
        $bez->exec("INSERT INTO vouchers (code, easytools_order_id, created_at, buyer_email) VALUES ('EWA-AAAA-BBBB', 'aaaaaaaa-0000-4000-a000-000000000001', '2026-11-27 10:00:00', 'x@example.com')");
        $kBez = array_replace($k, ['tabele_przedrostek' => '']);
        $pd = bon_numer_po_dzwonku($kBez, $bez, 'aaaaaaaa-0000-4000-a000-000000000001', '1604262');
        $uz = bon_numer_uzupelnij($kBez, $bez);
        [$hS, $oS] = bon_panel_szukaj($kBez, $bez, '#1604262', null, null); // bez sesji = bez limitu (ta baza nie ma tabeli limitów)
        $t('numer bez kolumny: kolumna_jest = nie, dzwonek → brak_kolumny bez wyjątku, cron → brak_kolumny, panel „#…” → 400 z komunikatem',
            bon_numer_kolumna_jest($kBez, $bez) === false && ($pd['brak_kolumny'] ?? false) === true && !isset($pd['blad'])
            && ($uz['brak_kolumny'] ?? false) === true && $uz['plikow'] === 0 && $hS === 400 && str_contains((string)($oS['blad'] ?? ''), 'aktualizacji bazy')
            && (int)$bez->query('SELECT COUNT(*) FROM vouchers')->fetchColumn() === 1, json_encode([$pd, $uz, $oS], JSON_UNESCAPED_UNICODE));
        [$hS8, $oS8] = bon_panel_szukaj($kBez, $bez, '16042621', null, null);
        $t('numer bez kolumny: 8+ cyfr → szukanie po id zamówienia jak przed #219 (200, bez błędu)', $hS8 === 200 && $oS8['bony'] === [], json_encode($oS8));
        $inny = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $t('numer bez kolumny: inny błąd bazy to NIE „brak kolumny”', !bon_blad_brak_kolumny($inny) && !bon_blad_brak_kolumny(new RuntimeException('no such column')));
    }

    // na OVH tabele test_* są kopią prawdziwych (CREATE TABLE … LIKE) – przed migracją kolumny nie ma: sprawdzamy, że nic nie pada
    if (!bon_numer_kolumna_jest($k, $pdo)) {
        $uuid = bon_test_uuid();
        bon_test_webhook($k, $pdo, bon_test_payload(['order_uuid' => $uuid]));
        $pd = bon_numer_po_dzwonku($k, $pdo, $uuid, '1604262');
        $t('numer (baza przed migracją): bon powstaje jak dotąd, zapis numeru = brak_kolumny bez wyjątku, cron = brak_kolumny',
            count(bon_test_bony($k, $pdo, $uuid)) === 1 && ($pd['brak_kolumny'] ?? false) === true && (bon_numer_uzupelnij($k, $pdo)['brak_kolumny'] ?? false) === true,
            json_encode($pd));
        return $wyniki;
    }

    // --- dzwonek: zapis po synchronizacji (sync wyłączony – bez API) ---
    $kD = array_replace($k, ['webhook_tryb' => 'dzwonek', 'sync_tryb' => 'wylaczony']);
    $uuid = bon_test_uuid();
    $nr = $nowyNr();
    bon_test_webhook($k, $pdo, bon_test_payload(['order_uuid' => $uuid, 'order_id' => $nr, 'quantity' => 2, 'amount_paid' => 400, 'order_amount' => 400]));
    $t('numer: bon z webhooka w trybie „bon” powstaje bez numeru (numer zapisuje tylko dzwonek i cron)', $nrBonu($uuid) === [null, null], json_encode($nrBonu($uuid)));

    [$h, $o, $zad] = bon_test_webhook($kD, $pdo, bon_test_payload(['order_uuid' => $uuid, 'order_id' => $nr]), 'zly-podpis') + [2 => null];
    $t('numer: dzwonek ze złym podpisem → 200, bez zadania, numer nie zapisany', $h === 200 && $zad === null && $nrBonu($uuid) === [null, null], json_encode($o));
    $raw = json_encode(bon_test_payload(['order_uuid' => $uuid, 'order_id' => $nr]));
    [$h, , $zad] = bon_obsluz_webhook($kD, $raw, [], static fn() => $pdo) + [2 => null];
    $t('numer: dzwonek bez podpisu → bez zapisu', $h === 200 && $zad === null && $nrBonu($uuid) === [null, null]);
    [, , $zad] = bon_test_webhook($kD, $pdo, bon_test_payload(['order_uuid' => $uuid, 'order_id' => $nr, 'event' => 'product_assigned'])) + [2 => null];
    $t('numer: podpisany product_assigned → bez zapisu', $zad === null && $nrBonu($uuid) === [null, null]);
    [, , $zad] = bon_test_webhook($kD, $pdo, bon_test_payload(['order_uuid' => $uuid, 'order_id' => 'abc']));
    $w = $zad ? $zad() : [];
    $t('numer: podpisany zakup z order_id nie-liczbą → dzwonek działa, numer nie zapisany (numer_zamowienia = null)',
        ($w['dzwonek'] ?? false) === true && array_key_exists('numer_zamowienia', $w) && $w['numer_zamowienia'] === null && $nrBonu($uuid) === [null, null], json_encode($w));

    [$h, $o, $zad] = bon_test_webhook($kD, $pdo, bon_test_payload(['order_uuid' => strtoupper($uuid), 'order_id' => $nr]));
    $odpPrzed = $o;
    $w = $zad ? $zad() : [];
    $t('numer: podpisany zakup → odpowiedź dla sklepu bez zmian (bez numeru), po niej numer przy OBU bonach koszyka (UUID wielkimi literami też)',
        $h === 200 && $odpPrzed === ['ok' => true, 'tryb' => 'dzwonek', 'podpis_ok' => true, 'dzwonek' => true]
        && $nrBonu($uuid) === [(string)$nr, (string)$nr] && ($w['numer_zamowienia'] ?? null) === ['nr' => (string)$nr, 'zapisano' => 2]
        && ($w['bonow_zamowienia'] ?? null) === 2, json_encode([$odpPrzed, $w, $nrBonu($uuid)]));
    [, , $zad] = bon_test_webhook($kD, $pdo, bon_test_payload(['order_uuid' => $uuid, 'order_id' => $nr + 1]));
    $w = $zad();
    $t('numer: drugi dzwonek z innym numerem nie nadpisuje istniejącego (zapisano 0)', $nrBonu($uuid) === [(string)$nr, (string)$nr]
        && ($w['numer_zamowienia']['zapisano'] ?? null) === 0, json_encode($w));
    $inne = bon_test_uuid();
    bon_test_webhook($k, $pdo, bon_test_payload(['order_uuid' => $inne]));
    $t('numer: inne zamówienie nie dostało numeru', $nrBonu($inne) === [null]);

    // dzwonek, zanim bon jest w bazie (API jeszcze nie „completed”): zapis 0, numer przyjdzie z crona
    $pozno = bon_test_uuid();
    $nrPozno = $nowyNr();
    [, , $zad] = bon_test_webhook($kD, $pdo, bon_test_payload(['order_uuid' => $pozno, 'order_id' => $nrPozno]));
    $w = $zad();
    $t('numer: dzwonek bez bonu w bazie → zapisano 0, bez błędu', ($w['numer_zamowienia']['zapisano'] ?? null) === 0 && ($w['bonow_zamowienia'] ?? null) === 0, json_encode($w));

    // --- cron: uzupełnienie z logu webhooków ---
    bon_test_webhook($k, $pdo, bon_test_payload(['order_uuid' => $pozno, 'order_id' => $nrPozno])); // bon powstaje dopiero teraz (sync)
    $stary = bon_test_uuid();
    $nrStary = $nowyNr();
    bon_test_webhook($k, $pdo, bon_test_payload(['order_uuid' => $stary, 'order_id' => $nrStary]));
    // zapłata sprzed 40 dni (bon_data_zaplaty nie przyjmie tak starej daty z webhooka, więc wprost w tabeli)
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . ' SET created_at = ? WHERE easytools_order_id = ?')
        ->execute([bon_teraz()->modify('-40 days')->format('Y-m-d H:i:s'), $stary]);
    $obcy = bon_test_uuid();
    bon_test_webhook($k, $pdo, bon_test_payload(['order_uuid' => $obcy]));
    $wygasl = bon_test_uuid();
    $nrWygasl = $nowyNr();
    bon_test_webhook($k, $pdo, bon_test_payload(['order_uuid' => $wygasl]));
    // log: niepodpisany wpis z polami (podrobiony) i podpisane zdarzenia przez prawdziwy bon_log_webhooka (ten sam format co produkcja)
    $katalog = $k['_katalog'] . '/bony-webhook-log';
    if (!is_dir($katalog)) @mkdir($katalog, 0700, true);
    @file_put_contents($katalog . '/' . bon_teraz()->format('Y-m-d') . '.log', json_encode(['czas' => bon_teraz()->format('c'), 'podpis_ok' => false,
        'zdarzenie' => ['event' => 'single_product_bought', 'order_id' => 1111111, 'order_uuid' => $obcy]]) . "\n", FILE_APPEND | LOCK_EX);
    $loguj = static function (array $p) use ($k) { $raw = json_encode($p); bon_log_webhooka($k, $raw, ['x-webhook-signature' => 'x'], true); };
    $loguj(bon_test_payload(['order_uuid' => $stary, 'order_id' => $nrStary]));
    $loguj(bon_test_payload(['order_uuid' => $wygasl, 'order_id' => $nrWygasl, 'event' => 'product_access_expired', 'amount_paid' => null]));
    $loguj(bon_test_payload(['order_uuid' => BON_UUID_ZEROWY, 'order_id' => 2222222]));
    $u1 = bon_numer_uzupelnij($k, $pdo);
    $t('numer cron: bon utworzony po dzwonku dostaje numer z logu (wpis dzwonka), product_access_expired też się liczy',
        $nrBonu($pozno) === [(string)$nrPozno] && $nrBonu($wygasl) === [(string)$nrWygasl] && $u1['uzupelniono'] >= 2 && $u1['plikow'] >= 1, json_encode($u1));
    $t('numer cron: niepodpisany wpis logu ignorowany, bon starszy niż 30 dni bez numeru, istniejący numer bez zmian',
        $nrBonu($obcy) === [null] && $nrBonu($stary) === [null] && $nrBonu($uuid) === [(string)$nr, (string)$nr]);
    $u2 = bon_numer_uzupelnij($k, $pdo);
    $t('numer cron: drugi przebieg niczego nie zmienia (idempotentnie), wynik bez danych osobowych',
        $u2['uzupelniono'] === 0 && $nrBonu($pozno) === [(string)$nrPozno] && !str_contains((string)json_encode($u2), '@')
        && array_keys($u2) === ['brakujacych', 'plikow', 'pasujacych', 'uzupelniono'], json_encode($u2));

    // --- panel: szukanie po numerze i pole w historii ---
    $kody = array_map(static fn($b) => $b['code'], bon_test_bony($k, $pdo, $uuid));
    [$h, $o] = bon_panel_szukaj($k, $pdo, '#' . $nr, null, $sAdmin);
    $t('numer panel: admin „#numer” → typ zamowienie, oba bony koszyka z pełnymi numerami', $h === 200 && $o['typ'] === 'zamowienie'
        && count($o['bony']) === 2 && array_diff($kody, array_column($o['bony'], 'kod')) === [], json_encode($o, JSON_UNESCAPED_UNICODE));
    [$h, $o] = bon_panel_szukaj($k, $pdo, (string)$nr, null, $sEwa);
    $t('numer panel: Gabinet sam numer (bez „#”) → 2 bony, numery zamaskowane jak przy UUID', $h === 200 && count($o['bony']) === 2
        && $o['bony'][0]['kod'] === null && !empty($o['bony'][0]['uchwyt']), json_encode($o, JSON_UNESCAPED_UNICODE));
    [$h, $o] = bon_panel_szukaj($k, $pdo, '#' . $nr, null, $sKasa);
    [$h8, $o8] = bon_panel_szukaj($k, $pdo, $nr . '12', null, $sKasa);
    $t('numer panel: poziom Bon „#numer” i 9 cyfr → 400 jak przy nieznanym wpisie, bez listy bonów (Backend 01:35)', $h === 400 && !isset($o['bony'])
        && str_contains((string)$o['blad'], 'Wpisz numer bonu') && $h8 === 400 && !isset($o8['bony']), json_encode([$o, $o8], JSON_UNESCAPED_UNICODE));
    $sLimit = ['login' => 'limit-219-' . bin2hex(random_bytes(3)), 'imie' => 'Ewa', 'rola' => 'realizacja', 'rezygnacje' => true, 'csrf' => 'x'];
    $kL = array_replace($k, ['panel_limit_fragment' => 2]);
    $kody429 = [bon_panel_szukaj($kL, $pdo, '#' . $nr, null, $sLimit)[0], bon_panel_szukaj($kL, $pdo, (string)$nr, null, $sLimit)[0],
        bon_panel_szukaj($kL, $pdo, '#' . $nr, null, $sLimit)[0], bon_panel_szukaj($kL, $pdo, '#' . $nr, null, $sLimit, true)[0]];
    $t('numer panel: szukanie po numerze pod limitem fragmentu na konto (2 → trzecie 429, fragment liczy się do tego samego)', $kody429 === [200, 200, 429, 429], json_encode($kody429));
    [$h, $o] = bon_panel_szukaj($k, $pdo, '#' . $nr, null, $sEwa, true);
    $t('numer panel: tryb Gabinet (fragment) „#numer” → te 2 bony (dokładny numer, nie fragment)', $h === 200 && count($o['bony']) === 2
        && ($o['typ'] ?? '') === 'fragment', json_encode($o, JSON_UNESCAPED_UNICODE));
    [$h, $o] = bon_panel_szukaj($k, $pdo, '#' . substr((string)$nr, 0, 5), null, $sAdmin);
    $t('numer panel: początek numeru (5 cyfr) w zwykłym szukaniu → 0 bonów (tylko dokładne dopasowanie)', $h === 200 && $o['bony'] === [], json_encode($o));
    [$h, $o] = bon_panel_szukaj($k, $pdo, '#9999999999', null, $sAdmin);
    $t('numer panel: nieznany numer → 200, pusta lista', $h === 200 && $o['bony'] === [] && $o['typ'] === 'zamowienie');

    [$h, $oA] = bon_panel_historia($k, $pdo, $sAdmin, $kody[0]);
    [, $oE] = bon_panel_historia($k, $pdo, $sEwa, $kody[0]);
    [, $oK] = bon_panel_historia($k, $pdo, $sKasa, $kody[0]);
    $idBonu = (int)bon_test_bony($k, $pdo, $uuid)[0]['id'];
    [$hU, $oU] = bon_panel_historia_uchwyt($k, $pdo, $sEwa, bon_panel_uchwyt($k, $sEwa, $idBonu));
    [, $oBez] = bon_panel_historia($k, $pdo, $sAdmin, bon_test_bony($k, $pdo, $obcy)[0]['code']);
    $t('numer historia: zamowienie_nr dla admina i Gabinetu (też przez uchwyt), poziom Bon bez pola, bon bez numeru → null',
        $h === 200 && ($oA['zamowienie_nr'] ?? null) === (string)$nr && ($oE['zamowienie_nr'] ?? null) === (string)$nr
        && $hU === 200 && ($oU['zamowienie_nr'] ?? null) === (string)$nr && !array_key_exists('zamowienie_nr', $oK)
        && array_key_exists('zamowienie_nr', $oBez) && $oBez['zamowienie_nr'] === null, json_encode([$oA['zamowienie_nr'] ?? '?', $oE['zamowienie_nr'] ?? '?', $oU['zamowienie_nr'] ?? '?']));
    return $wyniki;
}
