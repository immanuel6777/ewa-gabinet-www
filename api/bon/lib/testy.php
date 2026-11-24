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
require_once __DIR__ . '/testy-sync.php';
require_once __DIR__ . '/testy-krok1.php';
require_once __DIR__ . '/testy-krok4.php';
require_once __DIR__ . '/testy-obsluga.php';

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
        // test-ovh.php bierze prawdziwy konfig: powiadomienia gabinetu w testach też tylko do pliku (tryb „plik”), z adresem testowym,
        // a zapas SMTP i jego test wyłączone – scenariusze zapasu ustawiają je same (2026-11-24: 8 fałszywych błędów na OVH)
        'mail_powiadomienie' => ['powiadomienia-test@example.invalid'],
        'mail_zapas' => false,
        'mail_zapas_test_do' => '',
        'mail_nadawca' => 'Gabinet EWA <powiadomienia@ewa-gabinet.pl>',
        'mailjet_test_blad' => false,
        'mail_ponow_przerwy_s' => [0, 0], // testy: ponawianie bez czekania
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
    // na OVH w konfig/bony-mail-wysylki/ leżą prawdziwe wysyłki: test sprawdza, że testy nie DOPISAŁY nowych
    $wysylkiPrzed = glob($k['_katalog'] . '/bony-mail-wysylki/*.json') ?: [];
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
    $kol = static fn(string $id, string $zl) => bon_pdf_kolor($k, ['price_id' => $id, 'initial_pln' => $zl]);
    $t('tło PDF wg rodzaju: 200 zielone, 300 czerwone, 500 złote, dowolna granatowa (też przy 300 i 500 zł)',
        $kol('bon-200', '200.00') === 'zielen' && $kol('Bon Rubinowy', '300.00') === 'czerwony' && $kol('Bon 500 zł', '500.00') === 'zloty'
        && $kol('Bon Szafirowy', '500.00') === 'granat' && $kol('bon-dowolna', '300.00') === 'granat' && $kol('', '237.50') === 'granat'
        && $kol('', '300.00') === 'czerwony');
    $t('tła PDF: 4 kolory × przód i tył są w lib/grafika', count(array_filter(['zielen', 'czerwony', 'zloty', 'granat'],
        static fn($c) => is_file(__DIR__ . "/grafika/tlo-przod-$c.jpg") && is_file(__DIR__ . "/grafika/tlo-tyl-$c.jpg"))) === 4);
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

    // powiadomienie o nieudanej wysyłce: raz na zamówienie, potem najwyżej raz na dobę (cron ponawia co godzinę)
    $plikPow = $k['_katalog'] . '/bony-powiadomienia.txt';
    // znaczniki z poprzedniego uruchomienia (na OVH zostają w prawdziwym konfig/) zaniżałyby liczbę powiadomień
    $znaczniki = array_map(static fn($z) => $k['_katalog'] . '/bony-mail-bledy/' . md5($z) . '.txt', ['ord_powiadom_raz', 'ord_doba']);
    array_map(static fn($f) => @unlink($f), $znaczniki);
    $ilePow = static fn(string $zam): int => substr_count(is_file($plikPow) ? (string)file_get_contents($plikPow) : '', 'NIE wysłano maila – zamówienie ' . $zam . "\n");
    $p = bon_test_payload(['order_id' => 'ord_powiadom_raz']);
    [, , $zadanie] = bon_test_webhook($kBlad, $pdo, $p);
    if ($zadanie) $zadanie();
    bon_wyslij_zamowienie($kBlad, $pdo, $p['order_id']);
    bon_wyslij_zamowienie($kBlad, $pdo, $p['order_id']);
    $t('Mailjet błąd 3× z rzędu: jedno powiadomienie', $ilePow($p['order_id']) === 1, (string)$ilePow($p['order_id']));
    $t('powiadomienie o błędzie: po dobie znów wolno', bon_powiadomienie_bledu_czas($k, 'ord_doba', true) && !bon_powiadomienie_bledu_czas($k, 'ord_doba', true)
        && bon_powiadomienie_bledu_czas($k, 'ord_doba', true, 0));
    array_map(static fn($f) => @unlink($f), $znaczniki);
    $w = bon_wyslij_zamowienie($k, $pdo, $p['order_id']);
    $t('po udanej wysyłce znacznik błędu usunięty', ($w['wyslane'] ?? 0) === 1 && !is_file($k['_katalog'] . '/bony-mail-bledy/' . md5($p['order_id']) . '.txt'));

    // ponawianie przy zerwanym połączeniu (SSL_ERROR_SYSCALL z OVH): tylko błąd sprzed wysłania treści, najwyżej 3 próby
    $ileMaili = static fn(): int => count(file($k['_katalog'] . '/bony-mailjet.jsonl') ?: []);
    $p = bon_test_payload(['order_id' => 'ord_ponow_ok']);
    $przed = $ileMaili();
    $GLOBALS['bon_test_zerwania'] = 2;
    [, , $zadanie] = bon_test_webhook($k, $pdo, $p);
    $w = $zadanie ? $zadanie() : [];
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('2 zerwania połączenia → 3. próba wysyła, jeden mail', ($w['wyslane'] ?? 0) === 1 && ($w['mailjet'][0]['proby'] ?? 0) === 3
        && $ileMaili() === $przed + 1 && $b[0]['mail_wyslany_at'] !== null, json_encode($w));
    $t('odpowiedź Mailjeta zapisana: HTTP, Status, MessageID', ($w['mailjet'][0]['http'] ?? 0) === 200 && ($w['mailjet'][0]['status'] ?? '') === 'success'
        && count($w['mailjet'][0]['id'] ?? []) === 1);
    $p = bon_test_payload(['order_id' => 'ord_ponow_zle']);
    $GLOBALS['bon_test_zerwania'] = 5;
    [, , $zadanie] = bon_test_webhook($k, $pdo, $p);
    $w = $zadanie ? $zadanie() : [];
    $b = bon_test_bony($k, $pdo, $p['order_id']);
    $t('3 zerwania z rzędu → błąd po 3 próbach, mail_wyslany_at puste', ($w['blad'] ?? null) !== null && ($w['mailjet'][0]['proby'] ?? 0) === 3
        && $b[0]['mail_wyslany_at'] === null, json_encode($w));
    $GLOBALS['bon_test_zerwania'] = 0;
    $p = bon_test_payload(['order_id' => 'ord_http_blad']);
    [, , $zadanie] = bon_test_webhook($kBlad, $pdo, $p);
    $w = $zadanie ? $zadanie() : [];
    $t('błąd HTTP Mailjeta → bez ponawiania (1 próba)', ($w['blad'] ?? null) !== null && ($w['mailjet'][0]['proby'] ?? 0) === 1, json_encode($w));
    $ostatniMailjet = json_decode((string)(array_slice(file($k['_katalog'] . '/bony-mailjet.jsonl') ?: [''], -1)[0]), true);
    $t('Mailjet: śledzenie otwarć i kliknięć wyłączone w wiadomości', ($ostatniMailjet['TrackOpens'] ?? '') === 'disabled' && ($ostatniMailjet['TrackClicks'] ?? '') === 'disabled');

    // zapas SMTP OVH (22:20) – we własnym katalogu tymczasowym (test-ovh.php działa na prawdziwym konfig/)
    $kOrgZ = $k;
    $katZ = sys_get_temp_dir() . '/bony-zapas-' . bin2hex(random_bytes(4));
    @mkdir($katZ, 0700, true);
    $kZ = array_replace($k, ['_katalog' => $katZ, 'mail_zapas' => true, 'mail_smtp_host' => 'ssl0.ovh.net', 'mail_smtp_port' => 465,
        'mail_smtp_user' => 'powiadomienia@ewa-gabinet.pl', 'mail_smtp_haslo' => 'tajne-haslo-smtp-123', 'mail_odpowiedz' => 'kontakt@ewa-gabinet.pl']);
    $ileSmtp = static fn(): int => count(is_file($katZ . '/bony-smtp.jsonl') ? (file($katZ . '/bony-smtp.jsonl') ?: []) : []);
    $ileMj = static fn(): int => count(is_file($katZ . '/bony-mailjet.jsonl') ? (file($katZ . '/bony-mailjet.jsonl') ?: []) : []);
    $wyslijZ = static function (array $kk, string $zam, int $zerwania) use ($pdo): array {
        $GLOBALS['bon_test_zerwania'] = $zerwania;
        [, , $zadanie] = bon_test_webhook($kk, $pdo, bon_test_payload(['order_id' => $zam]));
        $w = $zadanie ? $zadanie() : [];
        $GLOBALS['bon_test_zerwania'] = 0;
        return [$w, bon_test_bony($kk, $pdo, $zam)];
    };
    [$w, $b] = $wyslijZ($kZ, 'ord_zapas_ok', 3);
    $t('zapas: 3 zerwania Mailjeta → ten sam mail przez SMTP OVH, bon wysłany', array_key_exists('blad', $w) && $w['blad'] === null && ($w['mailjet'][0]['droga'] ?? '') === 'smtp-ovh'
        && $ileSmtp() === 1 && $b[0]['mail_wyslany_at'] !== null, json_encode([$w, $ileSmtp(), $b[0]['mail_wyslany_at'], glob($katZ . '/*')]));
    $smtp = json_decode((string)(file($katZ . '/bony-smtp.jsonl') ?: [''])[0], true);
    $nagl = implode("\n", (array)($smtp['naglowki'] ?? []));
    $t('zapas: wiadomość MIME – nadawca z nazwą, Reply-To, temat, PDF, multipart', str_contains($nagl, '<powiadomienia@ewa-gabinet.pl>') && str_contains($nagl, '=?UTF-8?B?')
        && str_contains($nagl, 'Reply-To: <kontakt@ewa-gabinet.pl>') && str_contains($nagl, 'multipart/mixed') && str_contains($nagl, 'Message-ID: <')
        && count($smtp['zalaczniki'] ?? []) === 1 && str_ends_with((string)$smtp['zalaczniki'][0], '.pdf'), $nagl);
    $mime = bon_mail_mime(bon_mail_wiadomosc($kZ, 'jan@example.com', 'Twój bon podarunkowy – Gabinet EWA', ".kropka\nlinia", '<p>x</p>', [['nazwa' => 'bon-A.pdf', 'dane' => '%PDF-1.4 test']]));
    $t('zapas: MIME z CRLF, tekst i HTML w multipart/alternative, temat w UTF-8 do odczytania', !preg_match("/[^\r]\n/", $mime) && str_contains($mime, 'multipart/alternative')
        && str_contains($mime, 'filename="bon-A.pdf"') && mb_decode_mimeheader(preg_match('/^Subject: (.*(?:\r\n[ \t].*)*)/m', $mime, $m) ? $m[1] : '') === 'Twój bon podarunkowy – Gabinet EWA');
    [$w, $b] = $wyslijZ(array_replace($kZ, ['mailjet_test_blad' => true]), 'ord_zapas_http', 0);
    $t('zapas: błąd HTTP Mailjeta → bez zapasu (Mailjet dostał treść)', ($w['blad'] ?? null) !== null && $ileSmtp() === 1 && ($w['mailjet'][0]['droga'] ?? '') === 'mailjet', json_encode($w));
    [$w, $b] = $wyslijZ(array_replace($kZ, ['mail_zapas' => false]), 'ord_zapas_wyl', 3);
    $t('zapas wyłączony (bez konfiguracji) → błąd jak dotąd, bez SMTP', ($w['blad'] ?? null) !== null && $ileSmtp() === 1 && $b[0]['mail_wyslany_at'] === null, json_encode($w));
    [$w, $b] = $wyslijZ(array_replace($kZ, ['smtp_test_blad' => 'polaczenie']), 'ord_zapas_smtp_zle', 3);
    $t('zapas: SMTP też nie działa → błąd, bon czeka na cron', str_contains((string)($w['blad'] ?? ''), 'zapas') && $b[0]['mail_wyslany_at'] === null, json_encode($w));
    $ileNiepewny = static fn(): int => substr_count(is_file($katZ . '/bony-powiadomienia.txt') ? (string)file_get_contents($katZ . '/bony-powiadomienia.txt') : '', 'MOŻE nie dotrzeć – zamówienie ord_zapas_po_danych');
    [$w, $b] = $wyslijZ(array_replace($kZ, ['smtp_test_blad' => 'po_danych']), 'ord_zapas_po_danych', 3);
    [$w2] = $wyslijZ(array_replace($kZ, ['smtp_test_blad' => 'po_danych']), 'ord_zapas_po_danych', 3);
    $t('zapas: SMTP zerwany po treści → bez ponownej wysyłki (bez dubli), jedno powiadomienie', array_key_exists('blad', $w) && $w['blad'] === null && $b[0]['mail_wyslany_at'] !== null
        && ($w2['pominieto'] ?? '') === 'nic do wysłania' && $ileNiepewny() === 1, json_encode([$w, $w2, $ileNiepewny(), $b[0]['mail_wyslany_at']]));
    $waw = static fn(int $s): string => (new DateTimeImmutable('@' . (time() + $s)))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i');
    $przedMj = $ileMj();
    [$w, $b] = $wyslijZ(array_replace($kZ, ['mail_zapas_test_do' => $waw(3600)]), 'ord_zapas_test', 0);
    $t('test zapasu (mail_zapas_test_do za 1 h) → od razu SMTP, bez Mailjeta', ($w['mailjet'][0]['droga'] ?? '') === 'smtp-ovh' && !empty($w['mailjet'][0]['test_zapasu'])
        && $ileMj() === $przedMj && $b[0]['mail_wyslany_at'] !== null, json_encode($w));
    $t('test zapasu sam wygasa: termin miniony albo dalej niż 6 h → bez wymuszenia',
        !bon_zapas_wymuszony(array_replace($kZ, ['mail_zapas_test_do' => $waw(-60)]))
        && !bon_zapas_wymuszony(array_replace($kZ, ['mail_zapas_test_do' => $waw(7 * 3600)]))
        && !bon_zapas_wymuszony(array_replace($kZ, ['mail_zapas_test_do' => $waw(3600), 'mail_zapas' => false])));
    $diag = json_encode(bon_diagnostyka($kZ, 1, 1, 1, 1, $pdo), JSON_UNESCAPED_UNICODE);
    $t('log.php: zapas SMTP widoczny (host, port), login i hasło nigdy', str_contains($diag, '"zapas_smtp"') && str_contains($diag, 'ssl0.ovh.net')
        && !str_contains($diag, 'tajne-haslo-smtp-123') && !str_contains($diag, 'powiadomienia@'));
    $t('odpowiedź serwera SMTP w dzienniku bez adresów', bon_smtp_odp_jawna("250 2.0.0 Ok: queued as ABC <jan@example.com>\r\n") === '250 2.0.0 Ok: queued as ABC <***>');
    foreach (['bony-smtp.jsonl', 'bony-mailjet.jsonl', 'bony-powiadomienia.txt'] as $f) @unlink($katZ . '/' . $f);
    foreach (['bony-zdarzenia', 'bony-mail-bledy', 'bony-mail-wysylki'] as $d) { foreach (glob("$katZ/$d/*") ?: [] as $f) @unlink($f); @rmdir("$katZ/$d"); }
    @rmdir($katZ);
    $k = $kOrgZ;

    // kontrola dostarczenia po MessageID (cron) – we własnym katalogu tymczasowym, nigdy w prawdziwym konfig/ (test-ovh.php)
    $kOrg = $k;
    $kat0 = sys_get_temp_dir() . '/bony-kontrola-' . bin2hex(random_bytes(4));
    @mkdir($kat0, 0700, true);
    $k = array_replace($k, ['_katalog' => $kat0]);
    $plikPow = $kat0 . '/bony-powiadomienia.txt';
    $kat = $kat0 . '/bony-mail-wysylki';
    bon_mail_zapisz_wysylke($k, 'ord_kontrola_ok', ['EWA-TEST-0001'], [111]);
    bon_mail_zapisz_wysylke($k, 'ord_kontrola_zle', ['EWA-TEST-0002'], [222]);
    $r = bon_mail_kontrola($k, 0, 600);
    $t('kontrola: świeża wysyłka (< 10 min) jeszcze niesprawdzana', $r['sprawdzono'] === 0, json_encode($r));
    $r = bon_mail_kontrola($k, 0, 0);
    $t('kontrola: status sent → dostarczone', $r['dostarczone'] === 2 && $r['problem'] === 0, json_encode($r));
    $r = bon_mail_kontrola($k, 0, 0);
    $t('kontrola: zamknięta wysyłka nie jest sprawdzana drugi raz', $r['sprawdzono'] === 0, json_encode($r));
    array_map('unlink', glob($kat . '/*.json') ?: []);
    $ilePowDost = static fn(): int => substr_count(is_file($plikPow) ? (string)file_get_contents($plikPow) : '', 'mail NIE dotarł – zamówienie ord_kontrola_blok');
    bon_mail_zapisz_wysylke($k, 'ord_kontrola_blok', ['EWA-TEST-0003'], [333]);
    $kBlok = array_replace($k, ['mailjet_test_status' => 'blocked']);
    $r = bon_mail_kontrola($kBlok, 0, 0);
    bon_mail_kontrola($kBlok, 0, 0);
    $t('kontrola: blocked → problem i jedno powiadomienie gabinetu', $r['problem'] === 1 && $ilePowDost() === 1, json_encode($r) . ' / ' . $ilePowDost());
    array_map('unlink', glob($kat . '/*.json') ?: []);
    bon_mail_zapisz_wysylke($k, 'ord_kontrola_kolejka', ['EWA-TEST-0004'], [444]);
    $kKolejka = array_replace($k, ['mailjet_test_status' => 'queued']);
    $r1 = bon_mail_kontrola($kKolejka, 0, 0);
    $r2 = bon_mail_kontrola($kKolejka, time() + 86400 + 60, 0);
    $niepotw = substr_count(is_file($plikPow) ? (string)file_get_contents($plikPow) : '', 'brak potwierdzenia dostarczenia – zamówienie ord_kontrola_kolejka');
    $t('kontrola: queued czeka, po 24 h jedno powiadomienie „niepotwierdzone”', $r1['czeka'] === 1 && $r2['czeka'] === 1 && $niepotw === 1, json_encode([$r1, $r2, $niepotw]));
    $o = $r1['mailjet'][0]['odpowiedzi'][0] ?? [];
    $t('kontrola: w wyniku surowy status i HTTP Mailjeta', ($o['status'] ?? null) === 'queued' && ($o['http'] ?? 0) === 200 && ($o['id'] ?? 0) === 444, json_encode($r1));
    array_map('unlink', glob($kat . '/*.json') ?: []);
    bon_mail_zapisz_wysylke($k, 'ord_kontrola_404', ['EWA-TEST-0005'], [555]);
    $r = bon_mail_kontrola(array_replace($k, ['mailjet_test_status_http' => 404]), 0, 0);
    $o = $r['mailjet'][0]['odpowiedzi'][0] ?? [];
    $t('kontrola: błąd HTTP zapytania o status → czeka z kodem i opisem błędu', $r['czeka'] === 1 && ($o['http'] ?? 0) === 404 && array_key_exists('status', $o) && $o['status'] === null && str_contains((string)($o['blad'] ?? ''), '404'), json_encode($r));
    array_map('unlink', glob($kat . '/*.json') ?: []);
    $zerwaniaZdarzenia = static function () use ($kat0): array { // najnowsze pierwsze
        $linie = [];
        foreach (glob($kat0 . '/bony-zdarzenia/*.log') ?: [] as $f) $linie = array_merge($linie, file($f, FILE_IGNORE_NEW_LINES) ?: []);
        $z = array_map(static fn($l) => json_decode($l, true), $linie);
        return array_reverse(array_values(array_filter($z, static fn($e) => ($e['typ'] ?? '') === 'mailjet-zerwanie')));
    };
    $przedZ = count($zerwaniaZdarzenia());
    bon_mail_zapisz_wysylke($k, 'ord_kontrola_zerw2', ['EWA-TEST-0006'], [666]);
    $GLOBALS['bon_test_zerwania_status'] = 2;
    $r = bon_mail_kontrola($k, 0, 0);
    $o = $r['mailjet'][0]['odpowiedzi'][0] ?? [];
    $zz = $zerwaniaZdarzenia();
    $t('status: 2 zerwania → 3. próba odpowiada, dostarczone; jedno zdarzenie mailjet-zerwanie bez sondy',
        $r['dostarczone'] === 1 && ($o['proby'] ?? 0) === 3 && count($zz) === $przedZ + 1 && ($zz[0]['operacja'] ?? '') === 'status'
        && ($zz[0]['wszystkie'] ?? true) === false && !isset($zz[0]['sonda']), json_encode([$r, $zz[0] ?? null]));
    array_map('unlink', glob($kat . '/*.json') ?: []);
    $ileBrakOdp = static fn(): int => substr_count(is_file($plikPow) ? (string)file_get_contents($plikPow) : '', 'nie da się sprawdzić dostarczenia – zamówienie ord_kontrola_zerw');
    $ileNiepotw = static fn(): int => substr_count(is_file($plikPow) ? (string)file_get_contents($plikPow) : '', 'brak potwierdzenia dostarczenia – zamówienie ord_kontrola_zerw');
    bon_mail_zapisz_wysylke($k, 'ord_kontrola_zerw', ['EWA-TEST-0007'], [777]);
    $GLOBALS['bon_test_zerwania_status'] = 3;
    $r1 = bon_mail_kontrola($k, time() + 86400 + 60, 0);
    $zz = $zerwaniaZdarzenia();
    $GLOBALS['bon_test_zerwania_status'] = 3;
    $r2 = bon_mail_kontrola($k, time() + 3 * 86400 + 60, 0);
    $GLOBALS['bon_test_zerwania_status'] = 3;
    $r3 = bon_mail_kontrola($k, time() + 3 * 86400 + 3600, 0);
    $GLOBALS['bon_test_zerwania_status'] = 0;
    $t('status: 3 zerwania → zdarzenie z sondą (wszystkie próby)', ($zz[0]['wszystkie'] ?? false) === true && ($zz[0]['proby'] ?? 0) === 3 && isset($zz[0]['sonda']), json_encode($zz[0] ?? null));
    $t('kontrola: zerwania po 24 h → bez „niepotwierdzone”, dalej czeka', $r1['czeka'] === 1 && $ileNiepotw() === 0, json_encode($r1));
    $t('kontrola: zerwania po 72 h → jedno powiadomienie „nie da się sprawdzić”, kontrola trwa', $r2['czeka'] === 1 && $r3['sprawdzono'] === 1 && $ileBrakOdp() === 1, json_encode([$r2, $r3, $ileBrakOdp()]));
    array_map('unlink', glob($kat . '/*.json') ?: []);
    @rmdir($kat);
    @unlink($plikPow);
    foreach (glob($kat0 . '/bony-zdarzenia/*') ?: [] as $f) @unlink($f);
    @rmdir($kat0 . '/bony-zdarzenia');
    @rmdir($kat0);
    $k = $kOrg;
    $t('tryb plik (testy, test-ovh): wysyłka nie trafia do kontroli dostarczenia', !array_diff(glob($kOrg['_katalog'] . '/bony-mail-wysylki/*.json') ?: [], $wysylkiPrzed));

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
    // TYMCZASOWE (Krok 0 /bony/dziekujemy): parametry powrotu z kasy – klucze jawne, dane osobowe ukryte, id zamówienia jako skrót
    $p = bon_powrot_dane(['order_id' => 'ord_TEST123', 'email' => 'jan@example.com', 'customer_name' => 'Jan Kowalski', 'phone' => '+48600100200'], 'x');
    $pj = (string)json_encode($p, JSON_UNESCAPED_UNICODE);
    $t('powrót z kasy: klucze jawne, e-mail/imię/telefon ukryte, skrót id zamówienia', $p['klucze'] === ['order_id', 'email', 'customer_name', 'phone']
        && !str_contains($pj, 'jan@') && !str_contains($pj, 'Kowalski') && !str_contains($pj, '600100200')
        && $p['skroty']['order_id'] === bon_powrot_skrot('ord_TEST123'), $pj);
    // skrót z długim ciągiem cyfr (3f956e5626535769) nie może zniknąć jako „telefon” przy odczycie w log.php
    $sk = bon_powrot_skrot('sprawdzenie-wrzuty');
    $t('powrót z kasy: skrót przeżywa ukrywanie w podglądzie logu', $sk === '3f95-6e56-2653-5769' && bon_log_ukryj(['skroty' => ['x' => $sk]])['skroty']['x'] === $sk, $sk);
    $t('podgląd: kod bonu zamaskowany', bon_kod_maska('EWA-7KQ4-M9TX') === 'EWA-****-M9TX');
    $mj = bon_log_ukryj(['typ' => 'mail', 'buyer_email' => 'jan@example.com', 'mailjet' => [['http' => 200, 'status' => 'success', 'id' => [1152921520000000001], 'proby' => 1]]]);
    $t('podgląd: odpowiedź Mailjeta (HTTP, Status, MessageID, próby) jawna, e-mail nadal ukryty',
        ($mj['mailjet'][0]['http'] ?? null) === 200 && ($mj['mailjet'][0]['status'] ?? null) === 'success'
        && ($mj['mailjet'][0]['id'][0] ?? null) === 1152921520000000001 && ($mj['mailjet'][0]['proby'] ?? null) === 1 && $mj['buyer_email'] === '***', json_encode($mj));
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
    return array_merge($wyniki, bon_testy_panel($k, $pdo), bon_testy_sync($k, $pdo), bon_testy_krok1($k, $pdo), bon_testy_krok4($k, $pdo), bon_testy_obsluga($k, $pdo));
}
