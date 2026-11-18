<?php
// Zgłoszenie na staż podologiczny (formularz na /szkolenia-pabianice/staz-podologiczny) – Bartek 2026-11-18.
// Działa TYLKO na produkcji (OVH, PHP). Netlify (wersja testowa) PHP nie uruchamia.
//
// 1. zapis do MySQL na hostingu OVH (osobna baza, tabela zgloszenia_staz – plan/staz-zgloszenia.sql),
// 2. mail o zgłoszeniu do adresów z konfiguracji,
// 3. gdy baza nie odpowiada: zapis awaryjny do CSV obok konfiguracji (zgłoszenie nie przepada),
// 4. przekierowanie 303 na /szkolenia-pabianice/dziekujemy (działa bez JavaScriptu).
//
// Hasła i adresy NIE są w repo (kod trafia do publicznego ewa-gabinet-www): plik konfiguracji leży POZA katalogiem
// strony – na OVH obok astro-www: <katalog domowy>/konfig/staz.php (wzór: plan/konfig-staz.przyklad.php).
// Git OVH zarządza tylko astro-www, więc plik wgrany raz po FTP zostaje przy każdej wrzucie.

declare(strict_types=1);

const STRONA_DZIEKUJEMY = '/szkolenia-pabianice/dziekujemy';
const STRONA_FORMULARZA = '/szkolenia-pabianice/staz-podologiczny#zgloszenie';

$konfigPlik = dirname(__DIR__, 2) . '/konfig/staz.php';

