<?php
// P-12 (2026-11-24): rezygnacja z bonu zgłoszona przez kupującego na stronie /bon. Publiczny (bez sesji), tylko POST JSON.
//   POST {kod, email} → 200 {ok:true, kwota:"120.00"} | 4xx {ok:false, powod: email|termin|saldo|stan|limit}
// Logika i maile: lib/rezygnacja.php. Maile po odpowiedzi (fastcgi_finish_request), żeby strona nie czekała.
// W dzienniku bez adresu e-mail i z zamaskowanym numerem bonu.

declare(strict_types=1);
require __DIR__ . '/lib/rezygnacja.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    bon_json(405, ['ok' => false, 'powod' => 'stan']);
    exit;
}
$k = null;
$ctx = null;
try {
    $k = bon_konfig();
    $typ = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $d = str_starts_with($typ, 'application/json') ? json_decode((string)file_get_contents('php://input', false, null, 0, 8192), true) : null;
    if (!is_array($d)) { bon_json(400, ['ok' => false, 'powod' => 'stan']); exit; }
    $pdo = bon_pdo($k);
    [$http, $odp, $ctx] = bon_rezygnacja_ze_strony($k, $pdo, (string)($d['kod'] ?? ''), (string)($d['email'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    bon_json($http, $odp);
    $kodBonu = bon_normalizuj_kod((string)($d['kod'] ?? ''), (string)$k['kod_prefix']);
    bon_zdarzenie($k, 'rezygnacja-strona', ['http' => $http, 'powod' => $odp['powod'] ?? null, 'kod' => $kodBonu ? bon_kod_maska($kodBonu) : null]);
} catch (Throwable $e) {
    error_log('bony/rezygnacja: ' . $e->getMessage());
    if ($k) bon_zdarzenie($k, 'blad', ['plik' => 'rezygnacja'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'powod' => 'stan']);
    exit;
}

if ($ctx) {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    ignore_user_abort(true);
    @set_time_limit(120);
    try {
        $w = bon_rezygnacja_maile($k, $ctx);
        bon_zdarzenie($k, 'mail', ['po' => 'rezygnacja-strona', 'kod' => bon_kod_maska((string)$ctx['bon']['code']),
            'kupujacy' => $w['kupujacy'] === null ? 'ok' : 'błąd', 'obsluga' => ($w['obsluga'] ?? null) === null ? 'ok' : 'błąd']);
    } catch (Throwable $e) {
        bon_zdarzenie($k, 'blad', ['plik' => 'rezygnacja (maile)'] + bon_opis_bledu($e));
    }
}
