<?php
// Zapasowa droga wysyłki maila z bonem: SMTP poczty OVH domeny (wpis doradcy 2026-11-23 22:20, decyzja Bartka).
// Powód: z hostingu współdzielonego OVH połączenia do api.mailjet.com co kilka godzin zrywają się na TLS (SSL_ERROR_SYSCALL).
// Mailjet zostaje pierwszą drogą; SMTP tylko, gdy wysyłka przez API padła błędem połączenia SPRZED wysłania treści
// (te same warunki co ponawianie) – wtedy Mailjet na pewno nic nie dostał i nie ma podwójnego maila.
// Własny prosty klient (bez zależności): TLS od początku (465) albo STARTTLS (587), AUTH LOGIN, jedna wiadomość.
// Koperta (MAIL FROM) = skrzynka, na którą się logujemy (OVH odrzuca inną) – SPF przez include:mx.ovh.com i DMARC zgodne.
// Konfig (konfig/bony.php, poza repo): mail_zapas, mail_smtp_host, mail_smtp_port, mail_smtp_user, mail_smtp_haslo,
// opcjonalnie mail_zapas_test_do (test na produkcji – patrz bon_zapas_wymuszony).

declare(strict_types=1);

/** Zapas włączony i kompletnie skonfigurowany. Brak konfiguracji = zachowanie jak przed zapasem. */
function bon_zapas_wlaczony(array $k): bool {
    if (empty($k['mail_zapas'])) return false;
    foreach (['mail_smtp_host', 'mail_smtp_port', 'mail_smtp_user', 'mail_smtp_haslo'] as $pole) {
        $w = (string)($k[$pole] ?? '');
        if ($w === '' || str_starts_with($w, 'WKLEJ')) return false;
    }
    return true;
}

/**
 * Test zapasu na produkcji: 'mail_zapas_test_do' => '2026-11-24 12:00' (czas Warszawy) – do tej chwili wysyłki idą od razu
 * przez SMTP, z pominięciem Mailjeta. Sam wygasa (zapomniany wpis nie przestawi sklepu na stałe); najwyżej 6 h naprzód,
 * dalszy termin jest ignorowany.
 */
function bon_zapas_wymuszony(array $k, ?int $teraz = null): bool {
    $do = trim((string)($k['mail_zapas_test_do'] ?? ''));
    if ($do === '' || !bon_zapas_wlaczony($k)) return false;
    try {
        $koniec = (new DateTimeImmutable($do, new DateTimeZone('Europe/Warsaw')))->getTimestamp();
    } catch (Throwable) {
        return false;
    }
    $teraz ??= time();
    return $koniec > $teraz && $koniec - $teraz <= 6 * 3600;
}

/** Wspólna wiadomość dla obu dróg (Mailjet i SMTP) – ta sama treść, nadawca, Reply-To i załączniki. */
function bon_mail_wiadomosc(array $k, string $do, string $temat, string $tekst, string $html, array $zalaczniki): ?array {
    if (!preg_match('/^(.*)<([^>]+)>\s*$/', (string)($k['mail_nadawca'] ?? ''), $m)) return null;
    return [
        'od' => trim($m[2]), 'od_nazwa' => trim($m[1]), 'do' => $do,
        'odpowiedz' => (string)($k['mail_odpowiedz'] ?? ''),
        'temat' => $temat, 'tekst' => $tekst, 'html' => $html, 'zalaczniki' => $zalaczniki,
    ];
}

