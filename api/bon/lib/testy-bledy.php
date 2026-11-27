<?php
// Testy #256: błędy krytyczne PHP bonów – zapis do dziennika zdarzeń (blad_php), podgląd w log.php (bledy_krytyczne),
// alert z limitem (webhook, cron). Sam zapis: bon_blad_krytyczny() w lib/bon.php (wywołuje go funkcja zamykająca skrypt).

declare(strict_types=1);
require_once __DIR__ . '/diagnostyka.php';

function bon_testy_bledy(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    // osobny katalog, żeby dziennik i powiadomienia innych testów nie mieszały się z tymi
    $kat = sys_get_temp_dir() . '/bony-testy-bledy-' . bin2hex(random_bytes(4));
    @mkdir($kat, 0700, true);
    $kb = array_replace($k, ['_katalog' => $kat, 'mail_tryb' => 'plik', 'mail_powiadomienie' => ['alerty@example.com']]);
    $zdarzenia = static function () use ($kat): array {
        $w = [];
        foreach (glob($kat . '/bony-zdarzenia/*.log') ?: [] as $p) foreach (file($p, FILE_IGNORE_NEW_LINES) ?: [] as $l) $w[] = json_decode($l, true);
        return array_values(array_filter($w, static fn($z) => ($z['typ'] ?? '') === 'blad_php'));
    };
    $maile = static fn(): int => substr_count(is_file($kat . '/bony-powiadomienia.txt') ? (string)file_get_contents($kat . '/bony-powiadomienia.txt') : '', '== Bony: błąd krytyczny PHP');
    $blad = ['type' => E_ERROR, 'file' => '/home/x/astro-www/api/bon/lib/panel.php', 'line' => 123,
        'message' => "Uncaught PDOException: SQLSTATE[HY000] [1045] Access denied for user 'ewa_db'@'10.0.0.1' (anna.kowalska@example.com) in /home/x/astro-www/api/bon/lib/panel.php:123"];

    // 1. które typy są krytyczne
    $t('#256: E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR – krytyczne; E_WARNING, E_NOTICE, E_DEPRECATED – nie',
        bon_blad_krytyczny_typ(E_ERROR) && bon_blad_krytyczny_typ(E_PARSE) && bon_blad_krytyczny_typ(E_COMPILE_ERROR) && bon_blad_krytyczny_typ(E_CORE_ERROR)
        && bon_blad_krytyczny_typ(E_USER_ERROR) && !bon_blad_krytyczny_typ(E_WARNING) && !bon_blad_krytyczny_typ(E_NOTICE) && !bon_blad_krytyczny_typ(E_DEPRECATED));

    // 2. zapis z panelu: zdarzenie blad_php, ścieżka względna, bez e-maila i 'użytkownik'@'host', bez parametrów adresu; bez maila
    bon_blad_krytyczny($blad, $kb, 'POST', '/api/bon/szukaj.php?q=anna.kowalska@example.com');
    $z = $zdarzenia();
    $json = json_encode($z, JSON_UNESCAPED_UNICODE);
    $t('#256: błąd z panelu → zdarzenie blad_php (plik api/bon/lib/panel.php, linia, adres bez parametrów, metoda)',
        count($z) === 1 && ($z[0]['plik'] ?? '') === 'api/bon/lib/panel.php' && ($z[0]['linia'] ?? 0) === 123
        && ($z[0]['adres'] ?? '') === '/api/bon/szukaj.php' && ($z[0]['metoda'] ?? '') === 'POST', $json);
    $t('#256: w zdarzeniu bez e-maila, bez \'użytkownik\'@\'host\' i bez ścieżki katalogu domowego',
        !str_contains($json, 'kowalska') && !str_contains($json, 'ewa_db') && !str_contains($json, '/home/x'), $json);
    $t('#256: błąd z panelu → bez maila (alert tylko dla webhooka i crona)', $maile() === 0);

    // 3. webhook → jeden alert; drugi błąd w ciągu godziny → bez drugiego maila, zdarzenie jest
    bon_blad_krytyczny($blad, $kb, 'POST', '/api/bon/webhook.php');
    bon_blad_krytyczny($blad, $kb, 'POST', '/api/bon/webhook.php');
    $t('#256: webhook → jeden alert na godzinę, każde wystąpienie w dzienniku', $maile() === 1 && count($zdarzenia()) === 3, (string)$maile());
    $tresc = (string)@file_get_contents($kat . '/bony-powiadomienia.txt');
    $t('#256: treść alertu bez e-maila i danych bazy, z plikiem i linią', str_contains($tresc, 'api/bon/lib/panel.php:123')
        && !str_contains($tresc, 'kowalska') && !str_contains($tresc, 'ewa_db'), $tresc);

    // 4. po godzinie (znacznik starszy niż 3600 s) – znowu alert; cron (CLI) też alarmuje
    @touch($kat . '/bony-blad-php-alert.txt', time() - 3700);
    bon_blad_krytyczny($blad, $kb, 'CLI', 'lib/cron.php');
    $t('#256: cron po godzinie od ostatniego alertu → nowy alert', $maile() === 2, (string)$maile());

    // 5. log.php: pole bledy_krytyczne – najnowsze pierwsze, najwyżej 10
    $l = bon_log_bledy_krytyczne($kb);
    $t('#256: log.php bledy_krytyczne – 4 wpisy, najnowszy z crona, bez e-maili', count($l) === 4 && ($l[0]['adres'] ?? '') === 'lib/cron.php'
        && !str_contains(json_encode($l), 'kowalska'), json_encode($l));

    // 5b. ścieżka spoza api/bon (np. konfig w katalogu domowym OVH) – bez loginu konta
    $t('#256: ścieżka katalogu domowego OVH skrócona do ~/', bon_blad_tekst("require(): Failed opening '/homez.123/ewagab/konfig/bony.php'")
        === "require(): Failed opening '~/konfig/bony.php'", bon_blad_tekst("require(): Failed opening '/homez.123/ewagab/konfig/bony.php'"));

    // #265: test_token w konfigu (test-ovh.php działa) – stan w log.php, ostrzeżenie po 24 h od pierwszego zauważenia
    $kt = array_replace($kb, ['test_token' => str_repeat('t', 32)]);
    $s1 = bon_test_token_stan($kt);
    $t('#265: test_token ustawiony → ustawiony, od teraz, bez ostrzeżenia; token nie wypisany', $s1['ustawiony'] === true && $s1['ostrzezenie'] === null
        && !str_contains(json_encode($s1), str_repeat('t', 32)) && is_file($kat . '/bony-test-token-od.txt'), json_encode($s1));
    @touch($kat . '/bony-test-token-od.txt', time() - 25 * 3600);
    $s2 = bon_test_token_stan($kt);
    $t('#265: po 25 h → ostrzeżenie „usuń test_token”', $s2['ustawiony'] === true && str_contains((string)$s2['ostrzezenie'], 'usuń test_token'), json_encode($s2));
    $s3 = bon_test_token_stan($kb);
    $t('#265: bez test_token → nieustawiony, znacznik usunięty (następne ustawienie liczy od nowa)', $s3['ustawiony'] === false && $s3['ostrzezenie'] === null
        && !is_file($kat . '/bony-test-token-od.txt'), json_encode($s3));
    $t('#265: krótki test_token (test-ovh.php go nie przyjmuje) – nieustawiony', bon_test_token_stan(array_replace($kb, ['test_token' => 'krotki']))['ustawiony'] === false);

    // 6. brak konfiguracji (błąd przy wczytywaniu konfigu) – bez wyjątku, nic nie zapisuje
    $ok = true;
    try { bon_blad_krytyczny($blad, null, 'GET', '/api/bon/verify.php'); } catch (Throwable) { $ok = false; }
    $t('#256: bez konfiguracji – bez wyjątku', $ok);

    foreach (glob($kat . '/bony-zdarzenia/*') ?: [] as $f) @unlink($f);
    @rmdir($kat . '/bony-zdarzenia');
    foreach (glob($kat . '/*') ?: [] as $f) @unlink($f);
    @rmdir($kat);
    return $wyniki;
}
