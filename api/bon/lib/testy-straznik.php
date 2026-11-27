<?php
// Testy #259: strażnik sprzedaży (lib/straznik.php). Własna baza SQLite w pamięci (tylko kolumny, których strażnik używa) i własny
// katalog na znacznik crona – bony i znaczniki innych testów nie wpływają na wynik. API Easytools podstawione (bon_test_api).

declare(strict_types=1);
require_once __DIR__ . '/straznik.php';
require_once __DIR__ . '/testy-sync.php'; // bon_test_api, bon_test_uuid

function bon_testy_straznik(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $kat = sys_get_temp_dir() . '/bony-testy-straznik-' . bin2hex(random_bytes(4));
    @mkdir($kat, 0700, true);
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('CREATE TABLE vouchers (id INTEGER PRIMARY KEY, easytools_order_id TEXT, status TEXT, mail_wyslany_at TEXT, created_at TEXT)');
    $teraz = bon_teraz();
    $produkt = 'e12be4ae-2d3a-47f3-b220-437fc5b46277';
    $zamowienia = $transakcje = $wywolania = [];
    $dodaj = static function (int $minutTemu, string $produktZam = '') use (&$zamowienia, $produkt, $teraz): string {
        $id = bon_test_uuid();
        $zamowienia[$id] = ['id' => $id, 'status' => 'completed', 'amount' => 20000, 'currency' => 'pln', 'quantity' => 1,
            'product_name' => 'Bon', 'customer_email' => 'straznik@example.com', '_produkt' => $produktZam ?: $produkt,
            'created_at' => $teraz->modify("-$minutTemu minutes")->format('c')];
        return $id;
    };
    $bon = static function (string $zam, string $status = 'valid', ?string $mail = 'wyslany', int $minutTemu = 60) use ($db, $teraz): void {
        $db->prepare('INSERT INTO vouchers (easytools_order_id, status, mail_wyslany_at, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$zam, $status, $mail === null ? null : $teraz->format('Y-m-d H:i:s'), $teraz->modify("-$minutTemu minutes")->format('Y-m-d H:i:s')]);
    };
    $ks = array_replace($k, ['_katalog' => $kat, 'tabele_przedrostek' => '', 'straznik' => true, 'sync_tryb' => 'bon',
        'easytools_api_key' => 'klucz-testowy', 'easytools_produkt_uuid' => $produkt,
        'sync_od' => $teraz->modify('-1 day')->format('Y-m-d H:i:s'), 'easytools_http' => bon_test_api($zamowienia, $transakcje, $wywolania)]);
    $s = static fn(array $kk = []) => bon_straznik(array_replace($ks, $kk), $db, $teraz);
    $znacznik = static fn(int $minutTemu) => file_put_contents($kat . BON_STRAZNIK_CRON_PLIK, (string)($teraz->getTimestamp() - $minutTemu * 60));
    $powod = static fn(array $w, string $poczatek): bool => (bool)array_filter($w['powody'], static fn($p) => str_starts_with($p, $poczatek));

    // wyłącznik
    $w = $s(['straznik' => false]);
    $t('#259 strażnik: wyłącznik → wlaczony false, bez alarmu', $w === ['wlaczony' => false, 'alarm' => false, 'powody' => []], json_encode($w));

    // znacznik crona
    $w = $s();
    $t('#259 strażnik: brak znacznika crona → alarm „cron”', $w['alarm'] && $powod($w, 'cron:') && $w['cron_minut'] === null, json_encode($w, JSON_UNESCAPED_UNICODE));
    $znacznik(76);
    $w = $s();
    $t('#259 strażnik: cron sprzed 76 min → alarm', $w['alarm'] && $powod($w, 'cron:') && $w['cron_minut'] === 76, json_encode($w, JSON_UNESCAPED_UNICODE));
    $znacznik(20);
    $w = $s();
    $t('#259 strażnik: cron sprzed 20 min, brak zamówień → w porządku', !$w['alarm'] && $w['powody'] === [] && $w['zamowien'] === 0 && $w['bez_bonu'] === 0,
        json_encode($w, JSON_UNESCAPED_UNICODE));
    bon_straznik_znacznik($ks);
    $t('#259 strażnik: bon_straznik_znacznik zapisuje bieżącą chwilę', abs((int)file_get_contents($kat . BON_STRAZNIK_CRON_PLIK) - time()) <= 2);

    // zamówienia
    $zBonem = $dodaj(120);
    $bon($zBonem);
    $swieze = $dodaj(29);          // synchronizacja ma jeszcze czas
    $przedStartem = $dodaj(60 * 30); // 30 h temu – przed sync_od (24 h)
    $innyProdukt = $dodaj(90, bon_test_uuid());
    $w = $s();
    $t('#259 strażnik: zamówienie z bonem, świeże (29 min), sprzed sync_od i innego produktu → w porządku', !$w['alarm'] && $w['zamowien'] === 1 && $w['bez_bonu'] === 0,
        json_encode($w, JSON_UNESCAPED_UNICODE));
    $bezBonu = $dodaj(31);
    $w = $s();
    $t('#259 strażnik: zapłacone 31 min temu bez bonu → alarm „sprzedaż”, skrócone id', $w['alarm'] && $powod($w, 'sprzedaż: 1 ') && $w['bez_bonu'] === 1
        && $w['bez_bonu_id'] === [substr($bezBonu, 0, 8)], json_encode($w, JSON_UNESCAPED_UNICODE));
    $json = json_encode($w, JSON_UNESCAPED_UNICODE);
    $t('#259 strażnik: w wyniku bez e-maili, kwot i pełnych id', !str_contains($json, '@') && !str_contains($json, '200') && !str_contains($json, $bezBonu), $json);
    $bon($bezBonu, 'held');
    $t('#259 strażnik: bon wstrzymany też jest bonem → w porządku', !$s()['alarm']);
    // zamówienie pominięte przez synchronizację (np. brak e-maila) – nadal zapłacone bez bonu
    $dodaj(45);
    $t('#259 strażnik: drugie zamówienie bez bonu (45 min) → alarm', $s()['bez_bonu'] === 1 && $s()['alarm']);
    $db->exec('DELETE FROM vouchers');
    $zamowienia = [];
    $wywolania = [];

    // bony bez maila
    $z = $dodaj(50);
    $bon($z, 'valid', null, 50);
    $w = $s();
    $t('#259 strażnik: bon ważny bez maila od 50 min → alarm „mail”', $w['alarm'] && $powod($w, 'mail: 1 ') && $w['bez_maila'] === 1, json_encode($w, JSON_UNESCAPED_UNICODE));
    $db->exec("UPDATE vouchers SET created_at = '" . $teraz->modify('-10 minutes')->format('Y-m-d H:i:s') . "'");
    $zamowienia[$z]['created_at'] = $teraz->modify('-10 minutes')->format('c');
    $t('#259 strażnik: bon bez maila od 10 min → jeszcze bez alarmu', !$s()['alarm'], json_encode($s(), JSON_UNESCAPED_UNICODE));

    // konfiguracja i API
    $w = $s(['sync_tryb' => 'raport']);
    $t('#259 strażnik: sync_tryb ≠ bon → alarm „konfiguracja”', $w['alarm'] && $powod($w, 'konfiguracja: sync_tryb = raport'), json_encode($w, JSON_UNESCAPED_UNICODE));
    $w = $s(['sync_od' => '']);
    $t('#259 strażnik: bez sync_od → alarm „konfiguracja”, API nie pytane', $w['alarm'] && $powod($w, 'konfiguracja: brak') && $w['zamowien'] === null,
        json_encode($w, JSON_UNESCAPED_UNICODE));
    $w = $s(['easytools_http' => static fn() => [500, ['message' => 'Server Error dla kupujacy@example.com']]]);
    $t('#259 strażnik: błąd API → alarm „API”, api_blad, bez e-maila w treści', $w['alarm'] && $w['api_blad'] && $powod($w, 'API Easytools')
        && !str_contains(json_encode($w, JSON_UNESCAPED_UNICODE), 'kupujacy@'), json_encode($w, JSON_UNESCAPED_UNICODE));
    $t('#259 strażnik: token = HMAC z log_token, bez log_token → brak tokenu',
        bon_straznik_token(['log_token' => str_repeat('a', 32)]) === hash_hmac('sha256', 'straznik', str_repeat('a', 32))
        && bon_straznik_token(['log_token' => 'krotki']) === null && bon_straznik_token([]) === null);
    $t('#259 strażnik: ustawienie „straznik” w repo włączone i jawne', (require __DIR__ . '/ustawienia.php')['straznik'] === true && in_array('straznik', BON_USTAWIENIA_JAWNE, true));

    // #273: nieudany alarm do gabinetu (zdarzenie powiadomienie-blad) w ostatnich 24 h → alarm „powiadomienie”
    @mkdir($kat . '/bony-zdarzenia', 0700, true);
    $zdarzenie = static function (DateTimeImmutable $czas, string $typ = 'powiadomienie-blad') use ($kat): void {
        file_put_contents($kat . '/bony-zdarzenia/' . $czas->format('Y-m-d') . '.log',
            json_encode(['czas' => $czas->format('c'), 'typ' => $typ, 'temat' => 'Bon EWA-****-AB12', 'odbiorcow' => 1]) . "\n", FILE_APPEND);
    };
    $zdarzenie($teraz->modify('-25 hours'));
    $zdarzenie($teraz->modify('-2 hours'), 'powiadomienie');
    $w = $s();
    $t('#273 strażnik: nieudany alarm sprzed 25 h i udany sprzed 2 h → bez alarmu', !$w['alarm'] && $w['powiadomienia_blad'] === 0, json_encode($w, JSON_UNESCAPED_UNICODE));
    $zdarzenie($teraz->modify('-1 hour'));
    $w = $s();
    $t('#273 strażnik: nieudany alarm do gabinetu sprzed 1 h → alarm „powiadomienie: 1”', $w['alarm'] && $powod($w, 'powiadomienie: 1 ') && $w['powiadomienia_blad'] === 1,
        json_encode($w, JSON_UNESCAPED_UNICODE));

    // #264: sprzątanie dzienników > 90 dni (webhooki, zdarzenia, znaczniki błędów wysyłki, pliki trybu testowego)
    $dzien = static fn(int $dniTemu) => $teraz->modify("-$dniTemu days")->format('Y-m-d');
    @mkdir($kat . '/bony-webhook-log', 0700, true);
    @mkdir($kat . '/bony-mail-bledy', 0700, true);
    foreach ([100, 91, 89, 1] as $d) file_put_contents($kat . '/bony-webhook-log/' . $dzien($d) . '.log', "{}\n");
    file_put_contents($kat . '/bony-zdarzenia/' . $dzien(120) . '.log', "{}\n");
    file_put_contents($kat . '/bony-mail-bledy/stary.txt', '1');
    touch($kat . '/bony-mail-bledy/stary.txt', time() - 100 * 86400);
    file_put_contents($kat . '/bony-mail-bledy/nowy.txt', '1');
    file_put_contents($kat . '/bony-mailjet.jsonl', "{}\n");
    touch($kat . '/bony-mailjet.jsonl', time() - 95 * 86400);
    $u = bon_sprzatanie_dziennikow($ks);
    $zostaly = array_map('basename', glob($kat . '/bony-webhook-log/*.log') ?: []);
    sort($zostaly);
    $t('#264 sprzątanie: usuwa dzienniki > 90 dni (webhooki 2, zdarzenia 1, znacznik 1, plik testowy 1), zostawia nowsze',
        $u === ['bony-webhook-log' => 2, 'bony-zdarzenia' => 1, 'bony-mail-bledy' => 1, 'bony-mailjet.jsonl' => 1]
        && $zostaly === [$dzien(89) . '.log', $dzien(1) . '.log'] && is_file($kat . '/bony-mail-bledy/nowy.txt')
        && !is_file($kat . '/bony-mail-bledy/stary.txt') && !is_file($kat . '/bony-mailjet.jsonl') && is_file($kat . '/bony-zdarzenia/' . $teraz->format('Y-m-d') . '.log'),
        json_encode([$u, $zostaly]));
    $t('#264 sprzątanie: drugi przebieg nic nie usuwa; bez katalogu konfigu – pusto', bon_sprzatanie_dziennikow($ks) === [] && bon_sprzatanie_dziennikow(['_katalog' => '']) === []);

    foreach (['bony-zdarzenia', 'bony-webhook-log', 'bony-mail-bledy'] as $pod) {
        foreach (glob($kat . '/' . $pod . '/*') ?: [] as $f) @unlink($f);
        @rmdir($kat . '/' . $pod);
    }
    foreach (glob($kat . '/*') ?: [] as $f) @unlink($f);
    @rmdir($kat);
    return $wyniki;
}
