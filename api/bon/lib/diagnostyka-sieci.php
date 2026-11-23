<?php
// Diagnostyka połączenia z Mailjetem z serwera OVH (2026-11-23): GET /api/bon/log.php?siec=1 (ten sam token co log.php).
// Powód: wysyłka bonu kończy się „SSL_connect: SSL_ERROR_SYSCALL in connection to api.mailjet.com:443”, a z innych sieci Mailjet odpowiada.
// Co sprawdza (bez kluczy Mailjet – żądania są nieuwierzytelnione, spodziewane HTTP 401 oznacza, że TLS przeszedł):
//  - DNS (A i AAAA) dla api.mailjet.com i in-v3.mailjet.com,
//  - samo połączenie TCP na 443,
//  - HTTPS przez cURL: domyślnie, tylko IPv4, tylko IPv6, TLS 1.2, TLS 1.3 – po kilka prób (błąd stały czy losowy),
//    z ogonem logu połączenia (CURLOPT_VERBOSE) przy nieudanej próbie,
//  - porównanie z innymi adresami HTTPS (Easytools, Google),
//  - SMTP Mailjeta (587 i 2525): powitanie serwera, EHLO, STARTTLS,
//  - publiczny adres IP, z którego wychodzi ruch z hostingu (do zgłoszenia w OVH albo Mailjecie).
// Wynik nie zawiera sekretów ani danych osobowych.

declare(strict_types=1);

const BON_SIEC_PROB = 3;

/** Jedno żądanie HTTPS (GET, bez danych logowania). Zwraca wynik bez treści odpowiedzi. */
function bon_siec_https(string $url, array $opcje = []): array {
    $c = curl_init($url);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'ewa-gabinet-diagnostyka/1',
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR => $verbose,
    ] + $opcje);
    $start = microtime(true);
    $odp = curl_exec($c);
    $wynik = [
        'ok' => $odp !== false,
        'http' => (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE),
        'ip' => (string)curl_getinfo($c, CURLINFO_PRIMARY_IP),
        'tcp_ms' => (int)round(1000 * (float)curl_getinfo($c, CURLINFO_CONNECT_TIME)),
        'tls_ms' => (int)round(1000 * (float)curl_getinfo($c, CURLINFO_APPCONNECT_TIME)),
        'razem_ms' => (int)round(1000 * (microtime(true) - $start)),
        'blad_nr' => curl_errno($c),
        'blad' => curl_error($c),
    ];
    curl_close($c);
    if (!$wynik['ok']) {
        rewind($verbose);
        $linie = preg_split('/\r?\n/', (string)stream_get_contents($verbose)) ?: [];
        // tylko linie informacyjne cURL („* …”), bez nagłówków; i tak nie wysyłamy żadnych danych logowania
        $info = array_values(array_filter($linie, static fn($l) => str_starts_with($l, '*')));
        $wynik['przebieg'] = array_slice($info, -12);
    }
    fclose($verbose);
    return $wynik;
}

/** Kilka prób tego samego wariantu: podsumowanie + szczegóły. */
function bon_siec_proby(string $url, array $opcje = [], int $ile = BON_SIEC_PROB): array {
    $proby = [];
    for ($i = 0; $i < $ile; $i++) {
        if ($i > 0) usleep(700000);
        $proby[] = bon_siec_https($url, $opcje);
    }
    $udane = count(array_filter($proby, static fn($p) => $p['ok']));
    return ['udane' => "$udane/$ile", 'proby' => $proby];
}

/** Połączenie TCP (bez TLS). */
function bon_siec_tcp(string $host, int $port): array {
    $start = microtime(true);
    $s = @fsockopen($host, $port, $nr, $opis, 6);
    $ms = (int)round(1000 * (microtime(true) - $start));
    if (!$s) return ['ok' => false, 'ms' => $ms, 'blad' => "$nr $opis"];
    fclose($s);
    return ['ok' => true, 'ms' => $ms];
}

