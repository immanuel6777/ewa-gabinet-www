<?php
// Testy kont panelu w bazie (wpis czatu 2026-11-25 13:20): przeniesienie z konfigu, logowanie z bazy, wyłączone konto,
// hasło tymczasowe i wymuszona zmiana, unieważnianie sesji (zmiana/reset hasła, „wyloguj wszędzie”, wyłączenie),
// ostatni admin, uprawnienia ról, historia bez haseł, czas odpowiedzi dla nieznanego loginu. Woła je bon_testy() w lib/testy.php.

declare(strict_types=1);

function bon_testy_konta(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $tabK = bon_tabela($k, 'voucher_accounts');
    $tabL = bon_tabela($k, 'voucher_account_log');
    $tabS = bon_tabela($k, 'voucher_sessions');
    $pdo->exec("DELETE FROM $tabK");
    $pdo->exec("DELETE FROM $tabL");
    $ip = '198.51.100.' . random_int(10, 250);
    $ip2 = '198.51.100.' . random_int(10, 250);
    $kBaza = array_replace($k, ['konta_z_bazy' => true]);
    $kKonf = array_replace($k, ['konta_z_bazy' => false]);
    $loguj = static fn(array $kk, string $l, string $h, bool $zap = false, ?string $adres = null) => bon_panel_zaloguj($kk, $pdo, $l, $h, $zap, $adres ?? $ip) + [2 => null];

    // przy konta_z_bazy = false nic się nie zmienia (konto z konfigu, bez kolumny wersji w INSERT)
    [$kod, $odp] = $loguj($kKonf, 'ewa', 'haslo-ewy-test');
    $t('konta z konfigu (przełącznik wyłączony): logowanie jak dotąd, odpowiedź z konta_z_bazy = false', $kod === 200 && ($odp['konta_z_bazy'] ?? null) === false, json_encode($odp));

    // przeniesienie z konfigu: hashe, nie hasła; idempotentne; nic z hasha w odpowiedzi
    $sAdminKonf = bon_panel_sesja($kKonf, $pdo, (string)$loguj($kKonf, 'bartek', 'haslo-bartka-test')[2]['wartosc']);
    [$kod, $odp] = bon_panel_konta($kKonf, $pdo, $sAdminKonf, ['akcja' => 'przenies'], '');
    [, $odp2] = bon_panel_konta($kKonf, $pdo, $sAdminKonf, ['akcja' => 'przenies'], '');
    $hashEwy = (string)$pdo->query("SELECT haslo_hash FROM $tabK WHERE login = 'ewa'")->fetchColumn();
    $t('przeniesienie: konta z konfigu w bazie (hash ten sam), drugie wywołanie niczego nie dubluje, odpowiedź bez hashy',
        $kod === 200 && in_array('ewa', $odp['przeniesione'] ?? [], true) && in_array('bartek', $odp['przeniesione'] ?? [], true)
        && $hashEwy === $k['panel_konta']['ewa']['haslo'] && in_array('ewa', $odp2['pominiete'] ?? [], true) && !str_contains(json_encode($odp), '$2y$'), json_encode($odp));
    $rezEwy = ($k['panel_konta']['ewa']['rezygnacje'] ?? false) === true; // jak w konfigu (testowym bez, na OVH Ewa ma)
    $t('przeniesienie: uprawnienie rezygnacji i rola admina takie jak w konfigu',
        ((int)$pdo->query("SELECT rezygnacje FROM $tabK WHERE login = 'ewa'")->fetchColumn() === 1) === $rezEwy
        && $pdo->query("SELECT rola FROM $tabK WHERE login = 'bartek'")->fetchColumn() === 'admin');

    // logowanie z bazy; stara sesja (sprzed przełączenia, bez wersji) nieważna
    $staraSesja = (string)$loguj($kKonf, 'ewa', 'haslo-ewy-test')[2]['wartosc'];
    $t('po włączeniu konta_z_bazy sesja sprzed przełączenia jest nieważna (trzeba się zalogować)', bon_panel_sesja($kBaza, $pdo, $staraSesja) === null);
    [$kod, $odp, $c] = $loguj($kBaza, 'ewa', 'haslo-ewy-test', true);
    $sEwa = $c ? bon_panel_sesja($kBaza, $pdo, $c['wartosc']) : null;
    $t('logowanie z bazy: Ewa z hasłem z konfigu, rola i rezygnacje z bazy', $kod === 200 && $sEwa && $sEwa['rezygnacje'] === $rezEwy && $sEwa['rola'] === 'realizacja'
        && ($odp['zmiana_hasla'] ?? null) === false, json_encode($odp));
    [$kod] = $loguj($kBaza, 'ewa', 'zle-haslo-123', false, $ip2);
    $t('logowanie z bazy: złe hasło 401', $kod === 401);

    // admin dodaje konto: hasło tymczasowe raz, wymuszona zmiana; do zmiany sesja nic nie może poza zmianą hasła
    $cAdmin = $loguj($kBaza, 'bartek', 'haslo-bartka-test')[2];
    $sAdmin = bon_panel_sesja($kBaza, $pdo, $cAdmin['wartosc']);
    [$kod, $odp] = bon_panel_konta($kBaza, $pdo, $sAdmin, ['akcja' => 'dodaj', 'login' => 'beata', 'imie' => 'Beata', 'rola' => 'realizacja'], $cAdmin['wartosc']);
    $tymcz = (string)($odp['haslo_tymczasowe'] ?? '');
    $t('dodanie konta: hasło tymczasowe 12 znaków w odpowiedzi, w bazie tylko hash', $kod === 200 && strlen($tymcz) === 12
        && !str_contains((string)$pdo->query("SELECT haslo_hash FROM $tabK WHERE login = 'beata'")->fetchColumn(), $tymcz), json_encode($odp));
    [$kod, $odp, $cB] = $loguj($kBaza, 'beata', $tymcz);
    $sB = $cB ? bon_panel_sesja($kBaza, $pdo, $cB['wartosc']) : null;
    $t('hasło tymczasowe: logowanie OK z zmiana_hasla = true', $kod === 200 && ($odp['zmiana_hasla'] ?? false) === true && $sB && $sB['zmiana_hasla'] === true, json_encode($odp));
    [$kod, $odp] = bon_panel_konta($kBaza, $pdo, $sB, ['akcja' => 'zmien-haslo', 'stare' => $tymcz, 'nowe' => 'krotkie'], $cB['wartosc']);
    $t('zmiana hasła: za krótkie (< 10 znaków) odrzucone', $kod === 400, json_encode($odp));
    [$kod] = bon_panel_konta($kBaza, $pdo, $sB, ['akcja' => 'zmien-haslo', 'stare' => 'nie-to-haslo', 'nowe' => 'nowe-haslo-beaty'], $cB['wartosc']);
    $t('zmiana hasła: złe obecne hasło 403', $kod === 403);
    [$kod, $odp] = bon_panel_konta($kBaza, $pdo, $sB, ['akcja' => 'zmien-haslo', 'stare' => $tymcz, 'nowe' => 'nowe-haslo-beaty'], $cB['wartosc']);
    $sB2 = bon_panel_sesja($kBaza, $pdo, $cB['wartosc']);
    $t('zmiana hasła: OK, ta sama przeglądarka zostaje zalogowana bez wymuszenia', $kod === 200 && $sB2 && $sB2['zmiana_hasla'] === false, json_encode($odp));
    [$kod] = $loguj($kBaza, 'beata', $tymcz, false, $ip2);
    [$kodN] = $loguj($kBaza, 'beata', 'nowe-haslo-beaty');
    $t('po zmianie: stare hasło tymczasowe nie działa, nowe działa', $kod === 401 && $kodN === 200);
    [$kod] = bon_panel_konta($kBaza, $pdo, $sB2, ['akcja' => 'lista'], $cB['wartosc']);
    $t('lista kont: tylko admin (realizacja 403)', $kod === 403);

    // unieważnianie sesji: zapamiętane urządzenie Ewy (90 dni) po resecie hasła przez admina
    [$kod, $odp] = bon_panel_konta($kBaza, $pdo, $sAdmin, ['akcja' => 'haslo-tymczasowe', 'login' => 'ewa'], $cAdmin['wartosc']);
    $t('reset hasła Ewy: zapamiętane urządzenie (90 dni) wylogowane, nowe hasło tymczasowe wymusza zmianę',
        $kod === 200 && bon_panel_sesja($kBaza, $pdo, $c['wartosc']) === null && strlen((string)($odp['haslo_tymczasowe'] ?? '')) === 12, json_encode($odp));
    $cE2 = $loguj($kBaza, 'ewa', (string)$odp['haslo_tymczasowe'])[2];
    bon_panel_konta($kBaza, $pdo, bon_panel_sesja($kBaza, $pdo, $cE2['wartosc']), ['akcja' => 'zmien-haslo', 'stare' => (string)$odp['haslo_tymczasowe'], 'nowe' => 'nowe-haslo-ewy-1'], $cE2['wartosc']);
    $cE3 = $loguj($kBaza, 'ewa', 'nowe-haslo-ewy-1', true)[2];
    bon_panel_konta($kBaza, $pdo, $sAdmin, ['akcja' => 'wyloguj-wszedzie', 'login' => 'ewa'], $cAdmin['wartosc']);
    $t('„Wyloguj ze wszystkich urządzeń”: obie sesje Ewy nieważne, sesja admina działa',
        bon_panel_sesja($kBaza, $pdo, $cE2['wartosc']) === null && bon_panel_sesja($kBaza, $pdo, $cE3['wartosc']) === null && bon_panel_sesja($kBaza, $pdo, $cAdmin['wartosc']) !== null);
    $ileSesji = (int)$pdo->query("SELECT COUNT(*) FROM $tabS WHERE login = 'ewa'")->fetchColumn();
    $t('„Wyloguj wszędzie”: sesje Ewy usunięte z tabeli', $ileSesji === 0, (string)$ileSesji);

    // wyłączenie konta: sesje znikają, logowanie jak nieznany login
    $cB3 = $loguj($kBaza, 'beata', 'nowe-haslo-beaty')[2];
    bon_panel_konta($kBaza, $pdo, $sAdmin, ['akcja' => 'wylacz', 'login' => 'beata'], $cAdmin['wartosc']);
    [$kod, $odp] = $loguj($kBaza, 'beata', 'nowe-haslo-beaty', false, $ip2);
    $t('wyłączone konto: sesja nieważna, logowanie 401 „Zły login albo hasło.”', bon_panel_sesja($kBaza, $pdo, $cB3['wartosc']) === null && $kod === 401
        && ($odp['blad'] ?? '') === 'Zły login albo hasło.', json_encode($odp));
    bon_panel_konta($kBaza, $pdo, $sAdmin, ['akcja' => 'wlacz', 'login' => 'beata'], $cAdmin['wartosc']);
    [$kod] = $loguj($kBaza, 'beata', 'nowe-haslo-beaty');
    $t('włączenie konta: logowanie znów działa', $kod === 200);

    // rola, ostatni admin, admin sam sobie
    [$kod] = bon_panel_konta($kBaza, $pdo, $sAdmin, ['akcja' => 'rola', 'login' => 'beata', 'rola' => 'realizacja', 'rezygnacje' => true], $cAdmin['wartosc']);
    $t('zmiana roli: Beata dostaje rezygnacje', $kod === 200 && (int)$pdo->query("SELECT rezygnacje FROM $tabK WHERE login = 'beata'")->fetchColumn() === 1);
    [$kodS] = bon_panel_konta($kBaza, $pdo, $sAdmin, ['akcja' => 'wylacz', 'login' => 'bartek'], $cAdmin['wartosc']);
    [$kodR] = bon_panel_konta($kBaza, $pdo, $sAdmin, ['akcja' => 'rola', 'login' => 'bartek', 'rola' => 'realizacja'], $cAdmin['wartosc']);
    $t('admin nie wyłącza ani nie degraduje samego siebie', $kodS === 409 && $kodR === 409);
    bon_panel_konta($kBaza, $pdo, $sAdmin, ['akcja' => 'rola', 'login' => 'beata', 'rola' => 'admin'], $cAdmin['wartosc']);
    $cBA = $loguj($kBaza, 'beata', 'nowe-haslo-beaty')[2];
    $sBA = bon_panel_sesja($kBaza, $pdo, $cBA['wartosc']);
    bon_panel_konta($kBaza, $pdo, $sBA, ['akcja' => 'wylacz', 'login' => 'bartek'], $cBA['wartosc']);
    [$kodOst] = bon_panel_konta($kBaza, $pdo, $sAdmin, ['akcja' => 'rola', 'login' => 'beata', 'rola' => 'realizacja'], $cAdmin['wartosc']);
    $t('ostatni aktywny admin: nie da się go zdegradować (drugi admin wyłączony)', $kodOst === 409 || bon_panel_sesja($kBaza, $pdo, $cAdmin['wartosc']) === null);
    bon_panel_konta($kBaza, $pdo, $sBA, ['akcja' => 'wlacz', 'login' => 'bartek'], $cBA['wartosc']);

    // historia: wpisy bez haseł
    $hist = json_encode($pdo->query("SELECT * FROM $tabL")->fetchAll(), JSON_UNESCAPED_UNICODE);
    $t('historia kont: dodanie, zmiana hasła, reset, wyloguj wszędzie, wyłączenie, rola – bez haseł i hashy',
        str_contains($hist, '"dodanie"') && str_contains($hist, '"zmiana-hasla"') && str_contains($hist, '"haslo-tymczasowe"') && str_contains($hist, '"wyloguj-wszedzie"')
        && str_contains($hist, '"wylaczenie"') && str_contains($hist, '"rola"') && !str_contains($hist, $tymcz) && !str_contains($hist, 'nowe-haslo') && !str_contains($hist, '$2y$'), $hist);

    // czas odpowiedzi: nieznany login i wyłączone konto liczą bcrypt jak prawdziwe konto (atrapa hasha); liczniki limitów od zera,
    // bo zablokowane konto (429) odpowiada przed sprawdzeniem hasła i fałszowałoby pomiar
    $tabLim = bon_tabela($k, 'voucher_verify_limit');
    $pdo->exec("DELETE FROM $tabLim");
    $czas = static function (string $l) use ($loguj, $kBaza): float {
        $najlepszy = INF;
        for ($i = 0; $i < 3; $i++) { $s = hrtime(true); $loguj($kBaza, $l, 'zle-haslo-xyz', false, '203.0.113.' . random_int(10, 250)); $najlepszy = min($najlepszy, (hrtime(true) - $s) / 1e6); }
        return $najlepszy;
    };
    $tZnany = $czas('ewa');
    $tNieznany = $czas('ktos-nieznany');
    $t('czas odpowiedzi: nieznany login ≈ znany (bcrypt atrapy, różnica < 50%)', $tNieznany > $tZnany * 0.5 && $tNieznany < $tZnany * 1.5, sprintf('%.1f ms / %.1f ms', $tZnany, $tNieznany));

    // imię z bazy w historii bonów
    bon_panel_sesja($kBaza, $pdo, $cAdmin['wartosc']);
    $t('historia bonów: imię konta z bazy (bon_panel_imie)', bon_panel_imie($kBaza, 'beata') === 'Beata');

    $pdo->exec("DELETE FROM $tabK");
    $pdo->exec("DELETE FROM $tabL");
    $pdo->exec("DELETE FROM $tabLim"); // limity logowań z tych testów nie blokują kolejnych (HTTP sesja.php)
    return $wyniki;
}