/** Wiadomość MIME (CRLF): multipart/mixed { multipart/alternative { tekst, HTML }, PDF-y }. Bez obrazów cid: (mail ich nie ma). */
function bon_mail_mime(array $w): string {
    $nl = "\r\n";
    $naglowek = static fn(string $s) => preg_match('/[^\x20-\x7E]/', $s) ? mb_encode_mimeheader($s, 'UTF-8', 'B', $nl) : $s;
    $domena = substr(strrchr($w['od'], '@') ?: '@ewa-gabinet.pl', 1);
    $g1 = 'mix_' . bin2hex(random_bytes(12));
    $g2 = 'alt_' . bin2hex(random_bytes(12));
    $czesc = static fn(string $typ, string $dane, array $dodatkowe = []) => implode($nl, array_merge(["Content-Type: $typ"], $dodatkowe,
        ['Content-Transfer-Encoding: base64', '', rtrim(chunk_split(base64_encode($dane), 76, $nl))]));
    $h = [
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domena . '>',
        'From: ' . ($w['od_nazwa'] !== '' ? $naglowek($w['od_nazwa']) . ' ' : '') . '<' . $w['od'] . '>',
        'To: <' . $w['do'] . '>',
    ];
    if ($w['odpowiedz'] !== '') $h[] = 'Reply-To: <' . $w['odpowiedz'] . '>';
    $h[] = 'Subject: ' . $naglowek($w['temat']);
    $h[] = 'MIME-Version: 1.0';
    $h[] = "Content-Type: multipart/mixed; boundary=\"$g1\"";
    $tresc = ["--$g1", "Content-Type: multipart/alternative; boundary=\"$g2\"", '',
        "--$g2", $czesc('text/plain; charset=UTF-8', $w['tekst']),
        "--$g2", $czesc('text/html; charset=UTF-8', $w['html']),
        "--$g2--"];
    foreach ($w['zalaczniki'] as $z) {
        $nazwa = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$z['nazwa']);
        $tresc[] = "--$g1";
        $tresc[] = $czesc("application/pdf; name=\"$nazwa\"", (string)$z['dane'], ["Content-Disposition: attachment; filename=\"$nazwa\""]);
    }
    $tresc[] = "--$g1--";
    return implode($nl, $h) . $nl . $nl . implode($nl, $tresc) . $nl;
}

/** Odpowiedź serwera SMTP do dziennika: pierwsza linia, bez adresów e-mail. */
function bon_smtp_odp_jawna(string $odp): string {
    return mb_substr(preg_replace('/[^\s@<>"]+@[^\s@<>"]+/', '***', strtok(trim($odp), "\n") ?: '') ?? '', 0, 120);
}

/**
 * Wysyłka przez SMTP. Zwraca ['blad' => ?string, 'kod' => int ostatnia odpowiedź, 'odp' => linia bez adresów,
 * 'mozliwe_wyslane' => bool]. mozliwe_wyslane = połączenie padło PO przesłaniu treści (kropka po DATA), a przed
 * odpowiedzią 250 – serwer mógł mail przyjąć; wtedy NIE ponawiamy automatycznie (bez dubli), tylko powiadomienie gabinetu.
 */
