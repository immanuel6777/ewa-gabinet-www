<?php
// Scenariusze testowe backendu bonów – JEDEN zestaw dla dwóch baz (etap 1: 2026-11-21):
//  - lokalnie SQLite: tests/php/bony-test.php,
//  - na OVH MySQL, tabele test_*: /api/bon/test-ovh.php (z tokenem z konfigu).
// Zwraca listę [nazwa, ok, szczegół]. Nic nie wypisuje. Katalog lib/ zablokowany w .htaccess produkcji.

declare(strict_types=1);
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/panel.php';
require_once __DIR__ . '/testy-panel.php';
require_once __DIR__ . '/diagnostyka.php';

/** Payload jak w dokumentacji Easytools (single_product_bought), z nadpisaniami. */
function bon_test_payload(array $nadpisz = []): array {
    return array_replace([
        'event' => 'single_product_bought',
        'timestamp' => time(),
        'success' => true,
        'is_subscription' => false,
        'order_id' => random_int(100000000, 999999999),
        'order_uuid' => '00000000-0000-0000-0000-000000000000',
        'amount_paid' => 200,
        'order_amount' => 200,
        'currency' => 'pln',
        'quantity' => 1,
        'customer_email' => 'test@example.com',
        'customer_name' => 'Test Testowy',
        'price_id' => 'price_test_200',
        'price_custom_id' => 'bon-200',
        'promo_code' => null,
        'custom_fields' => ['bon-dla' => 'Mama', 'bon-od' => 'Ania'], // nazwy pól jak w sklepie (Easytools: „_” → „-”)
        'invoice_data' => null,
    ], $nadpisz);
}

function bon_test_konfig(array $k): array {
    return array_replace($k, [
        'webhook_tryb' => 'bon',
        'easytools_webhook_sekret' => 'sekret-testowy-1234567890',
        'kwota_jednostka' => 'pln',
        'ceny' => ['bon-200' => 200, 'bon-300' => 300, 'bon-500' => 500, 'bon-dowolna' => 'dowolna'],
        'prog_wstrzymania' => 0.8,
        'min_dowolna_pln' => 180,
        'waznosc_miesiecy' => 6,
        'kod_prefix' => 'EWA',
        'pole_dla' => ['bon-dla', 'bon_dla', 'Dla kogo (imię na bonie)'],
        'pole_od' => ['bon-od', 'bon_od', 'Od kogo'],
        'verify_limit_na_minute' => 1000,
        // wysyłka NIGDY prawdziwa w testach (też na OVH): treść maila do pliku bony-mailjet.jsonl obok konfigu
        'mail_tryb' => 'plik',
        'mail_nadawca' => 'Gabinet EWA <powiadomienia@ewa-gabinet.pl>',
        'mailjet_test_blad' => false,
        // etap 3: konta panelu (hasła tylko testowe, hash liczony przy starcie) i lista usług z pliku obok konfigu
        'panel_konta' => [
            'ewa' => ['haslo' => password_hash('haslo-ewy-test', PASSWORD_DEFAULT), 'imie' => 'Ewa', 'rola' => 'realizacja'],
            'bartek' => ['haslo' => password_hash('haslo-bartka-test', PASSWORD_DEFAULT), 'imie' => 'Bartek', 'rola' => 'admin'],
        ],
        'uslugi_plik' => bon_test_uslugi((string)($k['_katalog'] ?? sys_get_temp_dir())),
        'kontakt_plik' => bon_test_kontakt((string)($k['_katalog'] ?? sys_get_temp_dir())),
    ]);
}

/** Kontakt jak z buildu (src/pages/api/bon/lib/kontakt.json.ts), zapisany obok konfigu – wartości celowo testowe. */
function bon_test_kontakt(string $katalog): string {
    $plik = $katalog . '/bony-test-kontakt.json';
    file_put_contents($plik, json_encode(['telefon' => '600 000 001', 'ulica' => 'ul. Testowa 1', 'miasto' => 'Pabianice'], JSON_UNESCAPED_UNICODE));
    return $plik;
}

