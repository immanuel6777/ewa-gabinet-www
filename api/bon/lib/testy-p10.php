<?php
// Testy P-10 (2026-11-24, wpis 14:35): bon znaleziony po e-mailu kupującego / zamówieniu – numer zamaskowany do potwierdzenia
// czterech ostatnich znaków przez pacjenta (poza adminem). Woła je bon_testy() – SQLite i OVH (test-ovh.php).

declare(strict_types=1);
require_once __DIR__ . '/panel.php';

function bon_testy_p10(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $sKasa = ['login' => 'kasa', 'imie' => 'Kasa', 'rola' => 'realizacja', 'csrf' => 'csrf-kasy-' . random_int(1000, 9999)];
    $sInna = ['login' => 'kasa', 'imie' => 'Kasa', 'rola' => 'realizacja', 'csrf' => 'csrf-innej-sesji'];
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    $email = 'p10-' . random_int(1000, 9999) . '@example.com';
    $p = bon_test_payload(['price_custom_id' => 'bon-300', 'amount_paid' => 300, 'customer_email' => $email]);
    bon_test_webhook($k, $pdo, $p);
    $kod = (string)(bon_test_bony($k, $pdo, $p['order_id'])[0]['code'] ?? '');
    $koncowka = substr(str_replace('-', '', $kod), -4);

    // wyszukiwanie po e-mailu: kasa bez numeru (maska + uchwyt), admin z numerem
    [, $sK] = bon_panel_szukaj($k, $pdo, $email, null, $sKasa);
    [, $sA] = bon_panel_szukaj($k, $pdo, $email, null, $sAdmin);
    $w = $sK['bony'][0] ?? [];
    $t('P-10 szukaj po e-mailu: kasa dostaje maskę i uchwyt, bez numeru; admin pełny numer',
        array_key_exists('kod', $w) && $w['kod'] === null && ($w['kod_maska'] ?? '') === 'EWA-••••-••••' && !empty($w['uchwyt'])
        && !str_contains(json_encode($sK), $kod) && ($sA['bony'][0]['kod'] ?? '') === $kod, json_encode($sK, JSON_UNESCAPED_UNICODE));
    [, $sZ] = bon_panel_szukaj($k, $pdo, (string)$p['order_id'], null, $sKasa);
    $t('P-10 szukaj po zamówieniu: też zamaskowany', array_key_exists('kod', $sZ['bony'][0] ?? []) && $sZ['bony'][0]['kod'] === null && !str_contains(json_encode($sZ), $kod));
    [, $sN] = bon_panel_szukaj($k, $pdo, $kod, null, $sKasa);
    $t('P-10 pełny numer wpisany (albo QR): bez zmian, pełny numer', ($sN['typ'] ?? '') === 'kod' && ($sN['kod'] ?? '') === $kod);

    // bon z uchwytu: maska, brak numeru w całej odpowiedzi, bez czynności wymagających numeru; inna sesja → 404
    [$h, $b] = bon_panel_historia_uchwyt($k, $pdo, $sKasa, $w['uchwyt']);
    $t('P-10 bon z uchwytu (kasa): zamaskowany, saldo i ważność widoczne, numeru nigdzie w odpowiedzi, bez PDF / przedłużenia / cofnięć',
        $h === 200 && ($b['zamaskowany'] ?? false) && $b['kod'] === null && ($b['saldo'] ?? '') === '300.00' && !str_contains(json_encode($b), $kod)
        && $b['przedluzenie'] === null && $b['cofnij_realizacje'] === null && ($b['obsluga']['pdf'] ?? true) === false && ($b['obsluga']['wyslij'] ?? false) === true,
        json_encode($b, JSON_UNESCAPED_UNICODE));
    [$h] = bon_panel_historia_uchwyt($k, $pdo, $sInna, $w['uchwyt']);
    [$hZ] = bon_panel_historia_uchwyt($k, $pdo, $sKasa, '1.0123456789abcdef01234567');
    $t('P-10 uchwyt z innej sesji albo zmyślony → 404', $h === 404 && $hZ === 404);
    $uA = bon_panel_uchwyt($k, $sAdmin, (int)bon_panel_znajdz($k, $pdo, $kod)['id']);
    [, $bA] = bon_panel_historia_uchwyt($k, $pdo, $sAdmin, $uA);
    $t('P-10 admin z uchwytu: pełny numer', ($bA['kod'] ?? '') === $kod && empty($bA['zamaskowany']));

    // potwierdzenie 4 ostatnich znaków
    [$h, $o] = bon_panel_potwierdz_numer($k, $pdo, $sKasa, $w['uchwyt'], 'ZZZZ');
    $t('P-10 złe 4 znaki → 403 z komunikatem „To nie ten numer…”', $h === 403 && str_starts_with((string)($o['blad'] ?? ''), 'To nie ten numer.'));
    [$h, $o] = bon_panel_potwierdz_numer($k, $pdo, $sKasa, $w['uchwyt'], ' ' . strtolower($koncowka) . ' ');
    [, $hA] = bon_panel_historia($k, $pdo, $sAdmin, $kod);
    $wpis = array_values(array_filter($hA['zdarzenia'], static fn($z) => $z['akcja'] === 'numer-potwierdzony'))[0] ?? [];
    $t('P-10 dobre 4 znaki (małe litery, spacje) → 200 i pełny numer; w historii „Numer potwierdzony przez pacjenta” + kto',
        $h === 200 && ($o['kod'] ?? '') === $kod && ($wpis['kto'] ?? '') === 'kasa' && ($wpis['nazwa'] ?? '') === 'Numer potwierdzony przez pacjenta', json_encode($o));

    // limit 5 prób na bon na godzinę (liczy się każda próba, także udana)
    $p2 = bon_test_payload(['price_custom_id' => 'bon-200', 'amount_paid' => 200, 'customer_email' => 'p10b-' . random_int(1000, 9999) . '@example.com']);
    bon_test_webhook($k, $pdo, $p2);
    $kod2 = (string)(bon_test_bony($k, $pdo, $p2['order_id'])[0]['code'] ?? '');
    $u2 = bon_panel_uchwyt($k, $sKasa, (int)bon_panel_znajdz($k, $pdo, $kod2)['id']);
    for ($i = 0; $i < 5; $i++) bon_panel_potwierdz_numer($k, $pdo, $sKasa, $u2, 'ZZZ' . $i);
    [$h, $o] = bon_panel_potwierdz_numer($k, $pdo, $sKasa, $u2, substr(str_replace('-', '', $kod2), -4));
    $t('P-10 po 5 złych próbach → 429 nawet z dobrymi znakami, numer nie wychodzi', $h === 429 && !isset($o['kod']), json_encode($o));

    // karty wyników (2026-11-24): nazwa, kolor, „Dla”, ostatnia realizacja, grupa zamówienia, ważne najpierw; Gmail bez kropek
    $gm = 'karty.test.' . random_int(1000, 9999) . '@gmail.com';
    $pQ = bon_test_payload(['price_custom_id' => 'bon-500', 'amount_paid' => 1000, 'quantity' => 2, 'customer_email' => $gm, 'custom_fields' => ['bon-dla' => 'Mama']]);
    bon_test_webhook($k, $pdo, $pQ);
    $pW = bon_test_payload(['price_custom_id' => 'bon-200', 'amount_paid' => 200, 'customer_email' => $gm]);
    bon_test_webhook($k, $pdo, $pW);
    $kodW = (string)(bon_test_bony($k, $pdo, $pW['order_id'])[0]['code'] ?? '');
    bon_panel_realizuj($k, $pdo, $sKasa, ['kod' => $kodW, 'kwota_reczna' => '200', 'client_token' => bin2hex(random_bytes(12))]); // wykorzystany
    [, $sG] = bon_panel_szukaj($k, $pdo, str_replace('.', '', strstr($gm, '@', true)) . '@gmail.com', null, $sKasa);
    $bG = $sG['bony'] ?? [];
    $t('karty: Gmail bez kropek znajduje 3 bony; najpierw 2 ważne (500, z jednego zamówienia – ta sama grupa), na końcu wykorzystany',
        count($bG) === 3 && $bG[0]['status'] === 'valid' && $bG[1]['status'] === 'valid' && $bG[2]['status'] === 'used'
        && $bG[0]['grupa'] === $bG[1]['grupa'] && $bG[0]['grupa'] !== $bG[2]['grupa'], json_encode($sG, JSON_UNESCAPED_UNICODE));
    $t('karty: nazwa i kolor bonu, „Dla”, ostatnia realizacja tylko przy wykorzystanym',
        ($bG[0]['nazwa'] ?? '') === 'Bon Szmaragdowy' && ($bG[0]['kolor'] ?? '') === 'zielen' && ($bG[0]['dla'] ?? '') === 'Mama' && $bG[0]['ostatnia_realizacja'] === null
        && ($bG[2]['nazwa'] ?? '') === 'Bon Topazowy' && ($bG[2]['kolor'] ?? '') === 'zloty' && !empty($bG[2]['ostatnia_realizacja']));
    [, $sInnaDomena] = bon_panel_szukaj($k, $pdo, 'kartytest@example.com', null, $sKasa);
    $t('karty: kropki ignorowane tylko w Gmailu', ($sInnaDomena['bony'] ?? null) === []);

    // wysyłka ponowna przy masce: kasa na ten sam adres – tak
    [$h] = bon_panel_wyslij_ponownie($k, $pdo, $sKasa, ['kod' => $kod2]);
    $t('P-10 kasa: wysyłka ponowna na ten sam adres kupującego → 200', $h === 200);
    return $wyniki;
}
