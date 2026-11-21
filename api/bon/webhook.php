<?php
// Webhook Easytools po zakupie bonu → bon w MySQL (plan/BONY-PLAN-TECHNICZNY.md, etap 1: 2026-11-21).
// Adres w Easytools (dokładnie, z www i .php – inaczej 301 i POST przepada): https://www.ewa-gabinet.pl/api/bon/webhook.php
// Logika w lib/bon.php (bon_obsluz_webhook), tu tylko HTTP. Tryb pracy w konfigu: webhook_tryb = log | log+bon | bon.
// Gdy test z panelu dostaje 403, a w logu nic nie ma – pierwszy podejrzany: zapora aplikacyjna OVH (ModSecurity).

declare(strict_types=1);
require __DIR__ . '/lib/bon.php';

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

try {
    $k = bon_konfig();
    [$kod, $odp] = bon_obsluz_webhook($k, $raw, $naglowki);
} catch (Throwable $e) {
    // 500 → sklep może ponowić; idempotencja po order_id nie dopuści do podwójnego bonu
    error_log('bony/webhook: ' . $e->getMessage());
    [$kod, $odp] = [500, ['ok' => false, 'blad' => 'błąd serwera']];
}
bon_json($kod, $odp);
