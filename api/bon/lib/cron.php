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
    // kontrola dostarczenia wysłanych maili po MessageID (lib/mail.php); błąd tu nie przerywa crona
    try {
        $kontrola = bon_mail_kontrola($k, 0, 600, $pdo);
        if ($kontrola['sprawdzono'] > 0) bon_zdarzenie($k, 'mail-kontrola', $kontrola);
    } catch (Throwable $e) {
        bon_zdarzenie($k, 'blad', ['plik' => 'cron (kontrola dostarczenia)'] + bon_opis_bledu($e));
    }
    // P-11: potwierdzanie zwrotów zleconych z panelu (Easytools księguje je asynchronicznie)
    try {
        require_once __DIR__ . '/zwroty.php';
        $zwroty = bon_zwroty_potwierdz($k, $pdo);
        if ($zwroty['sprawdzono'] > 0) bon_zdarzenie($k, 'zwroty-kontrola', $zwroty);
    } catch (Throwable $e) {
        bon_zdarzenie($k, 'blad', ['plik' => 'cron (zwroty)'] + bon_opis_bledu($e));
    }
    // REVIEW nr 3 (A1): zwrot w Easytools spoza panelu przy działającym bonie → alert (bez blokowania)
    try {
        $a1 = bon_zwroty_a1($k, $pdo);
        if ($a1['sprawdzono'] > 0 || $a1['bledy'] > 0) bon_zdarzenie($k, 'a1-kontrola', $a1);
    } catch (Throwable $e) {
        bon_zdarzenie($k, 'blad', ['plik' => 'cron (A1)'] + bon_opis_bledu($e));
    }
    // #218: zamówienie z samymi zwróconymi bonami → odebranie dostępu do produktu w Easytools (po potwierdzeniu zwrotów wyżej)
    try {
        $dostep = bon_zwroty_dostep($k, $pdo);
        if ($dostep['zamowien'] > 0) bon_zdarzenie($k, 'dostep-kontrola', $dostep);
    } catch (Throwable $e) {
        bon_zdarzenie($k, 'blad', ['plik' => 'cron (dostęp Easytools)'] + bon_opis_bledu($e));
    }
    echo json_encode($raport, JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    error_log('bony/cron: ' . $e->getMessage());
    if ($k) bon_zdarzenie($k, 'blad', ['plik' => 'cron'] + bon_opis_bledu($e));
    echo 'błąd: ', $e->getMessage(), "\n";
    exit(1);
}
