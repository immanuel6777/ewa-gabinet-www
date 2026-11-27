<?php
// #259: strażnik sprzedaży – GET /api/bon/straznik.php z nagłówkiem `X-Straznik-Token` (HMAC z log_token, lib/straznik.php →
// bon_straznik_token; w GitHubie sekret BONY_STRAZNIK_TOKEN). Bez tokenu albo ze złym: 404 jak dla nieistniejącej strony.
// Odpowiedź: { straznik: { wlaczony, alarm, powody[], zamowien, bez_bonu, bez_bonu_id[8 znaków], bez_maila, cron_minut, api_blad, sprawdzono } }
// – bez e-maili, kwot i sekretów. Pyta workflow `.github/workflows/straznik.yml` co godzinę 7–22.

declare(strict_types=1);
require __DIR__ . '/lib/straznik.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$nie = static function (): never { http_response_code(404); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') $nie();
try {
    $k = bon_konfig();
} catch (Throwable) {
    $nie();
}
$token = bon_straznik_token($k);
if ($token === null || !hash_equals($token, (string)($_SERVER['HTTP_X_STRAZNIK_TOKEN'] ?? ''))) {
    usleep(300000);
    $nie();
}

try {
    $w = bon_straznik($k, bon_pdo($k));
} catch (Throwable $e) {
    // baza nie odpowiada itp. – też alarm (bonów nie da się utworzyć ani sprawdzić)
    $opis = bon_opis_bledu($e);
    bon_zdarzenie($k, 'blad', ['plik' => 'straznik'] + $opis);
    $w = ['wlaczony' => true, 'alarm' => true, 'powody' => ['strażnik: błąd sprawdzania (' . ($opis['opis'] ?? 'nieznany') . ')']];
}
if (!empty($w['alarm'])) bon_zdarzenie($k, 'straznik-alarm', ['powody' => $w['powody']]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['straznik' => $w + ['sprawdzono' => bon_teraz()->format('c')]],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
