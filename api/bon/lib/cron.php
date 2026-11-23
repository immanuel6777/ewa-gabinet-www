<?php
// Cron OVH (co godzinę – częściej hosting WWW nie pozwala): synchronizacja zamówień bonów z API Easytools + dokończenie wysyłek.
// Siatka bezpieczeństwa dla klientów, którzy zamknęli kartę przed powrotem na /bony/dziekujemy (lib/easytools.php).
// Panel OVH → Hosting → Zadania planowane (Cron): skrypt  astro-www/api/bon/lib/cron.php, język PHP 8.3, co godzinę.
// Tylko z wiersza poleceń (katalog lib/ i tak jest zablokowany w .htaccess produkcji).

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/easytools.php';

$k = null;
try {
    $k = bon_konfig();
    $pdo = bon_pdo($k);
    [$raport, $zadania] = bon_synchronizuj($k, $pdo, 'cron');
    bon_zdarzenie($k, 'synchronizacja', $raport);
    bon_synchronizuj_wyslij($k, $zadania, 'cron');
    echo json_encode($raport, JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    error_log('bony/cron: ' . $e->getMessage());
    if ($k) bon_zdarzenie($k, 'blad', ['plik' => 'cron'] + bon_opis_bledu($e));
    echo 'błąd: ', $e->getMessage(), "\n";
    exit(1);
}