// strona błędu bez Astro (PHP nie zna kontakt.yaml): numer = telefon „podologia” z src/data/kontakt.yaml – przy zmianie poprawić też tu
function blad(int $kod, string $tekst): void {
    http_response_code($kod);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $t = htmlspecialchars($tekst, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Zgłoszenie na staż</title><style>body{font:18px/1.6 sans-serif;max-width:640px;margin:48px auto;padding:0 20px;color:#1f3b36}'
        . 'a{color:inherit}</style></head><body><h1>Nie udało się wysłać zgłoszenia</h1><p>' . $t . '</p>'
        . '<p><a href="' . STRONA_FORMULARZA . '">Wróć do formularza</a> albo zadzwoń: <a href="tel:+48603129364">603 129 364</a>.</p></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . STRONA_FORMULARZA, true, 303);
    exit;
}

// pułapka na boty: pole ukryte przed ludźmi; wypełnione = udajemy sukces, nic nie zapisujemy
if (trim((string)($_POST['strona_www'] ?? '')) !== '') {
    header('Location: ' . STRONA_DZIEKUJEMY, true, 303);
    exit;
}

// --- pola (nazwy jak w FormularzStaz.astro i src/data/staz.ts → opcje) ---
function pole(string $nazwa, int $max): string {
    $v = trim((string)($_POST[$nazwa] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max, 'UTF-8');
}
function wybor(string $nazwa, array $dozwolone): string {
    $v = (string)($_POST[$nazwa] ?? '');
    return in_array($v, $dozwolone, true) ? $v : '';
}

$d = [
    'imie' => pole('imie', 80),
    'telefon' => pole('telefon', 30),
    'email' => pole('email', 120),
    'miejscowosc' => pole('miejscowosc', 80),
    'kurs' => wybor('kurs', ['tak', 'w-trakcie', 'nie']),
    'kurs_opis' => pole('kurs_opis', 300),
    'zajecie' => wybor('zajecie', ['kosmetyczka', 'gabinet-podologiczny', 'pedicure', 'zmiana-zawodu', 'inne']),
    'zabiegi' => wybor('zabiegi', ['tak', 'czasem', 'nie']),
    'dofinansowanie' => wybor('dofinansowanie', ['kfs', 'bur-region', 'sama', 'nie-wiem']),
    'termin' => wybor('termin', ['2027-01', 'kolejny']),
    'wiadomosc' => pole('wiadomosc', 1000),
    'zgoda_kontakt' => isset($_POST['zgoda_kontakt']),
    'zgoda_terminy' => isset($_POST['zgoda_terminy']),
];

$braki = [];
if ($d['imie'] === '') $braki[] = 'imię';
if (!preg_match('/^[0-9 +()-]{9,30}$/', $d['telefon'])) $braki[] = 'numer telefonu';
if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $braki[] = 'adres e-mail';
if ($d['kurs'] === '') $braki[] = 'odpowiedź o kursie podologicznym';
if (!$d['zgoda_kontakt']) $braki[] = 'zgodę na kontakt w sprawie zgłoszenia';
if ($braki) blad(422, 'Uzupełnij: ' . implode(', ', $braki) . '.');

if (!is_file($konfigPlik)) blad(500, 'Formularz nie jest jeszcze skonfigurowany.');
$k = require $konfigPlik;

// --- 1. baza ---
$zapisanoWBazie = false;
// pusty db_dsn = bez bazy (Bartek 2026-11-18: start na mailu + CSV, baza dopięta później) – bez czekania na nieudane połączenie
if (!empty($k['db_dsn'])) try {
    $pdo = new PDO($k['db_dsn'], $k['db_uzytkownik'], $k['db_haslo'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $sql = 'INSERT INTO zgloszenia_staz (imie, telefon, email, miejscowosc, kurs, kurs_opis, zajecie, zabiegi, dofinansowanie, termin, wiadomosc, zgoda_kontakt, zgoda_terminy, zrodlo)
            VALUES (:imie, :telefon, :email, :miejscowosc, :kurs, :kurs_opis, :zajecie, :zabiegi, :dofinansowanie, :termin, :wiadomosc, :zgoda_kontakt, :zgoda_terminy, :zrodlo)';
    $st = $pdo->prepare($sql);
    foreach ($d as $pole => $wartosc) {
        // zgody jako 0/1 (TINYINT w MySQL)
        is_bool($wartosc) ? $st->bindValue(':' . $pole, (int)$wartosc, PDO::PARAM_INT) : $st->bindValue(':' . $pole, $wartosc, PDO::PARAM_STR);
    }
    $st->bindValue(':zrodlo', 'ewa-gabinet.pl/staz');
    $st->execute();
    $zapisanoWBazie = true;
} catch (Throwable $e) {
    error_log('zgloszenie-staz: baza niedostępna – ' . $e->getMessage());
}

// --- 3. zapis awaryjny (CSV obok konfiguracji, poza katalogiem strony) ---
$zapisanoWCsv = false;
if (!$zapisanoWBazie) {
    $csv = dirname($konfigPlik) . '/zgloszenia-staz-awaryjne.csv';
    $nowy = !is_file($csv);
    $f = @fopen($csv, 'ab');
    if ($f) {
        // ochrona przed formułami w Excelu: wartość zaczynająca się od = + - @ dostaje apostrof
        $bezpieczne = array_map(static function ($v) {
            $v = is_bool($v) ? ($v ? 'tak' : 'nie') : (string)$v;
            return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
        }, $d);
        if ($nowy) fputcsv($f, array_merge(['data'], array_keys($d)), ';');
        fputcsv($f, array_merge([date('c')], array_values($bezpieczne)), ';');
        $zapisanoWCsv = fclose($f);
    }
}

// --- 2. mail ---
$etykiety = [
    'kurs' => ['tak' => 'ukończyła kurs', 'w-trakcie' => 'w trakcie kursu/studiów', 'nie' => 'bez kursu'],
    'dofinansowanie' => ['kfs' => 'KFS', 'bur-region' => 'projekt regionalny BUR', 'sama' => 'płaci sama', 'nie-wiem' => 'nie wie'],
];
$linie = [
    'Nowe zgłoszenie na staż podologiczny' . ($zapisanoWBazie ? '' : (empty($k['db_dsn']) ? ' (zapis w CSV – baza jeszcze niepodłączona)' : ' (UWAGA: baza nie odpowiedziała – zapis w CSV awaryjnym)')),
    '',
    'Imię: ' . $d['imie'],
    'Telefon: ' . $d['telefon'],
    'E-mail: ' . $d['email'],
    'Miejscowość: ' . $d['miejscowosc'],
    'Kurs podologiczny: ' . ($etykiety['kurs'][$d['kurs']] ?? '') . ($d['kurs_opis'] !== '' ? ' – ' . $d['kurs_opis'] : ''),
    'Zajęcie: ' . $d['zajecie'],
    'Zabiegi na stopach: ' . $d['zabiegi'],
    'Dofinansowanie: ' . ($etykiety['dofinansowanie'][$d['dofinansowanie']] ?? ''),
    'Termin: ' . $d['termin'],
    'Wiadomość: ' . $d['wiadomosc'],
    'Zgoda na informacje o kolejnych terminach: ' . ($d['zgoda_terminy'] ? 'tak' : 'nie'),
];
$temat = '=?UTF-8?B?' . base64_encode('Staż podologiczny – zgłoszenie: ' . $d['imie']) . '?=';
$naglowki = [
    'From: ' . $k['mail_nadawca'],
    'Reply-To: ' . str_replace(["\r", "\n"], '', $d['email']),
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];
$wyslano = @mail(implode(', ', $k['mail_do']), $temat, implode("\n", $linie), implode("\r\n", $naglowki));
if (!$wyslano) error_log('zgloszenie-staz: mail() nie wysłał powiadomienia');

// nigdzie nie zapisane i nikt nie dostał maila – lepiej powiedzieć wprost niż udawać sukces
if (!$zapisanoWBazie && !$zapisanoWCsv && !$wyslano) blad(500, 'Wystąpił błąd serwera.');

header('Location: ' . STRONA_DZIEKUJEMY, true, 303);
