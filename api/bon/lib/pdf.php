<?php
// PDF bonu (backend bonów, etap 2: 2026-11-21; od 2026-11-24 3 strony: „Pokaż w gabinecie” + dwie wycinanki składane, lib/grafika/uklad-wycinanka*.json).
// Dawniej: jedna strona A4: przód i tył karty obok siebie (do wydruku i wycięcia),
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

// kolor tła wg rodzaju bonu (Bartek 2026-11-24, jak karty na /bony): 200 szmaragd (zielen), 300 rubin (czerwony),
// 500 bursztyn (zloty), dowolna kwota szafir (granat) – także gdy dowolna kwota wynosi 300 albo 500 zł.
// Inna wartość (np. bon z webhooka na 250 zł bez znanego wariantu) = granat jak dowolna kwota.
function bon_pdf_kolor(array $k, array $bon): string {
    return match (bon_rodzaj($k, $bon)) {
        200 => 'zielen',
        300 => 'czerwony',
        500 => 'zloty',
        default => 'granat',
    };
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
    $wartosc = (int)bon_grosze($bon['initial_pln']);
    $prog = bon_pdf_kolor($k, $bon);
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

    // === strona 1: „Pokaż w gabinecie” (Bartek 2026-11-24, wpis 12:55) – PDF otwarty na telefonie pokazuje górę pierwszej
    // strony, więc obdarowany od razu ma duży kod QR i numer do pokazania w gabinecie. Bez danych kupującego. ===
    $pdf->AddPage();
    $adresQr = BON_PDF_ADRES_SPRAWDZ . rawurlencode($kod);
    $pdf->SetTextColor(...BON_PDF_MUTED);
    $pdf->SetFont('lato', '', 10.5);
    $pdf->SetXY(15, 12);
    $pdf->Cell(180, 6, 'Bon podarunkowy · EWA Gabinet podologiczny', 0, 1, 'L');
    // karta z kodem: tło jak --c-chip, kod 50 mm, obok numer, nazwa, kwota, ważność
    $pdf->SetFillColor(246, 239, 233);
    $pdf->RoundedRect(15, 22, 180, 70, 4, '1111', 'F');
    $pdf->SetFillColor(255, 255, 255);
    $pdf->RoundedRect(21, 28, 58, 58, 2, '1111', 'F');
    $pdf->write2DBarcode($adresQr, 'QRCODE,M', 25, 32, 50, 50, ['border' => false, 'padding' => 0, 'fgcolor' => BON_PDF_INK, 'bgcolor' => false], 'N');
    $x = 88;
    $pdf->SetTextColor(...BON_PDF_INK);
    $pdf->SetFont('latolight', '', 17);
    $pdf->SetXY($x, 29);
    $pdf->Cell(102, 8, bon_nazwa($k, $bon), 0, 2, 'L');
    $pdf->SetFont('lato', '', 30);
    $pdf->Cell(102, 14, $kwota, 0, 2, 'L');
    $pdf->SetFont('latob', '', 16);
    $pdf->setFontSpacing(0.3);
    $pdf->Cell(102, 9, $kod, 0, 2, 'L');
    $pdf->setFontSpacing(0);
    $pdf->SetFont('lato', '', 12);
    $pdf->SetTextColor(...BON_PDF_MUTED);
    $pdf->Cell(102, 7, 'Ważny do ' . $waznosc, 0, 2, 'L');
    $pdf->SetTextColor(...BON_PDF_INK);
    $pdf->SetFont('latob', '', 12);
    $pdf->SetXY($x, 74);
    $pdf->MultiCell(102, 5.6, 'Pokaż ten kod w gabinecie albo podaj numer bonu.', 0, 'L', false, 1);

    // jak umówić wizytę: Booksy i oba telefony gabinetu (kontakt.json z buildu)
    $telefony = array_values(array_filter((array)($kontakt['telefony'] ?? [$kontakt['telefon']])));
    $booksy = (string)($kontakt['booksy'] ?? '');
    $pdf->SetXY(15, 102);
    $pdf->SetFont('latob', '', 13);
    $pdf->Cell(180, 7, 'Jak umówić wizytę', 0, 1, 'L');
    $pdf->SetFont('lato', '', 11);
    if ($booksy !== '') {
        $pdf->SetX(15);
        $pdf->Write(6, '•  Online przez Booksy: ');
        $pdf->SetTextColor(...BON_PDF_MUTED);
        $pdf->Write(6, 'umów wizytę', $booksy);
        $pdf->SetTextColor(...BON_PDF_INK);
        $pdf->Ln(6);
    }
    $pdf->SetX(15);
    $pdf->MultiCell(180, 6, '•  Telefonicznie: ' . implode(' albo ', $telefony) . '. Przy rezerwacji podaj numer bonu.', 0, 'L', false, 1);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 6, '•  Gabinet EWA, ' . $kontakt['ulica'] . ', ' . ($kontakt['miasto'] ?? 'Pabianice') . '.', 0, 'L', false, 1);

    $pdf->SetXY(15, $pdf->GetY() + 5);
    $pdf->SetFont('latob', '', 13);
    $pdf->Cell(180, 7, 'Dobrze wiedzieć', 0, 1, 'L');
    $pdf->SetFont('lato', '', 11);
    foreach ([
        'Przy każdej wizycie wartość zabiegu odejmujemy od salda. Niewykorzystana kwota zostaje na bonie na kolejną wizytę.',
        'Jeśli zabieg kosztuje więcej niż saldo, różnicę można dopłacić na miejscu.',
        "Bon jest ważny $miesiace od dnia zapłaty. Po tym terminie wygasa.", // wpis 18:00: zapłaty, nie zakupu
        'Saldo i ważność sprawdzisz, skanując kod QR.',
    ] as $z) {
        $pdf->SetX(15);
        $pdf->MultiCell(180, 6, '•  ' . $z, 0, 'L', false, 1);
    }
    $pdf->SetTextColor(...BON_PDF_MUTED);
    $pdf->SetXY(15, $pdf->GetY() + 3);
    $pdf->Write(6, 'Pełne zasady bonu: ');
    $pdf->Write(6, preg_replace('/#.*/', '', str_replace('https://', '', BON_REGULAMIN)), BON_REGULAMIN);
    $pdf->SetFont('lato', '', 10.5);
    $pdf->SetXY(15, 270);
    $pdf->Cell(180, 6, 'Na następnych stronach: bon do wydrukowania – do wyboru z miejscem na wstążkę albo z nadrukowaną kokardą.', 0, 1, 'C');

    // === strony 2 i 3 (Bartek 2026-11-24, decyzja 15:35, wpis 22:22): dwie wycinanki składane – przód i tył obok siebie na jednym
    // tle z liniami cięcia i zgięcia (tła i uklad-*.json z frontu, PR #57). Strona 2: P3 „do przewiązania” (pas ton w ton),
    // strona 3: z nadrukowaną wstążką i kokardą. Dawna strona z osobnym przodem i tyłem (tlo-przod/tyl-*) wypadła z PDF. ===
    foreach ([
        ['uklad-wycinanka.json', "tlo-wycinanka-$prog.jpg", 'Bon do przewiązania wstążką', 'Wytnij, zegnij, sklej, a jeśli masz wstążkę, przewiąż bon.'],
        ['uklad-wycinanka-wstazka.json', "tlo-wycinanka-wstazka-$prog.jpg", 'Bon z nadrukowaną kokardą', 'Wytnij, zegnij i sklej.'],
    ] as [$plikUkladu, $tlo, $tytul, $podtytul]) {
        $uklad = json_decode((string)file_get_contents(__DIR__ . '/grafika/' . $plikUkladu), true);
        bon_pdf_wycinanka($pdf, $uklad, __DIR__ . '/grafika/' . $tlo, $tytul, $podtytul, $bon, $kod, $kwota, $waznosc, $adresQr);
    }
    return $pdf->Output('', 'S');
}

