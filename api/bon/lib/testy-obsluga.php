<?php
// Testy obsługi kupującego (2026-11-24, TA-11): odbicie maila (powiadomienie z kupującym i adresem, wpis w historii, znacznik
// w panelu), „Wyślij ponownie” ze zmianą adresu, PDF z panelu. Uprawnienie: admin i konto z 'rezygnacje' (Bartek, Ewa).
// Woła je bon_testy() w lib/testy.php – te same na SQLite (npm run test:bony) i na OVH (test-ovh.php).

declare(strict_types=1);
require_once __DIR__ . '/panel.php';
require_once __DIR__ . '/mail.php';

function bon_testy_obsluga(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $sKasa = ['login' => 'kasa', 'imie' => 'Kasa', 'rola' => 'realizacja', 'csrf' => 'x'];
    $sEwa = ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'rezygnacje' => true, 'csrf' => 'x'];
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    $stary = 'literowka-' . random_int(1000, 9999) . '@exampel.com';
    $nowy = 'poprawny-' . random_int(1000, 9999) . '@example.com';

    // zamówienie z 2 bonami, mail wysłany na adres z literówką
    $p = bon_test_payload(['price_custom_id' => 'bon-200', 'amount_paid' => 400, 'quantity' => 2, 'customer_email' => $stary]);
    [, , $zadanie] = bon_test_webhook($k, $pdo, $p);
    if ($zadanie) $zadanie();
    $bony = bon_test_bony($k, $pdo, $p['order_id']);
    $kod = (string)($bony[0]['code'] ?? '');

    // --- odbicie w kontroli dostarczenia (katalog tymczasowy, jak w testy.php) ---
    $kat0 = sys_get_temp_dir() . '/bony-obsluga-' . bin2hex(random_bytes(4));
    @mkdir($kat0, 0700, true);
    $kK = array_replace($k, ['_katalog' => $kat0, 'mailjet_test_status' => 'bounce']);
    bon_mail_zapisz_wysylke($kK, (string)$p['order_id'], array_column($bony, 'code'), [901, 902]);
    $r = bon_mail_kontrola($kK, 0, 0, $pdo);
    $pow = (string)@file_get_contents($kat0 . '/bony-powiadomienia.txt');
    $t('odbicie: powiadomienie gabinetu z numerami bonów, imieniem kupującego i odbitym adresem', $r['problem'] === 1
        && str_contains($pow, 'mail NIE dotarł') && str_contains($pow, $kod) && str_contains($pow, 'Odbity adres: ' . $stary)
        && str_contains($pow, 'Kupujący: ') && str_contains($pow, 'Wyślij ponownie'), $pow);
    [, $h] = bon_panel_historia($k, $pdo, $sKasa, $kod);
    $t('odbicie: znacznik mail_odbity w panelu (każda rola), wpis w historii', ($h['mail_odbity'] ?? false) === true
        && in_array('mail-odbity', array_column($h['zdarzenia'] ?? [], 'akcja'), true));
    $t('odbicie: kasa nie widzi adresu ani przycisków obsługi', !isset($h['obsluga']) && !str_contains(json_encode($h), $stary), json_encode($h));
    foreach (glob($kat0 . '/*/*') ?: [] as $f) @unlink($f);
    foreach (glob($kat0 . '/*') ?: [] as $f) is_dir($f) ? @rmdir($f) : @unlink($f);
    @rmdir($kat0);

    // --- uprawnienia ---
    // P-10 (2026-11-24): kasa może wysłać ponownie NA TEN SAM adres (pacjent bez bonu), zmiana adresu i PDF nadal nie
    [$kodH] = bon_panel_wyslij_ponownie($k, $pdo, $sKasa, ['kod' => $kod, 'email' => 'inny@example.com', 'potwierdzenie' => 'x']);
    [$kodP] = bon_panel_pdf_bon($k, $pdo, $sKasa, $kod);
    $t('kasa (bez uprawnienia): zmiana adresu i PDF → 403, adres bez zmian', $kodH === 403 && $kodP === 403 && bon_test_bony($k, $pdo, $p['order_id'])[0]['buyer_email'] === $stary);
    [, $h] = bon_panel_historia($k, $pdo, $sEwa, $kod);
    $t('Ewa (uprawnienie rezygnacji): widzi obecny adres i przyciski, bez szczegółów historii', ($h['obsluga']['email'] ?? '') === $stary
        && ($h['obsluga']['wyslij'] ?? false) && ($h['obsluga']['pdf'] ?? false), json_encode($h['obsluga'] ?? null));

    // --- zmiana adresu ---
    [$kodH, $odp] = bon_panel_wyslij_ponownie($k, $pdo, $sEwa, ['kod' => $kod, 'email' => $nowy]);
    $t('zmiana adresu bez „jak potwierdzono” → 400, adres bez zmian', $kodH === 400 && bon_test_bony($k, $pdo, $p['order_id'])[0]['buyer_email'] === $stary, json_encode($odp));
    [$kodH] = bon_panel_wyslij_ponownie($k, $pdo, $sEwa, ['kod' => $kod, 'email' => 'zly@', 'potwierdzenie' => 'telefon']);
    $t('zły adres e-mail → 400', $kodH === 400);
    $jsonl = $k['_katalog'] . '/bony-mailjet.jsonl';
    $przed = is_file($jsonl) ? count(file($jsonl)) : 0;
    [$kodH, $odp, $zam] = bon_panel_wyslij_ponownie($k, $pdo, $sEwa, ['kod' => $kod, 'email' => $nowy, 'potwierdzenie' => 'telefon 23.11, podała nr zamówienia']) + [2 => null];
    $po = bon_test_bony($k, $pdo, $p['order_id']);
    $t('zmiana adresu: nowy adres przy obu bonach zamówienia, oba do ponownej wysyłki', $kodH === 200 && $zam === (string)$p['order_id'] && ($odp['bonow'] ?? 0) === 2
        && $po[0]['buyer_email'] === $nowy && $po[1]['buyer_email'] === $nowy && $po[0]['mail_wyslany_at'] === null && $po[1]['mail_wyslany_at'] === null, json_encode($odp));
    $w = bon_wyslij_zamowienie($k, $pdo, $zam);
    $linie = is_file($jsonl) ? file($jsonl, FILE_IGNORE_NEW_LINES) : [];
    $ostatni = json_decode((string)end($linie), true) ?: [];
    $t('wysyłka ponowna: jeden mail na nowy adres z 2 PDF-ami, bony oznaczone', ($w['wyslane'] ?? 0) === 2 && count($linie) === $przed + 1
        && str_contains(json_encode($ostatni), $nowy) && bon_test_bony($k, $pdo, $p['order_id'])[0]['mail_wyslany_at'] !== null, json_encode($w));
    [, $h] = bon_panel_historia($k, $pdo, $sAdmin, $kod);
    $adres = array_values(array_filter($h['zdarzenia'], static fn($z) => $z['akcja'] === 'adres'));
    $t('historia (admin): stary i nowy adres, kto i jak potwierdzono; znacznik odbicia zdjęty po wysyłce', $adres
        && str_contains((string)$adres[0]['szczegoly'], "$stary → $nowy") && str_contains((string)$adres[0]['szczegoly'], 'telefon') && $adres[0]['kto'] === 'ewa'
        && ($h['mail_odbity'] ?? true) === false, json_encode($h['zdarzenia']));
    [, $h] = bon_panel_historia($k, $pdo, $sEwa, $kod);
    $t('historia (Ewa, nie admin): zdarzenie „Zmieniony adres” bez szczegółów z adresami', !str_contains(json_encode(array_column($h['zdarzenia'], 'szczegoly')), $stary));
    [$kodH, $odp] = bon_panel_wyslij_ponownie($k, $pdo, $sAdmin, ['kod' => $kod]);
    $t('wyślij ponownie bez zmiany adresu: bez „jak potwierdzono”, bez wpisu „adres”', $kodH === 200 && ($odp['zmiana_adresu'] ?? true) === false && ($odp['adres'] ?? '') === $nowy);
    bon_wyslij_zamowienie($k, $pdo, (string)$p['order_id']);

    // dwa kroki (Bartek 2026-11-24): „Zmień adres” (bez_wysylki) zapisuje adres i historię, NIE zleca wysyłki; ten sam adres → 400
    $nowy2 = 'drugi-' . random_int(1000, 9999) . '@example.com';
    [$kodZ, $odpZ, $zamZ] = bon_panel_wyslij_ponownie($k, $pdo, $sEwa, ['kod' => $kod, 'email' => $nowy2, 'potwierdzenie' => 'telefon', 'bez_wysylki' => true]) + [2 => null];
    $poZ = bon_test_bony($k, $pdo, $p['order_id']);
    [, $hZ] = bon_panel_historia($k, $pdo, $sAdmin, $kod);
    [$kodS] = bon_panel_wyslij_ponownie($k, $pdo, $sEwa, ['kod' => $kod, 'email' => $nowy2, 'bez_wysylki' => true]);
    $t('Zmień adres bez wysyłki: adres przy obu bonach, wpis „adres” z kto, bez zlecenia wysyłki (mail_wyslany_at bez zmian); ten sam adres → 400',
        $kodZ === 200 && $zamZ === null && ($odpZ['wyslano'] ?? true) === false && ($odpZ['bonow'] ?? 0) === 2
        && $poZ[0]['buyer_email'] === $nowy2 && $poZ[1]['buyer_email'] === $nowy2 && $poZ[0]['mail_wyslany_at'] !== null
        && ($hZ['zdarzenia'][0]['akcja'] ?? '') === 'adres' && ($hZ['zdarzenia'][0]['kto'] ?? '') === 'ewa' && str_contains((string)$hZ['zdarzenia'][0]['szczegoly'], "→ $nowy2")
        && $kodS === 400, json_encode([$odpZ, $poZ[0]['mail_wyslany_at'] ?? null, $hZ['zdarzenia'][0] ?? null], JSON_UNESCAPED_UNICODE));

    // --- PDF z panelu ---
    [$kodP, $bon] = bon_panel_pdf_bon($k, $pdo, $sEwa, $kod);
    $t('PDF z panelu: bon ważny → wiersz bonu, wpis „pdf” w historii', $kodP === 200 && ($bon['code'] ?? '') === $kod && str_starts_with(bon_pdf($bon, $k), '%PDF'));
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . " SET status = 'refunded' WHERE code = ?")->execute([$kod]);
    [$kodP] = bon_panel_pdf_bon($k, $pdo, $sAdmin, $kod);
    [$kodH] = bon_panel_wyslij_ponownie($k, $pdo, $sAdmin, ['kod' => $kod]);
    $t('bon zwrócony: PDF i wysyłka ponowna → 409', $kodP === 409 && $kodH === 409);

    // --- cofnięcie ostatniej realizacji (2026-11-24, wpisy 14:10 i 15:00) ---
    $p2 = bon_test_payload(['price_custom_id' => 'bon-300', 'amount_paid' => 300]);
    bon_test_webhook($k, $pdo, $p2);
    $kodC = (string)(bon_test_bony($k, $pdo, $p2['order_id'])[0]['code'] ?? '');
    $tok = static fn() => bin2hex(random_bytes(12));
    bon_panel_realizuj($k, $pdo, $sKasa, ['kod' => $kodC, 'kwota_reczna' => '100', 'client_token' => $tok()]);
    bon_panel_realizuj($k, $pdo, $sKasa, ['kod' => $kodC, 'kwota_reczna' => '250', 'client_token' => $tok()]); // 200 z bonu, dopłata 50 → used
    $saldo = static function () use ($k, $pdo, $kodC): array {
        $b = bon_panel_znajdz($k, $pdo, $kodC);
        return [bon_zl((int)bon_grosze($b['balance_pln'])), $b['status']];
    };
    [, $hK] = bon_panel_historia($k, $pdo, $sKasa, $kodC);
    [, $hE] = bon_panel_historia($k, $pdo, $sEwa, $kodC);
    $cr = $hE['cofnij_realizacje'] ?? null;
    $t('cofnięcie: kasa nie widzi przycisku, Ewa widzi ostatnią realizację (200 zł)', ($hK['cofnij_realizacje'] ?? null) === null && $cr && $cr['kwota'] === '200.00', json_encode($cr));
    [$kodA] = bon_panel_admin($k, $pdo, $sKasa, ['kod' => $kodC, 'akcja' => 'cofnij-realizacje', 'realizacja' => $cr['id'] ?? 0, 'powod' => 'x']);
    [$kodB] = bon_panel_admin($k, $pdo, $sEwa, ['kod' => $kodC, 'akcja' => 'cofnij-realizacje', 'realizacja' => $cr['id'] ?? 0]);
    [$kodC2] = bon_panel_admin($k, $pdo, $sEwa, ['kod' => $kodC, 'akcja' => 'cofnij-realizacje', 'realizacja' => ($cr['id'] ?? 0) - 1, 'powod' => 'nie ta']);
    $t('cofnięcie: kasa 403, bez powodu 400, nie ostatnia realizacja 409; saldo bez zmian', $kodA === 403 && $kodB === 400 && $kodC2 === 409 && $saldo() === ['0.00', 'used']);
    [$kodD, $odp] = bon_panel_admin($k, $pdo, $sEwa, ['kod' => $kodC, 'akcja' => 'cofnij-realizacje', 'realizacja' => $cr['id'] ?? 0, 'powod' => 'pomyłka w kwocie']);
    $t('cofnięcie: Ewa cofa ostatnią → saldo 0 → 200 zł, bon znów ważny', $kodD === 200 && $saldo() === ['200.00', 'valid'], json_encode($odp));
    [, $hA] = bon_panel_historia($k, $pdo, $sAdmin, $kodC);
    $wpis = array_values(array_filter($hA['zdarzenia'], static fn($z) => $z['akcja'] === 'realizacja-cofnieta'))[0] ?? [];
    $t('cofnięcie: wiersz realizacji zostaje jako „cofnięta”, poprzednia nie; wpis w historii z kwotą, saldem, powodem i osobą',
        count($hA['historia']) === 2 && $hA['historia'][0]['cofnieta'] === true && $hA['historia'][1]['cofnieta'] === false
        && str_contains((string)($wpis['szczegoly'] ?? ''), '−200.00 zł') && str_contains((string)$wpis['szczegoly'], '0.00 → 200.00')
        && str_contains((string)$wpis['szczegoly'], 'pomyłka w kwocie') && ($wpis['kto'] ?? '') === 'ewa', json_encode($wpis));
    [$kodE] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kodC, 'akcja' => 'cofnij-realizacje', 'realizacja' => $cr['id'] ?? 0, 'powod' => 'drugi raz']);
    $t('cofnięcie: drugi raz tej samej → 409; poprzedniej (już nie ostatniej) nie da się cofnąć, przycisku brak',
        $kodE === 409 && ($hA['cofnij_realizacje'] ?? null) === null && $saldo() === ['200.00', 'valid']);
    bon_panel_realizuj($k, $pdo, $sKasa, ['kod' => $kodC, 'kwota_reczna' => '50', 'client_token' => $tok()]);
    $poDobie = bon_teraz()->modify('+25 hours');
    [, $hP] = bon_panel_historia($k, $pdo, $sAdmin, $kodC, $poDobie);
    [$kodF] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kodC, 'akcja' => 'cofnij-realizacje', 'realizacja' => $hP['historia'][0]['id'] ?? 0, 'powod' => 'po czasie'], $poDobie);
    $t('cofnięcie: po 24 h przycisku nie ma, serwer odmawia (409)', ($hP['cofnij_realizacje'] ?? null) === null && $kodF === 409 && $saldo() === ['150.00', 'valid']);
    // pytanie 3a (wpis 17:30): przy rezygnacji (kwota zwrotu zamrożona w wierszu rezygnacji) cofnięcia realizacji nie ma
    bon_panel_realizuj($k, $pdo, $sKasa, ['kod' => $kodC, 'kwota_reczna' => '20', 'client_token' => $tok()]);
    [, $hR] = bon_panel_historia($k, $pdo, $sAdmin, $kodC);
    $idOst = $hR['cofnij_realizacje']['id'] ?? 0;
    $dzisR = bon_teraz()->format('Y-m-d');
    bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kodC, 'akcja' => 'rezygnacja', 'kanal' => 'mail', 'data' => $dzisR, 'otrzymano' => $dzisR]);
    [, $hR2] = bon_panel_historia($k, $pdo, $sAdmin, $kodC);
    [$kodG] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kodC, 'akcja' => 'cofnij-realizacje', 'realizacja' => $idOst, 'powod' => 'po rezygnacji']);
    $t('cofnięcie (3a): bon z rezygnacją – przycisku nie ma, serwer odmawia (409), saldo bez zmian',
        $idOst > 0 && ($hR2['cofnij_realizacje'] ?? null) === null && $kodG === 409 && $saldo() === ['130.00', 'withdrawn'], json_encode([$idOst, $kodG, $saldo()]));
    return $wyniki;
}
