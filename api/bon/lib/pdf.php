<?php
// PDF bonu (backend bonów, etap 2: 2026-11-21). Jedna strona A4: przód i tył karty obok siebie (do wydruku i wycięcia),
// pod spodem dane bonu i zasady w skrócie. Tła kart = zrzuty KartaBonu.astro (scripts/bon-pdf-tla.mjs → lib/grafika/),
// pozycje pól z lib/grafika/uklad.json. PDF powstaje w pamięci i nie jest nigdzie zapisywany (Dla/Od to dane osobowe).
// TCPDF 6.11.4 (LGPL, lib/tcpdf/, bez Composera), czcionka Lato (OFL) z @fontsource przerobiona na pliki TCPDF.

declare(strict_types=1);

const BON_PDF_ADRES_SPRAWDZ = 'https://www.ewa-gabinet.pl/bon?nr='; // QR na bonie – strona sprawdzania salda (etap 4, przed LIVE)
const BON_REGULAMIN = 'https://www.ewa-gabinet.pl/regulamin#bony'; // pełne zasady bonu (§ 14) – w PDF i w mailu (Bartek 2026-11-22)
const BON_PDF_INK = [23, 80, 75];        // --c-ink #17504B
const BON_PDF_MUTED = [62, 104, 99];     // --c-ink-muted #3E6863

function bon_pdf_tcpdf(): void {
    if (class_exists('TCPDF', false)) return;
    $dir = __DIR__ . '/tcpdf/';
    if (!defined('K_TCPDF_EXTERNAL_CONFIG')) define('K_TCPDF_EXTERNAL_CONFIG', true);
    if (!defined('K_PATH_MAIN')) define('K_PATH_MAIN', $dir);
    if (!defined('K_PATH_FONTS')) define('K_PATH_FONTS', $dir . 'fonts/');
    if (!defined('K_PATH_CACHE')) define('K_PATH_CACHE', rtrim(sys_get_temp_dir(), '/\\') . '/');
    if (!defined('K_PATH_IMAGES')) define('K_PATH_IMAGES', __DIR__ . '/grafika/');
    if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
    if (!defined('K_TCPDF_CALLS_IN_HTML')) define('K_TCPDF_CALLS_IN_HTML', false);
    require_once $dir . 'tcpdf.php';
    require_once __DIR__ . '/pdf-klasa.php';
}

// kolor wstążki z wartości bonu (jak grafiki sklepu: 200 czerwona, 300 złota, 500 zielona)
function bon_pdf_prog(int $grosze): string {
    if ($grosze >= 50000) return 'zielen';
    if ($grosze >= 30000) return 'zloty';
    return 'czerwony';
}

// 25000 → „250 zł”, 23750 → „237,50 zł”, 120000 → „1 200 zł” (twarda spacja, żeby kwota się nie łamała)
function bon_kwota_tekst(int $grosze): string {
    $zl = number_format(intdiv($grosze, 100), 0, ',', "\u{00A0}");
    $gr = $grosze % 100;
    return $zl . ($gr ? ',' . str_pad((string)$gr, 2, '0', STR_PAD_LEFT) : '') . "\u{00A0}zł";
}

function bon_data_tekst(string $data): string {
    return substr($data, 8, 2) . '.' . substr($data, 5, 2) . '.' . substr($data, 0, 4);
}

// 1 miesiąc, 2–4 miesiące, 5+ miesięcy (12–14 → miesięcy) – tekst z konfigu 'waznosc_miesiecy', żeby zdanie zgadzało się z datą na bonie
function bon_miesiace_tekst(int $n): string {
    if ($n === 1) return '1 miesiąc';
    $r10 = $n % 10;
    $r100 = $n % 100;
    return $n . ($r10 >= 2 && $r10 <= 4 && ($r100 < 12 || $r100 > 14) ? ' miesiące' : ' miesięcy');
}

/**
 * Telefon i adres gabinetu z buildu (src/pages/api/bon/lib/kontakt.json.ts ← src/data/kontakt.yaml) – w PHP ich nie wpisujemy
 * (CLAUDE.md: „Nie duplikuj numerów…”). Brak pliku = wyjątek: wysyłka się nie uda, admin dostaje powiadomienie, ponowiony webhook dokończy.
 */
