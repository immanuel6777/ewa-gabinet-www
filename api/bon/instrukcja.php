<?php
// Instrukcja dla gabinetu (PDF) – tylko po zalogowaniu do panelu, każdy poziom: Bon, Gabinet, Admin (wpisy czatu 2026-11-26 11:10 i 12:36).
//   GET /api/bon/instrukcja.php z ciasteczkiem sesji panelu ALBO ?t=<bilet z sesja.php> → PDF inline; bez obu (albo przed
//   ustawieniem własnego hasła) → 401. Bilet: aplikacja z ekranu iPhone'a otwiera ten adres w widoku Safari bez jej ciasteczek.
// Plik: konfig/instrukcja-gabinet.pdf na OVH (obok bony.php, poza astro-www), wgrywa Bartek po FTP (decyzja 26.11) – NIE w repo i NIE
// we wrzucie: wrzuta kopiuje stronę do PUBLICZNEGO repo ewa-gabinet-www, skąd PDF dałoby się pobrać bez logowania.
// Nowa wersja = nowy plik po FTP, bez wrzuty. log.php → instrukcja_pdf pokazuje, czy plik jest (rozmiar, data).
// Stary adres /panel-bonow/instrukcja.pdf nie istnieje (404).

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';

[$k, $pdo, $token] = bon_http_start(['GET']);
try {
    $plik = bon_instrukcja_plik($k);
    $sesja = bon_panel_sesja($k, $pdo, $token);
    if ($sesja && !empty($sesja['zmiana_hasla'])) $sesja = null; // wpis 13:20: najpierw własne hasło
    $kto = $sesja ? (string)$sesja['login'] : bon_instrukcja_bilet_login($k, $pdo, (string)($_GET['t'] ?? ''));
    if ($kto === null) {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        echo '<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Instrukcja – zaloguj się</title>'
            . '<p style="font:18px/1.5 sans-serif;margin:2em">Instrukcja jest dostępna po zalogowaniu. '
            . '<a href="/panel-bonow">Zaloguj się w panelu bonów</a> i otwórz ją z menu ☰.</p></html>';
        exit;
    }
    if ($plik === null) {
        bon_zdarzenie($k, 'blad', ['plik' => 'instrukcja', 'etap' => 'brak pliku']);
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Instrukcja jest chwilowo niedostępna. Daj znać Bartkowi.';
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="instrukcja-gabinet.pdf"');
    header('Content-Length: ' . filesize($plik));
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    readfile($plik);
} catch (Throwable $e) {
    error_log('bony/instrukcja: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'instrukcja'] + bon_opis_bledu($e));
    if (!headers_sent()) bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