/** Lista usług jak z buildu (src/pages/api/bon/lib/uslugi.json.ts), zapisana obok konfigu. */
function bon_test_uslugi(string $katalog): string {
    $plik = $katalog . '/bony-test-uslugi.json';
    file_put_contents($plik, json_encode(['grupy' => [['nazwa' => 'Testowe', 'pozycje' => [
        ['id' => 'pedicure~0', 'nazwa' => 'Pedicure podologiczny podstawowy', 'cena' => 180],
        ['id' => 'klamra~0', 'nazwa' => 'Klamra podologiczna', 'cena' => 180, 'od' => true],
        ['id' => 'wkladka~0', 'nazwa' => 'Wkładka indywidualna', 'cena' => 180, 'cena_do' => 250],
    ]]]], JSON_UNESCAPED_UNICODE));
    return $plik;
}

/** Wysyła webhook przez bon_obsluz_webhook; $podpis null = poprawny podpis. */
function bon_test_webhook(array $k, PDO $pdo, array $payload, ?string $podpis = null, ?string $raw = null): array {
    $raw ??= json_encode($payload, JSON_UNESCAPED_UNICODE);
    $podpis ??= hash_hmac('sha256', $raw, $k['easytools_webhook_sekret']);
    return bon_obsluz_webhook($k, $raw, ['x-webhook-signature' => $podpis], static fn() => $pdo);
}

function bon_test_bony(array $k, PDO $pdo, $zamowienie): array {
    $st = $pdo->prepare('SELECT * FROM ' . bon_tabela($k, 'vouchers') . ' WHERE easytools_order_id = ? ORDER BY nr_w_zamowieniu');
    $st->execute([(string)$zamowienie]);
    return $st->fetchAll();
}