function bon_kontakt(array $k): array {
    static $pamiec = [];
    $plik = (string)($k['kontakt_plik'] ?? __DIR__ . '/kontakt.json');
    if (!isset($pamiec[$plik])) {
        $d = is_file($plik) ? json_decode((string)file_get_contents($plik), true) : null;
        if (!is_array($d) || !is_string($d['telefon'] ?? null) || !is_string($d['ulica'] ?? null)) throw new RuntimeException('brak danych kontaktowych (kontakt.json)');
        $pamiec[$plik] = $d;
    }
    return $pamiec[$plik];
}

/** Bajty PDF-a jednego bonu. $bon = wiersz z vouchers (code, initial_pln, expires_at, dla, od); $k = konfig (ważność, kontakt). */
function bon_pdf(array $bon, array $k): string {
    $kontakt = bon_kontakt($k);
    $miesiace = bon_miesiace_tekst((int)($k['waznosc_miesiecy'] ?? 6));
    bon_pdf_tcpdf();
    $uklad = json_decode((string)file_get_contents(__DIR__ . '/grafika/uklad.json'), true);
    $wartosc = (int)bon_grosze($bon['initial_pln']);
    $prog = bon_pdf_prog($wartosc);
    $kwota = bon_kwota_tekst($wartosc);
    $kod = (string)$bon['code'];
    $waznosc = bon_data_tekst(substr((string)$bon['expires_at'], 0, 10));

    $pdf = new BonTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('ewa-gabinet.pl');
    $pdf->SetAuthor('EWA Gabinet podologiczny');
    $pdf->SetTitle('Bon podarunkowy ' . $kod);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->setFontSubsetting(true);
    $pdf->AddPage();

    // --- nagłówek ---
    $pdf->SetTextColor(...BON_PDF_INK);
    $pdf->SetFont('latolight', '', 24);
    $pdf->SetXY(10, 14);
    $pdf->Cell(190, 11, 'Bon podarunkowy', 0, 1, 'C');
    $pdf->SetTextColor(...BON_PDF_MUTED);
    $pdf->SetFont('lato', '', 10.5);
    $pdf->SetX(10);
    $pdf->Cell(190, 6, 'Wydrukuj na zwykłej kartce, wytnij obie strony wzdłuż przerywanej linii i sklej je tyłem do siebie.', 0, 1, 'C');

    // --- karty: przód z lewej, tył z prawej ---
    $obrazW = 92.0;                                   // mm na jeden zrzut (z marginesem na cień)
    $s = $obrazW / $uklad['strony']['przod']['obraz']['w']; // mm na 1 px CSS
    $obrazH = $uklad['strony']['przod']['obraz']['h'] * $s;
    $y0 = 32.0;
    $xP = 10.0;
    $xT = 210.0 - 10.0 - $obrazW;
    $pt = static fn(float $px) => $px * $s / 0.352778; // rozmiar czcionki: px CSS karty → pt w PDF
    $pdf->Image(__DIR__ . "/grafika/tlo-przod-$prog.jpg", $xP, $y0, $obrazW, $obrazH, 'JPG');
    $pdf->Image(__DIR__ . "/grafika/tlo-tyl-$prog.jpg", $xT, $y0, $obrazW, $obrazH, 'JPG');

    // przód: kwota (miejsce .kwota-b, wyśrodkowana na karcie)
    $p = $uklad['strony']['przod'];
    $pdf->SetTextColor(...BON_PDF_INK);
    $pdf->SetFont('lato', '', $pt(56));
    $pdf->SetXY($xP + $p['karta']['x'] * $s, $y0 + $p['kwota']['y'] * $s);
    $pdf->Cell($p['karta']['w'] * $s, $p['kwota']['h'] * $s, $kwota, 0, 0, 'C', false, '', 0, false, 'T', 'M');

    // tył: numer, Dla / Od / Kwota na liniach, QR, ważność
    $t = $uklad['strony']['tyl'];
    $pdf->SetFont('latob', '', $pt(15));
    $pdf->setFontSpacing(0.25);
    $pdf->SetXY($xT + $t['karta']['x'] * $s, $y0 + $t['numer']['y'] * $s);
    $pdf->Cell($t['karta']['w'] * $s, $t['numer']['h'] * $s, 'NR ' . $kod, 0, 0, 'C', false, '', 0, false, 'T', 'M');
    $pdf->setFontSpacing(0);

    $pdf->SetFont('lato', '', $pt(18));
    foreach ([$bon['dla'] ?? '', $bon['od'] ?? '', $kwota] as $i => $tekst) {
        $l = $t['linie'][$i];
        if ($tekst === '' || $tekst === null) continue; // puste pole zostaje do wpisania ręcznie
        $wys = 7.0;
        $pdf->SetXY($xT + ($l['x'] + 4) * $s, $y0 + $l['y'] * $s - $wys + 0.6);
        $pdf->Cell(($l['w'] - 8) * $s, $wys, (string)$tekst, 0, 0, 'L', false, '', 1, false, 'T', 'B'); // stretch 1 = zmieść długie imię
    }

    $q = $t['qr'];
    $pdf->write2DBarcode(BON_PDF_ADRES_SPRAWDZ . rawurlencode($kod), 'QRCODE,M', $xT + $q['x'] * $s, $y0 + $q['y'] * $s, $q['w'] * $s, $q['h'] * $s,
        ['border' => false, 'padding' => 0, 'fgcolor' => BON_PDF_INK, 'bgcolor' => false], 'N');

    $w = $t['waznosc'];
    $pdf->SetXY($xT + $w['x'] * $s, $y0 + $w['y'] * $s);
    $pdf->SetFont('latob', '', $pt(16));
    $pdf->Cell($w['w'] * $s + 10, $w['h'] * $s / 2, 'Ważny do', 0, 2, 'L', false, '', 0, false, 'T', 'M');
    $pdf->SetTextColor(...BON_PDF_MUTED);
    $pdf->SetFont('lato', '', $pt(16));
    $pdf->Cell($w['w'] * $s + 10, $w['h'] * $s / 2, $waznosc, 0, 0, 'L', false, '', 0, false, 'T', 'M');

    // --- pod kartami: dane bonu i zasady w skrócie (pełne zasady: BON_REGULAMIN) ---
    $y = $y0 + $obrazH + 8;
    $pdf->SetTextColor(...BON_PDF_INK);
    $pdf->SetFont('latob', '', 12);
    $pdf->SetXY(20, $y);
    $pdf->Cell(170, 7, 'Numer bonu: ' . $kod . '   ·   Wartość: ' . $kwota . '   ·   Ważny do: ' . $waznosc, 0, 1, 'C');
    $pdf->SetFont('lato', '', 10.5);
    $pdf->SetTextColor(...BON_PDF_MUTED);
    $pdf->SetX(20);
    $pdf->Cell(170, 6, 'Saldo i ważność sprawdzisz, skanując kod QR na bonie.', 0, 1, 'C');

    $zasady = [
        'Bon można wykorzystać na zabiegi podologiczne w Gabinecie EWA, ' . $kontakt['ulica'] . ' w Pabianicach.',
        'Przy każdej wizycie wartość zabiegu odejmujemy od salda. Niewykorzystana kwota zostaje na bonie na kolejną wizytę.',
        'Jeśli zabieg kosztuje więcej niż saldo, różnicę można dopłacić na miejscu.',
        "Bon jest ważny $miesiace od dnia zakupu. Po tym terminie wygasa.",
        'Termin wizyty zarezerwujesz przez Booksy albo telefonicznie: ' . $kontakt['telefon'] . '. Przy rezerwacji podaj numer bonu.',
    ];
    $pdf->SetTextColor(...BON_PDF_INK);
    $pdf->SetFont('lato', '', 10.5);
    $pdf->SetXY(25, $pdf->GetY() + 5);
    foreach ($zasady as $z) {
        $pdf->SetX(25);
        $pdf->MultiCell(160, 5.4, '•  ' . $z, 0, 'L', false, 1);
    }
    // pełne zasady: klikalny link pod listą
    $pdf->SetTextColor(...BON_PDF_MUTED);
    $pdf->SetXY(25, $pdf->GetY() + 3);
    $adres = str_replace('https://', '', BON_REGULAMIN);
    $pdf->Write(5.4, 'Pełne zasady bonu: ');
    $pdf->Write(5.4, preg_replace('/#.*/', '', $adres), BON_REGULAMIN);
    return $pdf->Output('', 'S');
}
