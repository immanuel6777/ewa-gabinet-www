<?php
// Panel bonów – „Pobierz PDF” (2026-11-24, TA-11): GET /api/bon/pdf.php?kod=EWA-XXXX-XXXX z ciasteczkiem sesji panelu.
// Tylko admin i konto z uprawnieniem rezygnacji (Bartek, Ewa): bon działa na okaziciela, więc PDF nie dla każdego konta.
// Ten sam PDF co w mailu (lib/pdf.php), generowany w pamięci; wpis „pdf” w historii bonu (kto, kiedy). Bez zapisu na dysku.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';
require __DIR__ . '/lib/pdf.php';

[$k, $pdo, $token] = bon_http_start(['GET']);
$tekst = static function (int $kod, string $tresc): void {
    http_response_code($kod);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $tresc;
};
try {
    $sesja = bon_panel_sesja($k, $pdo, $token);
    if ($sesja && !empty($sesja['zmiana_hasla'])) $sesja = null; // wpis 13:20: najpierw własne hasło
    if (!$sesja) { $tekst(401, 'Zaloguj się w panelu bonów i spróbuj ponownie.'); exit; }
    [$kod, $wynik] = bon_panel_pdf_bon($k, $pdo, $sesja, (string)($_GET['kod'] ?? ''));
    if ($kod !== 200) { $tekst($kod, (string)$wynik['blad']); exit; }
    $plik = bon_pdf($wynik, $k);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="bon-' . $wynik['code'] . '.pdf"');
    header('Content-Length: ' . strlen($plik));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $plik;
    bon_zdarzenie($k, 'pdf-panel', ['kto' => $sesja['login'], 'kod' => bon_kod_maska((string)$wynik['code'])]);
} catch (Throwable $e) {
    error_log('bony/pdf: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'pdf'] + bon_opis_bledu($e));
    $tekst(500, 'Błąd serwera. Spróbuj za chwilę.');
}
