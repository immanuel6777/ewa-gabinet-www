<?php
// Bony bez webhooka (2026-11-23): POST /api/bon/synchronizuj.php – uruchamia synchronizację zamówień z API Easytools (lib/easytools.php).
// Nie przyjmuje żadnych danych zamówienia: wszystko, co trafia do bonu, pochodzi z API. Dwa sposoby wywołania:
//   - strona /bony/dziekujemy (bez sesji): odstęp minimalny sync_odstep_s (20 s) dla wszystkich naraz, odpowiedź zawsze {ok:true};
//   - panel /panel-bonow (sesja + nagłówek X-CSRF, przycisk „Pobierz nowe zamówienia”): bez odstępu, odpowiedź z raportem.
// Wysyłka PDF po odpowiedzi (fastcgi_finish_request), tak jak w webhook.php. Wynik do dziennika zdarzeń jako „synchronizacja”.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';
require __DIR__ . '/lib/easytools.php';

[$k, $pdo, $token] = bon_http_start(['POST']);
$zadania = [];
$zrodlo = 'strona';
try {
    $sesja = $token !== '' ? bon_panel_sesja($k, $pdo, $token) : null;
    if ($sesja && hash_equals($sesja['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) $zrodlo = 'panel';
    [$raport, $zadania] = bon_synchronizuj($k, $pdo, $zrodlo);
    if ($zrodlo === 'panel') $raport['kto'] = $sesja['login'];
    if (!isset($raport['pominieto']) || $zrodlo === 'panel') bon_zdarzenie($k, 'synchronizacja', $raport);
    if ($zrodlo === 'panel') {
        bon_json(200, ['ok' => true, 'raport' => bon_synchronizuj_dla_panelu($raport)]);
    } else {
        // strona podziękowania (2026-11-24, TA-10): {"zamowienie": "<ec_order_id>"} → stan i adres, na który poszedł bon.
        // Także gdy synchronizacja była pominięta (odstęp). Adres tylko w odpowiedzi, nigdy w dzienniku.
        $cialo = json_decode((string)file_get_contents('php://input'), true);
        $zam = is_array($cialo) && is_string($cialo['zamowienie'] ?? null) ? strtolower(trim($cialo['zamowienie'])) : '';
        $stan = $zam !== '' ? bon_stan_zamowienia($k, $pdo, $zam, (string)($_SERVER['REMOTE_ADDR'] ?? '')) : null;
        bon_json(200, $stan === null ? ['ok' => true] : ['ok' => true, 'zamowienie' => $stan]);
    }
} catch (Throwable $e) {
    error_log('bony/synchronizuj: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'synchronizuj', 'zrodlo' => $zrodlo] + bon_opis_bledu($e));
    bon_json($zrodlo === 'panel' ? 500 : 200, $zrodlo === 'panel' ? ['ok' => false, 'blad' => 'Nie udało się pobrać zamówień z Easytools.'] : ['ok' => true]);
}

if ($zadania) {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    ignore_user_abort(true);
    @set_time_limit(180);
    bon_synchronizuj_wyslij($k, $zadania, $zrodlo);
}
