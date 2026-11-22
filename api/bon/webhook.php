<?php
// Webhook Easytools po zakupie bonu → bon w MySQL → PDF mailem (plan/BONY-PLAN-TECHNICZNY.md, etapy 1–2: 2026-11-21).
// Adres w Easytools (dokładnie, z www i .php – inaczej 301 i POST przepada): https://www.ewa-gabinet.pl/api/bon/webhook.php
// Logika w lib/bon.php (bon_obsluz_webhook) i lib/mail.php (PDF + Mailjet), tu tylko HTTP. Tryb: webhook_tryb = log | log+bon | bon.
// Kolejność: zapis bonu → odpowiedź 200 dla sklepu → (po zamknięciu połączenia) PDF i mail. Sklep nie czeka na wysyłkę.
// Gdy test z panelu dostaje 403, a w logu nic nie ma – pierwszy podejrzany: zapora aplikacyjna OVH (ModSecurity).

declare(strict_types=1);
require __DIR__ . '/lib/bon.php';
require __DIR__ . '/lib/mail.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    bon_json(405, ['ok' => false, 'blad' => 'tylko POST']);
    exit;
}

$raw = (string)file_get_contents('php://input', false, null, 0, 256 * 1024);

$naglowki = [];
foreach ($_SERVER as $klucz => $wartosc) {
    if (str_starts_with($klucz, 'HTTP_')) $naglowki[strtolower(str_replace('_', '-', substr($klucz, 5)))] = (string)$wartosc;
}
if (isset($_SERVER['CONTENT_TYPE'])) $naglowki['content-type'] = (string)$_SERVER['CONTENT_TYPE'];

$poOdpowiedzi = null;
try {
    $k = bon_konfig();
    [$kod, $odp, $poOdpowiedzi] = bon_obsluz_webhook($k, $raw, $naglowki) + [2 => null];
} catch (Throwable $e) {
    // 500 → sklep może ponowić; idempotencja po order_id nie dopuści do podwójnego bonu
    error_log('bony/webhook: ' . $e->getMessage());
    [$kod, $odp] = [500, ['ok' => false, 'blad' => 'błąd serwera']];
}
bon_json($kod, $odp);

if ($poOdpowiedzi) {
    // zamknij połączenie ze sklepem, PDF i wysyłka dzieją się już „w tle” (OVH: PHP-FPM)
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    ignore_user_abort(true);
    @set_time_limit(120);
    try {
        $poOdpowiedzi();
    } catch (Throwable $e) {
        error_log('bony/webhook (wysyłka): ' . $e->getMessage());
        try { bon_powiadom($k, 'Bon: błąd przy wysyłce PDF', 'Wyjątek: ' . $e->getMessage() . "\nPonowny webhook dokończy wysyłkę."); } catch (Throwable) {}
    }
}
