<?php
// Publiczne sprawdzenie bonu: GET /api/bon/verify.php?kod=EWA-XXXX-XXXX (etap 1: 2026-11-21).
// Zwraca tylko status, saldo, wartość i datę ważności – zero danych kupującego. Limit zapytań na IP (konfig: verify_limit_na_minute).
// Statusy: valid | used | expired (też liczony z daty) | refunded | held (wstrzymany do zatwierdzenia).

declare(strict_types=1);
require __DIR__ . '/lib/bon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    bon_json(405, ['ok' => false, 'blad' => 'tylko GET']);
    exit;
}

try {
    $k = bon_konfig();
    [$kod, $odp] = bon_obsluz_verify($k, (string)($_GET['kod'] ?? $_GET['code'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? ''));
} catch (Throwable $e) {
    error_log('bony/verify: ' . $e->getMessage());
    if (isset($k)) bon_zdarzenie($k, 'blad', ['plik' => 'verify'] + bon_opis_bledu($e));
    [$kod, $odp] = [500, ['ok' => false, 'blad' => 'błąd serwera']];
}
bon_json($kod, $odp);
