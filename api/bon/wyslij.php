<?php
// Panel bonów – „Wyślij ponownie” ze zmianą adresu (2026-11-24, TA-11). Admin i konto z uprawnieniem rezygnacji (Bartek, Ewa).
//   POST {kod, email?, potwierdzenie?} – email pusty = ten sam adres; zmiana adresu wymaga „jak potwierdzono kupującego”.
// Nowy adres trafia do wszystkich bonów zamówienia, wysyłka (Mailjet, przy zerwaniu zapas SMTP OVH) od razu, w tym zapytaniu,
// żeby panel pokazał wynik. Nieudana wysyłka: bony zostają z mail_wyslany_at = NULL – dokończy cron.
// Adres nie trafia do dziennika zdarzeń (tylko do historii bonu, której szczegóły widzi admin). Logika: lib/panel.php.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';
require __DIR__ . '/lib/mail.php';

[$k, $pdo, $token] = bon_http_start(['POST']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    $d = bon_http_json_body($sesja);
    [$kod, $odp, $zam] = bon_panel_wyslij_ponownie($k, $pdo, $sesja, $d) + [2 => null];
    if ($zam !== null) {
        @set_time_limit(120);
        $w = bon_wyslij_zamowienie($k, $pdo, $zam);
        bon_zdarzenie($k, 'mail', ['po' => 'ponownie', 'kto' => $sesja['login'], 'zmiana_adresu' => $odp['zmiana_adresu']] + $w);
        $odp['wyslane'] = (int)($w['wyslane'] ?? 0);
        if (!empty($w['blad'])) {
            $odp['ok'] = false;
            $odp['blad'] = 'Nie udało się teraz wysłać maila. Spróbujemy ponownie automatycznie w ciągu godziny.';
        }
    }
    bon_json($kod, $odp);
} catch (Throwable $e) {
    error_log('bony/wyslij: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'wyslij'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