/** SMTP: powitanie, EHLO, STARTTLS i uzgodnienie TLS (bez logowania i bez wysyłki). */
function bon_siec_smtp(string $host, int $port): array {
    $wynik = ['port' => $port];
    $s = @stream_socket_client("tcp://$host:$port", $nr, $opis, 6);
    if (!$s) return $wynik + ['ok' => false, 'etap' => 'tcp', 'blad' => "$nr $opis"];
    stream_set_timeout($s, 6);
    $czytaj = static function () use ($s): string {
        $odp = '';
        while (($l = fgets($s, 512)) !== false) { $odp .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return trim($odp);
    };
    try {
        $powitanie = $czytaj();
        $wynik['powitanie'] = mb_substr(strtok($powitanie, "\n") ?: '', 0, 80);
        if (!str_starts_with($powitanie, '220')) return $wynik + ['ok' => false, 'etap' => 'powitanie'];
        fwrite($s, "EHLO ewa-gabinet.pl\r\n");
        $ehlo = $czytaj();
        if (!str_starts_with($ehlo, '250')) return $wynik + ['ok' => false, 'etap' => 'ehlo'];
        fwrite($s, "STARTTLS\r\n");
        $st = $czytaj();
        if (!str_starts_with($st, '220')) return $wynik + ['ok' => false, 'etap' => 'starttls', 'odp' => mb_substr($st, 0, 80)];
        $tls = @stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if ($tls !== true) return $wynik + ['ok' => false, 'etap' => 'tls', 'blad' => error_get_last()['message'] ?? '?'];
        fwrite($s, "QUIT\r\n");
        return $wynik + ['ok' => true, 'etap' => 'tls ok (bez logowania)'];
    } finally {
        fclose($s);
    }
}

function bon_siec_dns(string $host): array {
    $a = @dns_get_record($host, DNS_A) ?: [];
    $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
    return [
        'A' => array_values(array_map(static fn($r) => $r['ip'] ?? '', $a)),
        'AAAA' => array_values(array_map(static fn($r) => $r['ipv6'] ?? '', $aaaa)),
        'gethostbyname' => gethostbyname($host),
    ];
}

function bon_diagnostyka_sieci(): array {
    @set_time_limit(160);
    $api = 'https://api.mailjet.com/v3/REST/sender';
    $cv = curl_version();
    $wynik = [
        'czas' => bon_teraz()->format('c'),
        'srodowisko' => [
            'php' => PHP_VERSION,
            'curl' => $cv['version'] ?? '?',
            'ssl' => $cv['ssl_version'] ?? '?',
            'ipv6_w_curl' => (bool)(($cv['features'] ?? 0) & CURL_VERSION_IPV6),
            'openssl' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : '?',
        ],
        'dns' => ['api.mailjet.com' => bon_siec_dns('api.mailjet.com'), 'in-v3.mailjet.com' => bon_siec_dns('in-v3.mailjet.com')],
        'tcp_443' => ['api.mailjet.com' => bon_siec_tcp('api.mailjet.com', 443)],
    ];
    $c = curl_init('https://api.ipify.org');
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 8]);
    $ip = curl_exec($c);
    $wynik['ip_wychodzace'] = $ip === false ? 'nie udało się ustalić: ' . curl_error($c) : mb_substr(trim((string)$ip), 0, 45);
    curl_close($c);
    $wynik['mailjet_https'] = [
        'domyslnie' => bon_siec_proby($api),
        'ipv4' => bon_siec_proby($api, [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]),
        'tls_1_2' => bon_siec_proby($api, [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_2]),
        'tls_1_3' => bon_siec_proby($api, [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_3]),
    ];
    if ($wynik['dns']['api.mailjet.com']['AAAA']) $wynik['mailjet_https']['ipv6'] = bon_siec_proby($api, [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V6]);
    else $wynik['mailjet_https']['ipv6'] = 'pominięte: api.mailjet.com nie ma adresu IPv6';
    $wynik['porownanie_https'] = [
        'cart.easy.tools' => bon_siec_proby('https://cart.easy.tools', [], 1),
        'www.google.com' => bon_siec_proby('https://www.google.com', [], 1),
    ];
    $wynik['mailjet_smtp'] = [bon_siec_smtp('in-v3.mailjet.com', 587), bon_siec_smtp('in-v3.mailjet.com', 2525)];
    $wynik['jak_czytac'] = 'HTTP 401 z Mailjeta = połączenie i TLS działają (brak kluczy w teście jest celowy). '
        . 'Błąd przy tcp = blokada po drodze; tcp ok, a TLS zrywa się zawsze = Mailjet odrzuca IP; działa tylko w jednym wariancie = ustawienie cURL.';
    return $wynik;
}