function bon_smtp_wyslij(array $k, array $w): array {
    if ($k['mail_tryb'] === 'plik') { // testy: bez sieci, zapis skrótu do pliku w katalogu konfigu testu
        $tryb = (string)($k['smtp_test_blad'] ?? '');
        if ($tryb === 'polaczenie') return ['blad' => 'SMTP: symulowany brak połączenia', 'kod' => 0, 'odp' => '', 'mozliwe_wyslane' => false];
        if ($tryb === 'po_danych') return ['blad' => 'SMTP: symulowane zerwanie po treści', 'kod' => 0, 'odp' => '', 'mozliwe_wyslane' => true];
        $mime = bon_mail_mime($w);
        file_put_contents($k['_katalog'] . '/bony-smtp.jsonl', json_encode(['od' => $w['od'], 'do' => $w['do'], 'temat' => $w['temat'],
            'zalaczniki' => array_map(static fn($z) => $z['nazwa'], $w['zalaczniki']), 'bajtow' => strlen($mime),
            'naglowki' => explode("\r\n", substr($mime, 0, (int)strpos($mime, "\r\n\r\n")))], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return ['blad' => null, 'kod' => 250, 'odp' => '250 OK (tryb plik)', 'mozliwe_wyslane' => false];
    }
    $host = (string)$k['mail_smtp_host'];
    $port = (int)$k['mail_smtp_port'];
    $kontekst = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
    $s = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . "$host:$port", $nr, $opis, 15, STREAM_CLIENT_CONNECT, $kontekst);
    $zly = static fn(string $blad, int $kod = 0, string $odp = '', bool $mozliwe = false) =>
        ['blad' => 'SMTP: ' . $blad, 'kod' => $kod, 'odp' => bon_smtp_odp_jawna($odp), 'mozliwe_wyslane' => $mozliwe];
    if (!$s) return $zly("połączenie $host:$port: $nr $opis");
    stream_set_timeout($s, 30);
    $czytaj = static function () use ($s): string {
        $odp = '';
        while (($l = fgets($s, 1024)) !== false) { $odp .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $odp;
    };
    $polecenie = static function (string $cmd, array $oczekiwane) use ($s, $czytaj): array {
        if ($cmd !== '') fwrite($s, $cmd . "\r\n");
        $odp = $czytaj();
        $kod = (int)substr($odp, 0, 3);
        return [in_array($kod, $oczekiwane, true), $kod, $odp];
    };
    try {
        [$ok, $kod, $odp] = $polecenie('', [220]);
        if (!$ok) return $zly('powitanie', $kod, $odp);
        [$ok, $kod, $odp] = $polecenie('EHLO ewa-gabinet.pl', [250]);
        if (!$ok) return $zly('EHLO', $kod, $odp);
        if ($port !== 465) {
            [$ok, $kod, $odp] = $polecenie('STARTTLS', [220]);
            if (!$ok) return $zly('STARTTLS', $kod, $odp);
            if (@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                return $zly('TLS: ' . (error_get_last()['message'] ?? '?'));
            }
            [$ok, $kod, $odp] = $polecenie('EHLO ewa-gabinet.pl', [250]);
            if (!$ok) return $zly('EHLO po TLS', $kod, $odp);
        }
        [$ok, $kod, $odp] = $polecenie('AUTH LOGIN', [334]);
        if (!$ok) return $zly('AUTH', $kod, $odp);
        [$ok, $kod, $odp] = $polecenie(base64_encode((string)$k['mail_smtp_user']), [334]);
        if (!$ok) return $zly('AUTH (login)', $kod, $odp);
        [$ok, $kod, $odp] = $polecenie(base64_encode((string)$k['mail_smtp_haslo']), [235]);
        if (!$ok) return $zly('AUTH (hasło odrzucone?)', $kod, $odp);
        [$ok, $kod, $odp] = $polecenie('MAIL FROM:<' . (string)$k['mail_smtp_user'] . '>', [250]);
        if (!$ok) return $zly('MAIL FROM', $kod, $odp);
        [$ok, $kod, $odp] = $polecenie('RCPT TO:<' . $w['do'] . '>', [250, 251]);
        if (!$ok) return $zly('RCPT TO', $kod, $odp);
        [$ok, $kod, $odp] = $polecenie('DATA', [354]);
        if (!$ok) return $zly('DATA', $kod, $odp);
        // treść z „kropkowaniem” (linia zaczynająca się od kropki → dwie kropki), zakończenie <CRLF>.<CRLF>
        $dane = preg_replace('/^\./m', '..', bon_mail_mime($w)) . ".\r\n";
        $zapisano = 0;
        while ($zapisano < strlen($dane)) {
            $n = @fwrite($s, substr($dane, $zapisano, 65536));
            if ($n === false || $n === 0) return $zly('przerwane w trakcie przesyłania treści'); // bez kropki serwer odrzuci całość
            $zapisano += $n;
        }
        $odp = $czytaj();
        if ($odp === '') return $zly('brak odpowiedzi po przesłaniu treści – serwer mógł przyjąć mail', 0, '', true);
        $kod = (int)substr($odp, 0, 3);
        if ($kod !== 250) return $zly('serwer nie przyjął treści', $kod, $odp);
        @fwrite($s, "QUIT\r\n");
        return ['blad' => null, 'kod' => 250, 'odp' => bon_smtp_odp_jawna($odp), 'mozliwe_wyslane' => false];
    } finally {
        @fclose($s);
    }
}
