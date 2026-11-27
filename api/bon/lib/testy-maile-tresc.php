<?php
// Testy #220 (2026-11-27, Backend 2): treść maili powiadomień – kwoty po polsku („5,80 zł”, także w temacie), imię zamiast loginu,
// UUID skrócone w treści (pełne tylko w drobnym druku), kolejność: co się stało → kwota → bon → co dalej, szablon frontu
// (mail-powiadomienie.tpl, pola {{…}}) i HTML zapasowy, gdy szablonu nie ma. Woła je bon_testy() – SQLite i OVH (test-ovh.php).

declare(strict_types=1);
require_once __DIR__ . '/bon.php';

/** #220: czy fragmenty występują w tekście w tej kolejności (każdy po poprzednim). */
function bon_testy_kolejnosc(string $tekst, array $fragmenty): bool {
    $od = 0;
    foreach ($fragmenty as $f) {
        $p = mb_strpos($tekst, $f, $od);
        if ($p === false) return false;
        $od = $p + 1;
    }
    return true;
}

function bon_testy_maile_tresc(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = ['#220 ' . $nazwa, $ok, $szczegol]; };
    $nb = "\u{00A0}";

    // kwoty i identyfikatory
    $t('kwota: 580 gr → „5,80 zł”, 25000 → „250 zł”, 120050 → „1 200,50 zł” (twarde spacje)',
        bon_kwota_pl(580) === "5,80{$nb}zł" && bon_kwota_pl(25000) === "250{$nb}zł" && bon_kwota_pl(120050) === "1{$nb}200,50{$nb}zł",
        bon_kwota_pl(580) . ' | ' . bon_kwota_pl(120050));
    $t('kwoty w gotowym tekście: „450.00 zł” → „450 zł”, „5.80 zł” → „5,80 zł”; inne liczby bez zmian',
        bon_kwoty_pl_w_tekscie('kwota zamówienia 450.00 zł, minimum 5.80 zł, wersja 1.20') === "kwota zamówienia 450{$nb}zł, minimum 5,80{$nb}zł, wersja 1.20",
        bon_kwoty_pl_w_tekscie('kwota zamówienia 450.00 zł, minimum 5.80 zł, wersja 1.20'));
    $uuid = '3f2a91c0-7b1e-4c2d-9a8b-0123456789ab';
    $t('UUID → 8 znaków + „…”; inne identyfikatory (ord_…, numer) bez zmian',
        bon_id_krotko($uuid) === '3f2a91c0…' && bon_id_krotko('ord_zapas_po_danych') === 'ord_zapas_po_danych' && bon_id_krotko('') === '',
        bon_id_krotko($uuid));

    // treść z danych: kolejność i drobny druk
    $d = [
        'co' => 'Zwrot zlecony z panelu w Easytools.',
        'kwota' => 580,
        'bony' => ['EWA-HTAH-4PPG'],
        'pola' => [['Zlecił', 'Bartek'], ['Zamówienie', bon_id_krotko($uuid)]],
        'dalej' => 'Easytools zaksięguje zwrot po potwierdzeniu przez operatora płatności.',
        'drobny' => [['Zamówienie Easytools', $uuid]],
    ];
    $tekst = bon_powiadom_tekst($d);
    $poz = array_map(static fn($s) => mb_strpos($tekst, $s), ['Zwrot zlecony', "Kwota: 5,80{$nb}zł", 'Bon: EWA-HTAH-4PPG', 'Zlecił: Bartek', 'Easytools zaksięguje', $uuid]);
    $t('tekst: co się stało → kwota → bon → pola → co dalej → drobny druk (pełne UUID na końcu)',
        !in_array(false, $poz, true) && $poz === array_values(array_unique($poz)) && $poz == array_values((static function ($p) { sort($p); return $p; })($poz)),
        json_encode($poz) . "\n" . $tekst);
    $przedDrobnym = mb_substr($tekst, 0, (int)mb_strpos($tekst, 'Szczegóły techniczne'));
    $t('tekst: pełne UUID tylko w drobnym druku, wyżej skrót', str_contains($tekst, 'Szczegóły techniczne') && !str_contains($przedDrobnym, $uuid)
        && str_contains($przedDrobnym, '3f2a91c0…'), $tekst);

    // HTML zapasowy (bez szablonu frontu): tabelka, wyróżniona kwota, przycisk do panelu, UUID w drobnym druku
    $kBez = array_replace($k, ['mail_powiadomienie_szablon_plik' => $k['_katalog'] . '/nie-ma-takiego.tpl']);
    $html = bon_powiadom_html_dane($kBez, 'Zlecono zwrot ' . bon_kwota_pl(580) . ' – EWA-HTAH-4PPG', $d);
    $t('HTML zapasowy: kwota, numer bonu, „Zlecił” – „Bartek”, przycisk „Otwórz bon w panelu”, pełne UUID',
        str_contains($html, "5,80{$nb}zł") && str_contains($html, 'EWA-HTAH-4PPG') && str_contains($html, 'Zlecił') && str_contains($html, 'Bartek')
        && str_contains($html, 'panel-bonow?nr=EWA-HTAH-4PPG') && str_contains($html, $uuid) && str_contains($html, 'System bonów'), substr($html, 0, 300));

    // szablon frontu: bloki powtarzane i znikające, wartości escapowane, bez znaczników
    $tpl = $k['_katalog'] . '/mail-powiadomienie-test.tpl';
    file_put_contents($tpl, '<title>{{TEMAT}}</title><i>{{PREHEADER}}</i><h1>{{TEMAT}}</h1><p>{{CO}}</p><!--KWOTA--><i>{{KWOTA_ETYKIETA}}</i><b>{{KWOTA}}</b><!--/KWOTA-->'
        . '<!--BON--><code>{{BON}}</code><!--/BON--><table><!--POLE--><tr><th>{{ETYKIETA}}</th><td>{{WARTOSC}}</td></tr><!--/POLE--></table>'
        . '<p>{{DALEJ}}</p><!--PRZYCISK--><a href="{{PRZYCISK_ADRES}}">{{PRZYCISK_TEKST}}</a><!--/PRZYCISK-->'
        . '<!--DROBNY--><small>{{ETYKIETA}}: {{WARTOSC}}</small><!--/DROBNY--><!--GABINET--><footer>System</footer><!--/GABINET--><!--KLIENT--><footer>Pozdrawiamy</footer><!--/KLIENT-->');
    $kTpl = array_replace($k, ['mail_powiadomienie_szablon_plik' => $tpl]);
    $d2 = $d + [];
    $d2['bony'] = ['EWA-HTAH-4PPG', 'EWA-2222-3333'];
    $d2['pola'][] = ['Uwaga', '<b>x</b>'];
    $h = bon_powiadom_html_dane($kTpl, 'Zlecono zwrot', $d2);
    $hE = bon_powiadom_html_dane($kTpl, 'Zwrot', ['co' => 'x', 'kwota' => 9000, 'kwota_etykieta' => 'Kwota do zwrotu & <test>']);
    $t('szablon frontu: {{KWOTA_ETYKIETA}} z kwota_etykieta (escapowane), domyślnie „Kwota”',
        str_contains($hE, '<i>Kwota do zwrotu &amp; &lt;test&gt;</i>') && str_contains($h, '<i>Kwota</i>'), $hE);
    $t('HTML zapasowy: podpis kwoty nad kwotą', str_contains(bon_powiadom_html_dane($kBez, 'Zwrot', ['co' => 'x', 'kwota' => 9000, 'kwota_etykieta' => 'Kwota do zwrotu']), 'Kwota do zwrotu</p>'));
    $t('szablon frontu: dwa bloki BON, trzy POLE, escapowanie, stopka GABINET (bez KLIENT), bez {{ }} i znaczników',
        substr_count($h, '<code>') === 2 && substr_count($h, '<tr>') === 3 && str_contains($h, '&lt;b&gt;x&lt;/b&gt;') && str_contains($h, '<footer>System</footer>')
        && !str_contains($h, 'Pozdrawiamy') && !str_contains($h, '{{') && !str_contains($h, '<!--') && str_contains($h, 'panel-bonow?nr=EWA-HTAH-4PPG'), $h);
    $h3 = bon_powiadom_html_dane($kTpl, 'Bez kwoty', ['co' => 'Coś się stało.', 'dalej' => 'Sprawdź.', 'klient' => true]);
    $t('szablon frontu: bez kwoty, bonu, przycisku i drobnego druku – bloki znikają; mail do klienta – stopka KLIENT',
        !str_contains($h3, '<b>') && !str_contains($h3, '<code>') && !str_contains($h3, '<a ') && !str_contains($h3, '<small>')
        && str_contains($h3, 'Pozdrawiamy') && !str_contains($h3, 'System') && !str_contains($h3, '<!--'), $h3);
    $h4 = bon_powiadom_html_dane($kTpl, 'Twarde', ['co' => "Dzień dobry,\nbon w gabinecie i w domu", 'dalej' => 'Zadzwoń z telefonu']);
    $t('szablon frontu (Front 2 pkt 4): CO i DALEJ – twarda spacja po a/i/o/u/w/z, nowa linia → <br>',
        // ta sama reguła co twarde() w mail-bon.tpl.ts: przy „i w” twarda spacja tylko po pierwszym słowie (dopasowania się nie nakładają)
        str_contains($h4, 'bon w&nbsp;gabinecie i&nbsp;w domu') && str_contains($h4, 'Dzień dobry,<br>') && str_contains($h4, 'Zadzwoń z&nbsp;telefonu'), $h4);
    file_put_contents($tpl, '<h1>{{TEMAT}}</h1><p>{{CO}}</p><!--POLA--><b>{{ETYKIETA}}</b><!--/POLA--><p>{{DALEJ}}</p>');
    $h5 = bon_powiadom_html_dane($kTpl, 'Literówka', ['co' => 'Coś.', 'pola' => [['A', 'B']], 'dalej' => 'X.']);
    $t('szablon frontu (Front 2 pkt 5): nieznany znacznik bloku (<!--POLA-->) → HTML zapasowy (pole w tabelce), bez znaczników',
        !str_contains($h5, '<!--POLA-->') && !str_contains($h5, '{{') && str_contains($h5, 'System bonów') && str_contains($h5, '>A</td>'), substr($h5, 0, 200));
    @unlink($tpl);

    // bon_powiadom z danymi: plik testowy w tym samym formacie co dotąd (== temat \n treść), HTML z danych
    $kat = sys_get_temp_dir() . '/bony-maile-tresc-' . bin2hex(random_bytes(4));
    @mkdir($kat, 0700, true);
    $kP = array_replace($kBez, ['mail_tryb' => 'plik', '_katalog' => $kat, 'mail_powiadomienie' => ['alerty@test']]);
    bon_powiadom_dane($kP, 'Zlecono zwrot ' . bon_kwota_pl(580) . ' – EWA-HTAH-4PPG', $d);
    $plik = (string)@file_get_contents($kat . '/bony-powiadomienia.txt');
    $t('bon_powiadom_dane: wpis „== temat” + tekst z danych, HTML z tabelką', str_starts_with($plik, "== Zlecono zwrot 5,80{$nb}zł – EWA-HTAH-4PPG\n")
        && str_contains($plik, 'Zlecił: Bartek') && str_contains((string)@file_get_contents($kat . '/bony-powiadomienie-ostatnie.html'), 'Zlecił'), $plik);
    array_map('unlink', glob($kat . '/*') ?: []);
    @rmdir($kat);

    return $wyniki;
}