/**
 * Strona z wycinanką: nagłówek, tło (przód | zgięcie | tył, 190 mm szerokości) i pola zmienne w miejscach z uklad-*.json
 * (px CSS względem obrazu). Wpisy 22:27/22:34: numer „Numer bonu” + numer pogrubiony, odsunięty od logo; Dla/Od/Kwota NAD
 * liniami (dół tekstu ok. 1,2 mm nad linią, wcześniej tekst nachodził na linię), kwota wyśrodkowana na długości linii;
 * kwota na przodzie Lato Bold (była za cienka).
 */
function bon_pdf_wycinanka(TCPDF $pdf, array $u, string $tlo, string $tytul, string $podtytul, array $bon, string $kod, string $kwota, string $waznosc, string $adresQr): void {
    $pdf->AddPage();
    $pdf->SetTextColor(...BON_PDF_INK);
    $pdf->SetFont('latolight', '', 22);
    $pdf->SetXY(10, 12);
    $pdf->Cell(190, 10, $tytul, 0, 1, 'C');
    $pdf->SetTextColor(...BON_PDF_MUTED);
    $pdf->SetFont('lato', '', 10.5);
    $pdf->SetX(10);
    $pdf->Cell(190, 6, $podtytul, 0, 1, 'C');

    $x0 = 10.0;
    $y0 = 30.0;
    $s = 190.0 / $u['obraz']['w'];                       // mm na 1 px CSS
    $pt = static fn(float $px) => $px * $s / 0.352778;   // rozmiar czcionki: px CSS → pt
    $X = static fn(float $px) => $x0 + $px * $s;
    $Y = static fn(float $px) => $y0 + $px * $s;
    $pdf->Image($tlo, $x0, $y0, 190.0, $u['obraz']['h'] * $s, 'JPG');

    // przód: kwota wyśrodkowana na karcie, Lato Bold
    $p = $u['przod'];
    $pdf->SetTextColor(...BON_PDF_INK);
    $pdf->SetFont('latob', '', $pt(56));
    $pdf->SetXY($X($p['karta']['x']), $Y($p['kwota']['y']));
    $pdf->Cell($p['karta']['w'] * $s, $p['kwota']['h'] * $s, $kwota, 0, 0, 'C', false, '', 1, false, 'T', 'M');

    // tył: „Numer bonu” + numer pogrubiony, wyśrodkowane na karcie, odsunięte w dół od logo
    $t = $u['tyl'];
    $etykieta = 'Numer bonu  ';
    $pdf->SetFont('lato', '', $pt(14));
    $wE = $pdf->GetStringWidth($etykieta);
    $pdf->SetFont('latob', '', $pt(17));
    $pdf->setFontSpacing(0.2);
    $wK = $pdf->GetStringWidth($kod);
    $xN = $X($t['karta']['x']) + ($t['karta']['w'] * $s - $wE - $wK) / 2;
    $yN = $Y($t['numer']['y']); // odstęp od logo daje tło (front #98: numer.y 254 → 276)
    $odstepy = $pdf->getCellPaddings();
    $pdf->setCellPaddings(0, 0, 0, 0); // bez wewnętrznego odstępu komórek TCPDF (~1 mm): numer wyśrodkowany, „Ważny do” na krawędzi listy z tła
    $hN = $t['numer']['h'] * $s;
    $pdf->setFontSpacing(0);
    $pdf->SetFont('lato', '', $pt(14));
    $pdf->SetXY($xN, $yN);
    $pdf->Cell($wE, $hN, $etykieta, 0, 0, 'L', false, '', 0, false, 'T', 'B');
    $pdf->SetFont('latob', '', $pt(17));
    $pdf->setFontSpacing(0.2);
    $pdf->Cell($wK + 1, $hN, $kod, 0, 0, 'L', false, '', 0, false, 'T', 'B');
    $pdf->setFontSpacing(0);

    // Dla / Od / Kwota: tekst NAD linią (dół 1,2 mm nad nią); Dla/Od od lewej, kwota na środku linii; puste = do wpisania ręcznie
    $pdf->SetFont('lato', '', $pt(18));
    foreach ([$bon['dla'] ?? '', $bon['od'] ?? '', $kwota] as $i => $tekst) {
        if ($tekst === '' || $tekst === null) continue;
        $l = $t['linie'][$i];
        $wys = 7.0;
        $pdf->SetXY($X($l['x'] + 4), $Y($l['y']) - 1.2 - $wys);
        $pdf->Cell(($l['w'] - 8) * $s, $wys, (string)$tekst, 0, 0, $i === 2 ? 'C' : 'L', false, '', 1, false, 'T', 'B'); // stretch 1 = zmieść długie imię
    }

    $q = $t['qr'];
    $pdf->write2DBarcode($adresQr, 'QRCODE,M', $X($q['x']), $Y($q['y']), $q['w'] * $s, $q['h'] * $s,
        ['border' => false, 'padding' => 0, 'fgcolor' => BON_PDF_INK, 'bgcolor' => false], 'N');

    $w = $t['waznosc'];
    $pdf->SetTextColor(...BON_PDF_INK);
    $pdf->SetXY($X($w['x']), $Y($w['y']));
    $pdf->SetFont('latob', '', $pt(16));
    $pdf->Cell($w['w'] * $s + 10, $w['h'] * $s / 2, 'Ważny do', 0, 2, 'L', false, '', 0, false, 'T', 'M');
    $pdf->SetTextColor(...BON_PDF_MUTED);
    $pdf->SetFont('lato', '', $pt(16));
    $pdf->Cell($w['w'] * $s + 10, $w['h'] * $s / 2, $waznosc, 0, 0, 'L', false, '', 0, false, 'T', 'M');
    $pdf->setCellPaddings($odstepy['L'], $odstepy['T'], $odstepy['R'], $odstepy['B']);

    // pod wycinanką: dane bonu i jak drukować
    $y = $y0 + $u['obraz']['h'] * $s + 6;
    $pdf->SetTextColor(...BON_PDF_INK);
    $pdf->SetFont('latob', '', 12);
    $pdf->SetXY(10, $y);
    $pdf->Cell(190, 7, 'Numer bonu: ' . $kod . '   ·   Wartość: ' . $kwota . '   ·   Ważny do: ' . $waznosc, 0, 1, 'C');
    $pdf->SetFont('lato', '', 10.5);
    $pdf->SetTextColor(...BON_PDF_MUTED);
    $pdf->SetX(10);
    $pdf->Cell(190, 6, 'Drukuj na kartce A4 w rzeczywistym rozmiarze (100%, bez dopasowania do strony).', 0, 1, 'C');
}