function bon_testy(array $kBaza, PDO $pdo): array {
    $k = bon_test_konfig($kBaza);
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $zl = static fn($v) => bon_zl((int)bon_grosze($v));
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

    // --- funkcje pomocnicze ---
    $t('kwoty: 0.10 + 0.20 = 0.30 w groszach', bon_zl(bon_grosze('0.10') + bon_grosze('0.20')) === '0.30');
    $t('kwoty: 50 zł w groszach z Easytools („grosze”)', bon_grosze(5000, 'grosze') === 5000 && bon_grosze(50) === 5000);
    $t('kwoty: zła wartość → null', bon_grosze('abc') === null && bon_grosze(-5) === null && bon_grosze(null) === null);
    $strefa = new DateTimeZone(BON_STREFA);
    $t('ważność: 2026-11-21 + 12 mies.', bon_waznosc(new DateTimeImmutable('2026-11-21', $strefa), 12) === '2027-11-21');
    $t('ważność: 29.02.2028 + 12 mies. → 28.02.2029', bon_waznosc(new DateTimeImmutable('2028-02-29', $strefa), 12) === '2029-02-28');
    $t('ważność: 31.01 + 1 mies. → 28.02', bon_waznosc(new DateTimeImmutable('2027-01-31', $strefa), 1) === '2027-02-28');
    $kod = bon_nowy_kod();
    $t('kod: format EWA-XXXX-XXXX bez 0/O/1/I', (bool)preg_match('/^EWA-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $kod), $kod);
    $t('kod: wpisany małymi, bez myślników → ten sam', bon_normalizuj_kod(strtolower(str_replace('-', ' ', $kod))) === $kod);
    $t('kod: zły format → null', bon_normalizuj_kod('EWA-2026-00042') === null && bon_normalizuj_kod('EWA-OOOO-1111') === null);

    // --- webhook ---
    $p = bon_test_payload();
    [$kodHttp, $odp] = bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $oczekWaznosc = bon_waznosc(bon_teraz(), 6);
    $t('webhook poprawny: 200 i jeden bon', $kodHttp === 200 && count($b) === 1, json_encode($odp));
    $t('webhook poprawny: saldo = wartość = zapłacono = 200.00', $b && $zl($b[0]['initial_pln']) === '200.00' && $zl($b[0]['balance_pln']) === '200.00' && $zl($b[0]['paid_pln']) === '200.00');
    $t('webhook poprawny: status valid, ważność = zakup + 6 mies.', $b && $b[0]['status'] === 'valid' && substr((string)$b[0]['expires_at'], 0, 10) === $oczekWaznosc, $b ? $b[0]['expires_at'] . ' vs ' . $oczekWaznosc : '');
    $t('webhook poprawny: Dla/Od z pól własnych, e-mail', $b && $b[0]['dla'] === 'Mama' && $b[0]['od'] === 'Ania' && $b[0]['buyer_email'] === 'test@example.com');
    $kodBonu = $b[0]['code'] ?? '';

    [$kodHttp, $odp] = bon_test_webhook($k, $pdo, $p);
    $t('webhook powtórzony: 200, „powtorzony”, nadal jeden bon', $kodHttp === 200 && ($odp['powtorzony'] ?? false) === true && count(bon_test_bony($k, $pdo, $p['order_id'])) === 1, json_encode($odp));

    $p2 = bon_test_payload();
    [$kodHttp] = bon_test_webhook($k, $pdo, $p2, str_repeat('0', 64));
    $t('webhook zły podpis: 401, bon nie powstaje', $kodHttp === 401 && !bon_test_bony($k, $pdo, $p2['order_id']));
    [$kodHttp] = bon_test_webhook($k, $pdo, $p2, '');
    $t('webhook bez podpisu: 401', $kodHttp === 401);
    $raw = json_encode($p2);
    [$kodHttp] = bon_test_webhook($k, $pdo, $p2, hash_hmac('sha256', $raw, $k['easytools_webhook_sekret']), $raw . ' ');
    $t('webhook body zmienione po podpisaniu: 401', $kodHttp === 401);

    $p = bon_test_payload(['price_custom_id' => 'bon-dowolna', 'amount_paid' => 237.5]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('kwota spoza progów (dowolna 237.50): bon na kwotę zapłaconą', $b && $zl($b[0]['initial_pln']) === '237.50' && $b[0]['status'] === 'valid');

    $p = bon_test_payload(['price_custom_id' => 'bon-dowolna', 'amount_paid' => 150]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('dowolna poniżej 180 zł: wstrzymany (held)', $b && $b[0]['status'] === 'held');

    $p = bon_test_payload(['price_custom_id' => 'bon-300', 'amount_paid' => 270, 'promo_code' => 'SWIETA10']);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('rabat 10%: wartość = nominał 300, zapłacono 270, kod zapisany', $b && $zl($b[0]['initial_pln']) === '300.00' && $zl($b[0]['paid_pln']) === '270.00' && $b[0]['promo_code'] === 'SWIETA10' && $b[0]['status'] === 'valid');

    $p = bon_test_payload(['price_custom_id' => 'bon-300', 'amount_paid' => 200, 'promo_code' => 'DUZO']);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('rabat > 20% (200 przy 300): held z powodem', $b && $b[0]['status'] === 'held' && str_contains((string)$b[0]['status_uwagi'], 'nominale 300.00'), $b[0]['status_uwagi'] ?? '');

    $p = bon_test_payload(['price_id' => 'price_nieznany', 'price_custom_id' => null, 'amount_paid' => 250]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('nieznany wariant ceny: held, wartość = zapłacono', $b && $b[0]['status'] === 'held' && $zl($b[0]['initial_pln']) === '250.00');

    $p = bon_test_payload(['price_id' => 'bon-500', 'price_custom_id' => null, 'amount_paid' => 500]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('identyfikator wariantu w price_id (bon-500): nominał 500', $b && $zl($b[0]['initial_pln']) === '500.00' && $b[0]['status'] === 'valid');

    $p = bon_test_payload(['quantity' => 3, 'amount_paid' => 600]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('quantity 3: trzy bony po 200, różne kody, te same Dla/Od', count($b) === 3
        && count(array_unique(array_column($b, 'code'))) === 3
        && array_map($zl, array_column($b, 'initial_pln')) === ['200.00', '200.00', '200.00']
        && array_unique(array_column($b, 'dla')) === ['Mama']);
    [, $odp] = bon_test_webhook($k, $pdo, $p);
    $t('quantity 3 powtórzony: nadal trzy bony', ($odp['powtorzony'] ?? false) && count(bon_test_bony($k, $pdo, $p['order_id'])) === 3);

    $p = bon_test_payload(['quantity' => '2', 'amount_paid' => 400]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('quantity jako tekst "2": dwa bony po 200', count($b) === 2 && $b[0]['status'] === 'valid');

    $p = bon_test_payload(['quantity' => 'trzy', 'amount_paid' => 600]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('quantity nieczytelne przy 600 zł: 1 bon held (nic nie znika bez śladu)', count($b) === 1 && $b[0]['status'] === 'held', $b[0]['status_uwagi'] ?? '');

    $p = bon_test_payload(['quantity' => 1, 'amount_paid' => 600]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('zapłacono więcej niż nominał (600 za bon 200): held', $b && $b[0]['status'] === 'held' && str_contains((string)$b[0]['status_uwagi'], 'więcej niż nominał'));

    $p = bon_test_payload(['quantity' => 60, 'amount_paid' => 12000]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('quantity 60 > 50: 50 bonów held z informacją', count($b) === 50 && $b[0]['status'] === 'held' && str_contains((string)$b[0]['status_uwagi'], 'ilość 60'));

    $p = bon_test_payload(['event' => 'product_assigned', 'amount_paid' => null]);
    [$kodHttp, $odp] = bon_test_webhook($k, $pdo, $p);
    $t('product_assigned: pominięty, bez bonu', $kodHttp === 200 && ($odp['pominieto'] ?? '') === 'product_assigned' && !bon_test_bony($k, $pdo, $p['order_id']));

    $p = bon_test_payload(['success' => false]);
    bon_test_webhook($k, $pdo, $p);
    $t('success = false: bez bonu', !bon_test_bony($k, $pdo, $p['order_id']));

    $p = bon_test_payload(['custom_fields' => [['name' => 'Dla kogo (imię na bonie)', 'value' => 'Tata'], ['label' => 'Od kogo', 'value' => 'Zosia']]]);
    bon_test_webhook($k, $pdo, $p);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('pola własne jako lista {name,value}: Dla/Od odczytane', $b && $b[0]['dla'] === 'Tata' && $b[0]['od'] === 'Zosia');

    // --- PDF i mail (etap 2) ---
    $nb = "\u{00A0}";
    $t('kwota na bonie: 250 zł / 237,50 zł / 1 200 zł', bon_kwota_tekst(25000) === "250{$nb}zł" && bon_kwota_tekst(23750) === "237,50{$nb}zł" && bon_kwota_tekst(120000) === "1{$nb}200{$nb}zł");
    $t('kolor wstążki: 200 czerwona, 300 złota, 500 zielona', bon_pdf_prog(20000) === 'czerwony' && bon_pdf_prog(30000) === 'zloty' && bon_pdf_prog(50000) === 'zielen' && bon_pdf_prog(23750) === 'czerwony');
    $t('ważność słownie: 1 miesiąc, 3 miesiące, 6 miesięcy, 12 miesięcy, 22 miesiące', bon_miesiace_tekst(1) === '1 miesiąc' && bon_miesiace_tekst(3) === '3 miesiące'
        && bon_miesiace_tekst(6) === '6 miesięcy' && bon_miesiace_tekst(12) === '12 miesięcy' && bon_miesiace_tekst(22) === '22 miesiące');
    $pdfBajty = bon_pdf(['code' => 'EWA-TEST-2345', 'initial_pln' => '300.00', 'expires_at' => '2027-11-21', 'dla' => 'Żaneta Ćwik', 'od' => null], $k);
    $t('PDF: powstaje, poprawny nagłówek, < 1,5 MB', str_starts_with($pdfBajty, '%PDF-') && strlen($pdfBajty) < 1500000 && str_contains($pdfBajty, '%%EOF'), strlen($pdfBajty) . ' B');
    $jsonl = $k['_katalog'] . '/bony-mailjet.jsonl';
    $maile = static function () use ($jsonl): array {
        return is_file($jsonl) ? array_values(array_map(static fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($jsonl))))) : [];
    };
    $przed = count($maile());
    $p = bon_test_payload(['customer_email' => 'kupujacy@example.com']);
    [, , $zadanie] = bon_test_webhook($k, $pdo, $p);
    $t('webhook valid: zwraca zadanie wysyłki', is_callable($zadanie));
    $w = $zadanie ? $zadanie() : [];
    $m = array_slice($maile(), $przed);
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('mail: 1 wiadomość do kupującego, 1 PDF, nadawca z konfigu', ($w['wyslane'] ?? 0) === 1 && count($m) === 1 && $m[0]['To'][0]['Email'] === 'kupujacy@example.com'
        && count($m[0]['Attachments']) === 1 && $m[0]['Attachments'][0]['pdf'] === true && $m[0]['From']['Email'] === 'powiadomienia@ewa-gabinet.pl', json_encode($w));
    $t('mail: treść z numerem bonu, bez wykrzykników', $m && $b && str_contains($m[0]['TextPart'], $b[0]['code']) && !str_contains($m[0]['TextPart'] . strip_tags($m[0]['HTMLPart']), '!'));
    $t('mail: mail_wyslany_at ustawione', $b && $b[0]['mail_wyslany_at'] !== null);
    $t('mail: telefon, adres i ważność z kontakt.json i konfigu (nie na sztywno)', $m && str_contains($m[0]['TextPart'], '600 000 001') && str_contains($m[0]['TextPart'], 'ul. Testowa 1')
        && str_contains($m[0]['TextPart'], 'ważny ' . bon_miesiace_tekst((int)$k['waznosc_miesiecy'])));
    $w = $zadanie ? $zadanie() : [];
    $t('mail: drugie wywołanie nic nie wysyła', ($w['pominieto'] ?? '') === 'nic do wysłania' && count($maile()) === $przed + 1);
    [, , $zadanie] = bon_test_webhook($k, $pdo, $p);
    $w = $zadanie ? $zadanie() : [];
    $t('mail: powtórzony webhook po udanej wysyłce nie wysyła drugi raz', ($w['wyslane'] ?? -1) === 0 && count($maile()) === $przed + 1);

    $p = bon_test_payload(['price_custom_id' => 'bon-300', 'amount_paid' => 100]);
    [, , $zadanie] = bon_test_webhook($k, $pdo, $p);
    $t('mail: bon wstrzymany (held) – bez wysyłki', $zadanie === null);

    $przed = count($maile());
    $p = bon_test_payload(['quantity' => 12, 'amount_paid' => 2400]);
    [, , $zadanie] = bon_test_webhook($k, $pdo, $p);
    $w = $zadanie ? $zadanie() : [];
    $m = array_slice($maile(), $przed);
    $t('quantity 12: dwa maile (10 + 2 PDF), 12 bonów oznaczonych', ($w['wyslane'] ?? 0) === 12 && count($m) === 2 && count($m[0]['Attachments']) === 10 && count($m[1]['Attachments']) === 2
        && !array_filter(bon_test_bony($k, $pdo, $p['order_id']), static fn($b) => $b['mail_wyslany_at'] === null), json_encode($w));

    $kBlad = array_replace($k, ['mailjet_test_blad' => true]);
    $p = bon_test_payload();
    [, , $zadanie] = bon_test_webhook($kBlad, $pdo, $p);
    $w = $zadanie ? $zadanie() : [];
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('Mailjet błąd: zwraca błąd, mail_wyslany_at puste', ($w['blad'] ?? null) !== null && $b && $b[0]['mail_wyslany_at'] === null);
    [, $odp, $zadanie] = bon_test_webhook($k, $pdo, $p);
    $w = $zadanie ? $zadanie() : [];
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('Mailjet błąd → ponowiony webhook dokańcza wysyłkę', ($odp['powtorzony'] ?? false) && ($w['wyslane'] ?? 0) === 1 && $b[0]['mail_wyslany_at'] !== null, json_encode($w));

    // --- podgląd diagnostyczny (log.php): dane osobowe nigdy nie wychodzą ---
    $p = bon_test_payload(['customer_email' => 'jan.kowalski@example.com', 'customer_name' => 'Jan Kowalski', 'promo_code' => 'TEST99',
        'invoice_data' => ['nip' => '7311314632', 'company' => 'Firma Jana', 'street' => 'ul. Prosta 1'], 'customer_phone' => '+48 600 100 200',
        'custom_fields' => [['name' => 'bon-dla', 'value' => 'Mama Halina'], ['name' => 'bon-od', 'value' => 'Zosia']], 'uwaga' => 'kontakt: jan@example.org, tel. 600100200']);
    $u = json_encode(bon_log_ukryj(['naglowki' => ['x-webhook-signature' => str_repeat('a', 64), 'remote-ip' => '203.0.113.7', 'x-forwarded-for' => '203.0.113.7', 'user-agent' => 'Easytools'],
        'body' => $p]), JSON_UNESCAPED_UNICODE);
    $wyciek = array_filter(['jan.kowalski', 'Kowalski', 'TEST99', '7311314632', 'Firma Jana', 'Prosta', '600 100 200', 'Halina', 'Zosia', 'jan@example.org', '600100200', str_repeat('a', 64), '203.0.113.7'],
        static fn($s) => str_contains((string)$u, $s));
    $t('podgląd: e-mail, imię, NIP, adres, telefon, Dla/Od, kod rabatowy, podpis i IP ukryte', !$wyciek, implode(', ', $wyciek));
    $t('podgląd: zostaje to, co potrzebne do diagnozy (zdarzenie, kwota, waluta, wariant, order_id, user-agent, puste pola)',
        str_contains((string)$u, 'single_product_bought') && str_contains((string)$u, '"amount_paid":200') && str_contains((string)$u, '"price_custom_id":"bon-200"')
        && str_contains((string)$u, '"order_id":' . $p['order_id']) && str_contains((string)$u, 'Easytools') && str_contains((string)$u, '"customer_email":"***"'), (string)$u);
    $t('podgląd: nazwy pól własnych (lista {name,value}) jawne, wartości ukryte', str_contains((string)$u, '"name":"bon-dla"') && str_contains((string)$u, '"name":"bon-od"')
        && !str_contains((string)$u, 'Halina'), (string)$u);
    $uSlownik = json_encode(bon_log_ukryj(['custom_fields' => ['bon-dla' => 'Mama Halina'], 'invoice_data' => ['name' => 'Firma Jana']]), JSON_UNESCAPED_UNICODE);
    $t('podgląd: pola własne jako słownik – klucz jawny; name w danych faktury ukryte', str_contains((string)$uSlownik, '"bon-dla":"***"')
        && !str_contains((string)$uSlownik, 'Halina') && !str_contains((string)$uSlownik, 'Firma Jana'), (string)$uSlownik);
    $t('podgląd: kod bonu zamaskowany', bon_kod_maska('EWA-7KQ4-M9TX') === 'EWA-****-M9TX');
    $t('podgląd: błąd bazy bez loginu', !str_contains(bon_opis_bledu(new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'yppvggk'@'10.0.0.1' (using password: YES)"))['opis'], 'yppvggk'));

    // --- verify ---
    $ip = '192.0.2.' . random_int(1, 250);
    [$kodHttp, $odp] = bon_obsluz_verify($k, $kodBonu, $ip, static fn() => $pdo);
    $t('verify ważny: status valid, saldo 200.00, data', $kodHttp === 200 && $odp['status'] === 'valid' && $odp['saldo'] === '200.00' && $odp['wazny_do'] === $oczekWaznosc, json_encode($odp));
    $t('verify: brak danych osobowych w odpowiedzi', !array_intersect(array_keys($odp), ['buyer_email', 'buyer_name', 'dla', 'od', 'buyer_nip', 'email']) && !str_contains(json_encode($odp), 'example.com'));
    [$kodHttp, $odp2] = bon_obsluz_verify($k, strtolower(str_replace('-', '', $kodBonu)), $ip, static fn() => $pdo);
    $t('verify: kod małymi literami bez myślników działa', $kodHttp === 200 && ($odp2['kod'] ?? '') === $kodBonu);

    $wczoraj = bon_teraz()->modify('-1 day')->format('Y-m-d');
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . ' SET expires_at = ? WHERE code = ?')->execute([$wczoraj, $kodBonu]);
    [$kodHttp, $odp] = bon_obsluz_verify($k, $kodBonu, $ip, static fn() => $pdo);
    $t('verify wygasły (expires_at wczoraj): status expired, bez salda', $kodHttp === 200 && $odp['status'] === 'expired' && !isset($odp['saldo']), json_encode($odp));
    $dzis = bon_teraz()->format('Y-m-d');
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . ' SET expires_at = ? WHERE code = ?')->execute([$dzis, $kodBonu]);
    [, $odp] = bon_obsluz_verify($k, $kodBonu, $ip, static fn() => $pdo);
    $t('verify w ostatnim dniu ważności: nadal valid', $odp['status'] === 'valid');

    [$kodHttp, $odp] = bon_obsluz_verify($k, bon_nowy_kod(), $ip, static fn() => $pdo);
    $t('verify nieistniejący: 404', $kodHttp === 404 && $odp['znaleziono'] === false);
    [$kodHttp] = bon_obsluz_verify($k, "EWA-2026-00042' OR 1=1", $ip, static fn() => $pdo);
    $t('verify zły format: 400 (bez zapytania do bazy)', $kodHttp === 400);

    $kLimit = array_replace($k, ['verify_limit_na_minute' => 3]);
    $ip2 = '198.51.100.' . random_int(1, 250);
    $kody = [];
    for ($i = 0; $i < 4; $i++) $kody[] = bon_obsluz_verify($kLimit, $kodBonu, $ip2, static fn() => $pdo)[0];
    $t('verify limit 3/min: czwarte zapytanie 429', $kody === [200, 200, 200, 429], implode(',', $kody));

    // --- baza ---
    $tab = bon_tabela($k, 'vouchers');
    $pdo->prepare("UPDATE $tab SET balance_pln = ? WHERE code = ?")->execute(['199.99', $kodBonu]);
    $st = $pdo->prepare("SELECT balance_pln FROM $tab WHERE code = ?");
    $st->execute([$kodBonu]);
    $t('DECIMAL: 199.99 zapisane i odczytane bez zaokrągleń', $zl($st->fetchColumn()) === '199.99');
    if ($mysql) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT balance_pln FROM $tab WHERE code = ? FOR UPDATE");
        $st->execute([$kodBonu]);
        $pdo->commit();
        $t('MySQL: SELECT … FOR UPDATE w transakcji działa', true);
        $tz = $pdo->query("SELECT NOW() AS n, @@session.time_zone AS s, @@system_time_zone AS sys")->fetch();
        $t('MySQL: strefa czasu (informacyjnie – daty liczy PHP)', true, "NOW()={$tz['n']} session={$tz['s']} system={$tz['sys']} PHP=" . bon_teraz()->format('Y-m-d H:i:s'));
    }
    return array_merge($wyniki, bon_testy_panel($k, $pdo));
}
