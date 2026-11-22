<?php
// TYMCZASOWE (Krok 0, plan/PROMPT-CLAUDE-CODE-BONY-PODZIEKOWANIE.md): GET /api/bon/powrot.php?<parametry z kasy Easytools>
// Woła je strona /bony/dziekujemy (fetch z location.search). Zapisuje nazwy parametrów, wartości z ukrytymi danymi osobowymi
// i skróty wartości do dziennika zdarzeń (konfig/bony-zdarzenia/, poza katalogiem strony; podgląd: log.php) jako „powrot-zakupu”.
// Niczego nie tworzy ani nie zmienia w bazie. Limit zapytań na IP jak verify.php. Po ustaleniu parametru id zamówienia – usunąć.

declare(strict_types=1);
require __DIR__ . '/lib/diagnostyka.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { header('Allow: GET'); http_response_code(405); exit; }

try {
    $k = bon_konfig();
    try {
        if (!bon_limit_ok(bon_pdo($k), $k, (string)($_SERVER['REMOTE_ADDR'] ?? ''))) { http_response_code(429); exit; }
    } catch (Throwable) {} // baza niedostępna – zapis do dziennika i tak ma wartość
    bon_zdarzenie($k, 'powrot-zakupu', bon_powrot_dane($_GET, (string)($_SERVER['QUERY_STRING'] ?? '')));
} catch (Throwable $e) {
    error_log('bony/powrot: ' . $e->getMessage());
}
http_response_code(204);
