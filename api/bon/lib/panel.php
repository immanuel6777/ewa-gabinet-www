<?php
// Panel realizacji bonów w gabinecie – logika (etap 3: 2026-11-21, plan/BONY-PLAN-TECHNICZNY.md sekcje 3–7).
// Wygląd panelu: strona Astro /panel-bonow (src/pages/panel-bonow.astro + src/scripts/panel-bonow.ts) woła cienkie
// pliki HTTP: sesja.php (logowanie), historia.php (bon + historia), redeem.php (realizacja), admin.php (tylko admin),
// szukaj.php (bon po numerze, e-mailu kupującego albo zamówieniu Easytools) i przedluz.php (przedłużenie o 3 mies.) – krok 4, 2026-11-23.
// Tu same funkcje, bez wypisywania – testowalne na SQLite (tests/php/) i na OVH (test-ovh.php).
//
// Bezpieczeństwo:
//  - konta w konfigu ('panel_konta': login → hash password_hash, imię, rola realizacja|admin), nigdy w repo;
//    hash: php scripts/haslo-panelu-bonow.php,
//  - sesja w bazie (voucher_sessions): w ciasteczku losowy token, w bazie jego SHA-256; przy każdym logowaniu NOWY token
//    (to samo, co daje session_regenerate_id – sesji PHP nie używamy, bo OVH czyści je po kilkudziesięciu minutach,
//    a „zapamiętaj to urządzenie” ma działać 90 dni),
//  - limit nieudanych prób: 5 na 15 min na login+IP i 20 na samo IP,
//  - POST tylko jako JSON z nagłówkiem X-CSRF (token z sesji) i z tej samej domeny (Origin) – lib/panel-http.php,
//  - realizacja: transakcja + SELECT … FOR UPDATE (MySQL) + UPDATE z warunkiem na stare saldo (oba silniki),
//    client_token UNIQUE (podwójne kliknięcie nie odejmuje 2×), ceny liczone z uslugi.json – nigdy z przeglądarki.

declare(strict_types=1);
require_once __DIR__ . '/bon.php';

const BON_PANEL_CIASTKO = 'bon_panel';
// hash nieistniejącego konta: przy nieznanym loginie password_verify i tak liczy (czas odpowiedzi nie zdradza, czy login jest)
const BON_PANEL_HASH_ATRAPA = '$2y$10$uZ0SZVujcy2qBerpKha4rudlFyODKcZusLHeCDFB5VGberB2NEA32';
const BON_PANEL_MAX_ZL = 5000; // górna granica jednej realizacji (literówka „20000” zamiast „200”)

function bon_panel_konfig(array $k): array {
    return $k + [
        'panel_konta' => [],
        'panel_sesja_godzin' => 12,
        'panel_zapamietaj_dni' => 90,
        'panel_limit_login_ip' => 5,
        'panel_limit_ip' => 20,
        'panel_limit_okno_min' => 15,
        'uslugi_plik' => dirname(__DIR__) . '/lib/uslugi.json',
    ];
}

function bon_panel_ip_klucz(array $k, string $co): string {
    return hash('sha256', $co . '|' . ($k['easytools_webhook_sekret'] ?? '') . '|panel');
}

// --- logowanie ---

/**
 * Zwraca [kod HTTP, odpowiedź, ?ciasteczko]. Ciasteczko: ['wartosc' => token, 'wygasa' => unix albo 0 (do zamknięcia przeglądarki)].
 */
function bon_panel_zaloguj(array $k, PDO $pdo, string $login, string $haslo, bool $zapamietaj, string $ip, ?DateTimeImmutable $teraz = null): array {
    $k = bon_panel_konfig($k);
    $teraz ??= bon_teraz();
    $login = strtolower(trim($login));
    $tabProby = bon_tabela($k, 'voucher_login_attempts');
    $kluczLogin = bon_panel_ip_klucz($k, $login . '|' . $ip);
    $kluczIp = bon_panel_ip_klucz($k, $ip);
    $od = time() - (int)$k['panel_limit_okno_min'] * 60;

    $ile = $pdo->prepare("SELECT COUNT(*) FROM $tabProby WHERE klucz = ? AND czas >= ?");
    $ile->execute([$kluczLogin, $od]);
    $nLogin = (int)$ile->fetchColumn();
    $ile->execute([$kluczIp, $od]);
    $nIp = (int)$ile->fetchColumn();
    if ($nLogin >= (int)$k['panel_limit_login_ip'] || $nIp >= (int)$k['panel_limit_ip']) {
        return [429, ['ok' => false, 'blad' => 'Za dużo nieudanych prób. Spróbuj ponownie za ' . (int)$k['panel_limit_okno_min'] . ' minut.']];
    }

    $konto = is_array($k['panel_konta'][$login] ?? null) ? $k['panel_konta'][$login] : null;
    $hash = (string)($konto['haslo'] ?? '');
    $ok = password_verify($haslo, $hash !== '' ? $hash : BON_PANEL_HASH_ATRAPA) && $konto !== null && $hash !== '';
    if (!$ok) {
        $wpis = $pdo->prepare("INSERT INTO $tabProby (klucz, czas) VALUES (?, ?)");
        $wpis->execute([$kluczLogin, time()]);
        $wpis->execute([$kluczIp, time()]);
        if (random_int(1, 50) === 1) $pdo->prepare("DELETE FROM $tabProby WHERE czas < ?")->execute([time() - 86400]);
        return [401, ['ok' => false, 'blad' => 'Zły login albo hasło.']];
    }
    $pdo->prepare("DELETE FROM $tabProby WHERE klucz = ?")->execute([$kluczLogin]);

    $token = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    $wygasa = $zapamietaj ? $teraz->modify('+' . (int)$k['panel_zapamietaj_dni'] . ' days') : $teraz->modify('+' . (int)$k['panel_sesja_godzin'] . ' hours');
    $tabSesje = bon_tabela($k, 'voucher_sessions');
    $pdo->prepare("INSERT INTO $tabSesje (token_hash, login, csrf, created_at, expires_at) VALUES (?, ?, ?, ?, ?)")
        ->execute([hash('sha256', $token), $login, $csrf, $teraz->format('Y-m-d H:i:s'), $wygasa->format('Y-m-d H:i:s')]);
    if (random_int(1, 20) === 1) $pdo->prepare("DELETE FROM $tabSesje WHERE expires_at < ?")->execute([$teraz->format('Y-m-d H:i:s')]);

    return [200, ['ok' => true, 'zalogowany' => true, 'login' => $login, 'imie' => (string)($konto['imie'] ?? $login), 'rola' => bon_panel_rola($konto), 'csrf' => $csrf],
        ['wartosc' => $token, 'wygasa' => $zapamietaj ? $wygasa->getTimestamp() : 0]];
}

function bon_panel_rola(?array $konto): string {
    return ($konto['rola'] ?? '') === 'admin' ? 'admin' : 'realizacja';
}

/**
 * Rejestracja rezygnacji, która przyszła mailem albo na papierze: admin albo konto z 'rezygnacje' => true w konfigu
 * (decyzja 1, 2026-11-23: Ewa tak, Karolina nie). Uprawnienie przy koncie, nie trzecia rola. Cofnięcie rezygnacji,
 * „Oznacz zwrot” i pozostałe zmiany zostają tylko dla admina.
 */
function bon_panel_moze_rezygnacje(?array $konto): bool {
    return bon_panel_rola($konto) === 'admin' || ($konto['rezygnacje'] ?? false) === true;
}

/** Sesja z ciasteczka → ['login','imie','rola','rezygnacje','csrf'] albo null (brak, wygasła, konto usunięte z konfigu). */
function bon_panel_sesja(array $k, PDO $pdo, string $token, ?DateTimeImmutable $teraz = null): ?array {
    $k = bon_panel_konfig($k);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $pdo->prepare('SELECT login, csrf, expires_at FROM ' . bon_tabela($k, 'voucher_sessions') . ' WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $s = $st->fetch();
    if (!$s || (string)$s['expires_at'] < ($teraz ?? bon_teraz())->format('Y-m-d H:i:s')) return null;
    $konto = $k['panel_konta'][$s['login']] ?? null;
    if (!is_array($konto) || empty($konto['haslo'])) return null; // konto skreślone z konfigu = wylogowane wszędzie
    return ['login' => (string)$s['login'], 'imie' => (string)($konto['imie'] ?? $s['login']), 'rola' => bon_panel_rola($konto),
        'rezygnacje' => bon_panel_moze_rezygnacje($konto), 'csrf' => (string)$s['csrf']];
}

function bon_panel_wyloguj(array $k, PDO $pdo, string $token): void {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return;
    $pdo->prepare('DELETE FROM ' . bon_tabela($k, 'voucher_sessions') . ' WHERE token_hash = ?')->execute([hash('sha256', $token)]);
}

// --- usługi (ceny z cennik.yaml, eksport przy buildzie: src/pages/api/bon/lib/uslugi.json.ts) ---

/** id → ['nazwa','cena' (grosze),'cena_do' (grosze|null),'od' (bool)] */
function bon_panel_uslugi(array $k): array {
    $k = bon_panel_konfig($k);
    $plik = (string)$k['uslugi_plik'];
    $d = is_file($plik) ? json_decode((string)file_get_contents($plik), true) : null;
    if (!is_array($d) || !is_array($d['grupy'] ?? null)) throw new RuntimeException('brak listy usług (uslugi.json)');
    $wynik = [];
    foreach ($d['grupy'] as $g) {
        foreach ((array)($g['pozycje'] ?? []) as $p) {
            $cena = bon_grosze($p['cena'] ?? null);
            if (!is_string($p['id'] ?? null) || $cena === null) continue;
            $wynik[$p['id']] = [
                'nazwa' => (string)$p['nazwa'],
                'cena' => $cena,
                'cena_do' => isset($p['cena_do']) ? bon_grosze($p['cena_do']) : null,
                'od' => !empty($p['od']),
            ];
        }
    }
    return $wynik;
}

// --- bon: szczegóły i historia (po zalogowaniu) ---

function bon_panel_znajdz(array $k, PDO $pdo, string $wpisany, bool $doZapisu = false): ?array {
    $kod = bon_normalizuj_kod($wpisany, (string)$k['kod_prefix']);
    if ($kod === null) return null;
    $zablokuj = $doZapisu && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $st = $pdo->prepare('SELECT * FROM ' . bon_tabela($k, 'vouchers') . ' WHERE code = ?' . $zablokuj);
    $st->execute([$kod]);
    return $st->fetch() ?: null;
}

/** [kod HTTP, odpowiedź] – saldo, ważność, historia; admin widzi też powód wstrzymania i dziennik zmian. */
function bon_panel_historia(array $k, PDO $pdo, array $sesja, string $wpisany, ?DateTimeImmutable $teraz = null): array {
    if (bon_normalizuj_kod($wpisany, (string)$k['kod_prefix']) === null) return [400, ['ok' => false, 'blad' => 'Numer bonu ma postać EWA-XXXX-XXXX.']];
    $bon = bon_panel_znajdz($k, $pdo, $wpisany);
    if (!$bon) return [404, ['ok' => false, 'blad' => 'Nie ma bonu o tym numerze.']];
    $st = $pdo->prepare('SELECT id, amount_pln, doplata_pln, typ, pozycje, notatka, redeemed_at, redeemed_by FROM ' . bon_tabela($k, 'redemptions')
        . ' WHERE voucher_id = ? ORDER BY redeemed_at DESC, id DESC');
    $st->execute([$bon['id']]);
    $cofniete = bon_panel_cofniete_realizacje($k, $pdo, (int)$bon['id']);
    $historia = array_map(static fn($r) => [
        'id' => (int)$r['id'],
        'cofnieta' => in_array((int)$r['id'], $cofniete, true), // 2026-11-24: realizacja cofnięta (wiersz zostaje, saldo wróciło)
        'kiedy' => (string)$r['redeemed_at'],
        'zdjeto' => bon_zl((int)bon_grosze($r['amount_pln'])),
        'doplata' => bon_zl((int)bon_grosze($r['doplata_pln'])),
        'typ' => (string)$r['typ'],
        'pozycje' => $r['pozycje'] ? (json_decode((string)$r['pozycje'], true) ?: []) : [],
        'notatka' => $r['notatka'],
        'kto' => bon_panel_imie($k, (string)$r['redeemed_by']),
    ], $st->fetchAll());

    $admin = $sesja['rola'] === 'admin';
    // historia zdarzeń bonu (voucher_admin_log) dla każdej roli; szczegóły (wpisane powody, kwota zapłacona) tylko admin –
    // kasa widzi samą nazwę zdarzenia, a przy przedłużeniu także daty (krok 4, 2026-11-23)
    $st = $pdo->prepare('SELECT akcja, szczegoly, kto, kiedy FROM ' . bon_tabela($k, 'voucher_admin_log') . ' WHERE voucher_id = ? ORDER BY kiedy DESC, id DESC');
    $st->execute([$bon['id']]);
    $zdarzenia = array_map(static fn($r) => [
        'kiedy' => (string)$r['kiedy'],
        'akcja' => (string)$r['akcja'],
        'nazwa' => BON_PANEL_ZDARZENIA[$r['akcja']] ?? (string)$r['akcja'],
        'kto' => (string)$r['kto'],
        'kto_imie' => bon_panel_imie($k, (string)$r['kto']),
        'szczegoly' => $admin || $r['akcja'] === 'przedluzenie' ? $r['szczegoly'] : null,
    ], $st->fetchAll());

    $odp = [
        'ok' => true,
        'kod' => $bon['code'],
        'status' => bon_status_efektywny($bon, $teraz),
        'saldo' => bon_zl((int)bon_grosze($bon['balance_pln'])),
        'wartosc' => bon_zl((int)bon_grosze($bon['initial_pln'])),
        'wazny_do' => substr((string)$bon['expires_at'], 0, 10),
        'dla' => $bon['dla'],
        'przedluzony' => !empty($bon['przedluzony_at']) ? substr((string)$bon['przedluzony_at'], 0, 10) : null,
        'wazny_do_pierwotnie' => !empty($bon['expires_at_pierwotna']) ? substr((string)$bon['expires_at_pierwotna'], 0, 10) : null,
        'przedluzenie' => bon_przedluzenie_okno($bon, $teraz), // null = przycisku „Przedłuż” nie ma
        'rezygnacja' => bon_panel_rezygnacja($k, $pdo, (int)$bon['id']),
        'mail_odbity' => bon_panel_mail_odbity($zdarzenia), // znacznik „mail nie dotarł” (TA-11), zdejmuje go udana ponowna wysyłka
        // cofnięcie ostatniej realizacji (2026-11-24, wpisy 14:10 i 15:00): tylko admin i konto z uprawnieniem rezygnacji (Ewa)
        'cofnij_realizacje' => ($admin || !empty($sesja['rezygnacje'])) ? bon_panel_cofniecie_mozliwe($k, $pdo, $bon, $teraz) : null,
        'historia' => $historia,
        'zdarzenia' => $zdarzenia,
    ];
    if ($admin || !empty($sesja['rezygnacje'])) {
        // P-11: „Zwróć pieniądze” (bon z rezygnacją czekającą na zwrot) i linia „Płatność w Easytools” (API, tylko te konta)
        $r = $odp['rezygnacja'];
        $odp['zwrot'] = $r && $bon['status'] === 'withdrawn' && in_array($r['stan'], ['oczekuje', 'nieudany'], true)
            ? ['kwota' => $r['kwota'], 'zamowienie' => $bon['easytools_order_id'], 'email' => $bon['buyer_email'], 'ponowienie' => $r['stan'] === 'nieudany',
               'transakcja' => !empty($bon['easytools_transaction_id'])]
            : null;
        if (function_exists('bon_panel_platnosc')) $odp['platnosc'] = bon_panel_platnosc($k, $bon);
        // obsługa kupującego (TA-11): obecny adres do potwierdzenia z kupującym, „Wyślij ponownie” i „Pobierz PDF”
        $odp['obsluga'] = ['email' => $bon['buyer_email'], 'wyslij' => $bon['status'] === 'valid', 'pdf' => in_array($bon['status'], ['valid', 'used'], true)];
        // formularz „Zarejestruj rezygnację”: tylko bon ważny w bazie z saldem (bon_panel_zarejestruj_rezygnacje sprawdza to samo)
        $saldo = (int)bon_grosze($bon['balance_pln']);
        $odp['rezygnacja_mozliwa'] = $bon['status'] === 'valid' && $saldo > 0;
        $odp['rezygnacja_do'] = bon_rezygnacja_termin($bon);
        $odp['rezygnacja_kwota'] = bon_zl(min($saldo, (int)bon_grosze($bon['paid_pln'])));
    }
    if ($admin) {
        $odp['status_bazy'] = $bon['status'];
        $odp['uwagi'] = $bon['status_uwagi'];
        $odp['zamowienie'] = $bon['easytools_order_id']; // id zamówienia Easytools (UUID) – do skopiowania
        $odp['email'] = $bon['buyer_email']; // po e-mailu Bartek znajduje zamówienie w panelu Easytools (UUID tam nie wyszukuje)
        $odp['kupiono'] = (string)$bon['created_at'];
        $odp['zaplacono'] = bon_zl((int)bon_grosze($bon['paid_pln']));
    }
    return [200, $odp];
}

const BON_PANEL_ZDARZENIA = [
    'utworzenie' => 'Bon utworzony', 'mail' => 'Bon wysłany mailem', 'dla-od' => 'Uzupełnione „Dla” i „Od”',
    'waznosc' => 'Zmiana daty ważności', 'zatwierdz' => 'Bon zatwierdzony', 'zwrot' => 'Zwrot oznaczony',
    'rezygnacja' => 'Rezygnacja z bonu', 'rezygnacja-cofnieta' => 'Rezygnacja cofnięta', 'przedluzenie' => 'Ważność przedłużona',
    'przypomnienie' => 'Przypomnienie o końcu ważności', 'zwrot-zlecony' => 'Zwrot zlecony', 'zwrot-potwierdzony' => 'Zwrot potwierdzony',
    'zwrot-nieudany' => 'Zwrot nieudany',
    'mail-odbity' => 'Mail z bonem NIE dotarł', 'adres' => 'Zmieniony adres e-mail', 'mail-ponownie' => 'Wysyłka ponowna',
    'pdf' => 'PDF pobrany w panelu', 'realizacja-cofnieta' => 'Realizacja cofnięta', 'numer-potwierdzony' => 'Numer potwierdzony przez pacjenta',
];

// --- cofnięcie ostatniej realizacji (2026-11-24, wpisy 14:10 i 15:00) ---
// Bez migracji: wiersz realizacji zostaje (redemptions.typ to ENUM), cofnięcie = wpis „realizacja-cofnieta” w historii bonu
// z prefiksem „realizacja #ID:” i zwrot kwoty na saldo. Tylko OSTATNIA realizacja bonu, do 24 h, z powodem.
const BON_COFNIECIE_GODZIN = 24;

/** id realizacji cofniętych (z historii bonu). */
function bon_panel_cofniete_realizacje(array $k, PDO $pdo, int $voucherId): array {
    $st = $pdo->prepare('SELECT szczegoly FROM ' . bon_tabela($k, 'voucher_admin_log') . " WHERE voucher_id = ? AND akcja = 'realizacja-cofnieta'");
    $st->execute([$voucherId]);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) if (preg_match('/^realizacja #(\d+):/', (string)$s, $m)) $ids[] = (int)$m[1];
    return $ids;
}

/** Ostatnia realizacja bonu, jeśli można ją cofnąć: ['id','kwota','kiedy','zabiegi'] albo null. */
function bon_panel_cofniecie_mozliwe(array $k, PDO $pdo, array $bon, ?DateTimeImmutable $teraz = null): ?array {
    if (!in_array($bon['status'], ['valid', 'used'], true)) return null; // wstrzymany, zwrócony, z rezygnacją – bez cofania
    $st = $pdo->prepare('SELECT id, amount_pln, pozycje, typ, redeemed_at FROM ' . bon_tabela($k, 'redemptions') . ' WHERE voucher_id = ? ORDER BY redeemed_at DESC, id DESC LIMIT 1');
    $st->execute([$bon['id']]);
    $r = $st->fetch();
    if (!$r || in_array((int)$r['id'], bon_panel_cofniete_realizacje($k, $pdo, (int)$bon['id']), true)) return null;
    $kiedy = new DateTimeImmutable((string)$r['redeemed_at'], new DateTimeZone(BON_STREFA));
    if ($kiedy < ($teraz ?? bon_teraz())->modify('-' . BON_COFNIECIE_GODZIN . ' hours')) return null;
    $nazwy = array_map(static fn($p) => (string)($p['nazwa'] ?? ''), $r['pozycje'] ? (json_decode((string)$r['pozycje'], true) ?: []) : []);
    return ['id' => (int)$r['id'], 'kwota' => bon_zl((int)bon_grosze($r['amount_pln'])), 'kiedy' => (string)$r['redeemed_at'],
        'zabiegi' => $r['typ'] === 'reczna' ? 'kwota ręczna' : implode(', ', array_filter($nazwy))];
}

/** Ostatnia wysyłka odbita: najnowsze z „mail” / „mail-odbity” to odbicie (zdarzenia od najnowszych). Znacznik w panelu (TA-11). */
function bon_panel_mail_odbity(array $zdarzenia): bool {
    foreach ($zdarzenia as $z) {
        if ($z['akcja'] === 'mail-odbity') return true;
        if ($z['akcja'] === 'mail') return false;
    }
    return false;
}

// --- obsługa kupującego (2026-11-24, TA-11): wyślij ponownie, zmiana adresu, PDF. Admin i konto z uprawnieniem rezygnacji
// (Bartek i Ewa, nie Karolina) – bon działa na okaziciela, więc PDF i zmiana adresu tylko dla nich. ---

/**
 * [kod HTTP, odpowiedź, ?id zamówienia do wysyłki]. Zmiana adresu: nowy adres przy WSZYSTKICH bonach zamówienia (wysyłka bierze
 * adres z pierwszego), wymagane „jak potwierdzono kupującego”, wpis w historii ze starym i nowym adresem (szczegóły widzi
 * tylko admin). Potem wszystkie ważne bony zamówienia do ponownej wysyłki (mail_wyslany_at = NULL) – wysyła wywołujący.
 */
function bon_panel_wyslij_ponownie(array $k, PDO $pdo, array $sesja, array $dane, ?DateTimeImmutable $teraz = null): array {
    // P-10 (2026-11-24): wysyłka ponowna NA TEN SAM adres kupującego – każda rola (pacjent bez bonu przy sobie, przycisk przy masce);
    // zmiana adresu nadal tylko Bartek i Ewa
    $uprawniony = $sesja['rola'] === 'admin' || !empty($sesja['rezygnacje']);
    if (!$uprawniony && trim((string)($dane['email'] ?? '')) !== '') return [403, ['ok' => false, 'blad' => 'Zmianę adresu robią Bartek albo Ewa.']];
    $teraz ??= bon_teraz();
    $tabB = bon_tabela($k, 'vouchers');
    $pdo->beginTransaction();
    try {
        $bon = bon_panel_znajdz($k, $pdo, (string)($dane['kod'] ?? ''), true);
        if (!$bon) { $pdo->rollBack(); return [404, ['ok' => false, 'blad' => 'Nie ma bonu o tym numerze.']]; }
        if ($bon['status'] !== 'valid') { $pdo->rollBack(); return [409, ['ok' => false, 'blad' => 'Wysłać ponownie można tylko bon ważny.']]; }
        $zam = (string)$bon['easytools_order_id'];
        $stary = trim((string)$bon['buyer_email']);
        $nowy = trim((string)($dane['email'] ?? ''));
        if ($nowy === '') $nowy = $stary;
        if (mb_strlen($nowy) > 190 || filter_var($nowy, FILTER_VALIDATE_EMAIL) === false) { $pdo->rollBack(); return [400, ['ok' => false, 'blad' => 'Wpisz poprawny adres e-mail.']]; }
        $zmiana = mb_strtolower($nowy) !== mb_strtolower($stary);
        $potw = bon_tekst((string)($dane['potwierdzenie'] ?? ''), 120);
        if ($zmiana && mb_strlen($potw) < 3) { $pdo->rollBack(); return [400, ['ok' => false, 'blad' => 'Przy zmianie adresu wpisz, jak potwierdzono kupującego (np. telefon, numer zamówienia).']]; }
        $st = $pdo->prepare("SELECT id, status FROM $tabB WHERE easytools_order_id = ?");
        $st->execute([$zam]);
        $bonyZam = $st->fetchAll();
        if ($zmiana) {
            $pdo->prepare("UPDATE $tabB SET buyer_email = ? WHERE easytools_order_id = ?")->execute([$nowy, $zam]);
            foreach ($bonyZam as $b) bon_historia($k, $pdo, (int)$b['id'], 'adres', "$stary → $nowy; potwierdzono: $potw", $sesja['login'], $teraz);
        }
        $pdo->prepare("UPDATE $tabB SET mail_wyslany_at = NULL WHERE easytools_order_id = ? AND status = 'valid'")->execute([$zam]);
        $ile = count(array_filter($bonyZam, static fn($b) => $b['status'] === 'valid'));
        bon_historia($k, $pdo, (int)$bon['id'], 'mail-ponownie', "na adres $nowy, bonów w mailu: $ile", $sesja['login'], $teraz);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [200, ['ok' => true, 'adres' => $nowy, 'zmiana_adresu' => $zmiana, 'bonow' => $ile], $zam];
}

/** Bon do pobrania PDF w panelu: [kod HTTP, ?wiersz bonu albo odpowiedź błędu]. Wpis „pdf” w historii (kto, kiedy). */
function bon_panel_pdf_bon(array $k, PDO $pdo, array $sesja, string $wpisany, ?DateTimeImmutable $teraz = null): array {
    if ($sesja['rola'] !== 'admin' && empty($sesja['rezygnacje'])) return [403, ['ok' => false, 'blad' => 'PDF bonu pobierają Bartek albo Ewa.']];
    $bon = bon_panel_znajdz($k, $pdo, $wpisany);
    if (!$bon) return [404, ['ok' => false, 'blad' => 'Nie ma bonu o tym numerze.']];
    if (!in_array($bon['status'], ['valid', 'used'], true)) return [409, ['ok' => false, 'blad' => 'PDF można pobrać tylko dla bonu ważnego albo wykorzystanego.']];
    bon_historia($k, $pdo, (int)$bon['id'], 'pdf', 'PDF pobrany w panelu', $sesja['login'], $teraz);
    return [200, $bon];
}

/** Złożona (nieanulowana) rezygnacja bonu albo null. Przed migracją kroku 1 na OVH (brak tabeli) – null, panel działa dalej. */
function bon_panel_rezygnacja(array $k, PDO $pdo, int $voucherId): ?array {
    try {
        $st = $pdo->prepare('SELECT zlozono_at, potwierdzono_at, kanal, kwota_pln, zwrot_stan FROM ' . bon_tabela($k, 'voucher_withdrawals') . " WHERE voucher_id = ? AND zwrot_stan <> 'anulowana'");
        $st->execute([$voucherId]);
        $w = $st->fetch();
    } catch (PDOException) {
        return null;
    }
    if (!$w) return null;
    $otrzymano = substr((string)$w['potwierdzono_at'], 0, 10);
    // zlozono = wysłanie / złożenie oświadczenia (termin 14 dni), otrzymano = dzień, w którym gabinet je dostał (od niego bon
    // zablokowany i od niego 14 dni na zwrot pieniędzy – decyzja 3, 2026-11-23)
    return ['zlozono' => substr((string)$w['zlozono_at'], 0, 10), 'otrzymano' => $otrzymano,
        'godzina' => $w['kanal'] === 'strona' ? substr((string)$w['zlozono_at'], 11, 5) : null, // P-12: zgłoszona na /bon – z godziną
        'zwrot_do' => (new DateTimeImmutable($otrzymano, new DateTimeZone(BON_STREFA)))->modify('+14 days')->format('Y-m-d'),
        'kanal' => (string)$w['kanal'], 'kwota' => bon_zl((int)bon_grosze($w['kwota_pln'])), 'stan' => (string)$w['zwrot_stan']];
}

// --- wyszukiwanie (krok 4): numer bonu, e-mail kupującego albo id zamówienia Easytools ---
// POST (nie GET): e-mail nie trafia do logów serwera ani historii przeglądarki. Porównanie bez wielkości liter.
const BON_PANEL_SZUKAJ_MAX = 20;

/** [kod HTTP, odpowiedź]: {typ: kod, kod} albo {typ: email|zamowienie, bony: [...], wiecej}. */
function bon_panel_szukaj(array $k, PDO $pdo, string $q, ?DateTimeImmutable $teraz = null, ?array $sesja = null): array {
    $q = trim($q);
    $kod = bon_normalizuj_kod($q, (string)$k['kod_prefix']);
    if ($kod !== null) return [200, ['ok' => true, 'typ' => 'kod', 'kod' => $kod]];
    $tab = bon_tabela($k, 'vouchers');
    if (str_contains($q, '@')) {
        $wartosc = mb_strtolower($q, 'UTF-8');
        if (!filter_var($wartosc, FILTER_VALIDATE_EMAIL)) return [400, ['ok' => false, 'blad' => 'Wpisz cały adres e-mail kupującego.']];
        $typ = 'email';
        $kolumna = 'buyer_email';
    } elseif (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $q)) {
        $wartosc = strtolower($q);
        $typ = 'zamowienie';
        $kolumna = 'easytools_order_id';
    } else {
        return [400, ['ok' => false, 'blad' => 'Wpisz numer bonu (EWA-XXXX-XXXX), e-mail kupującego albo numer zamówienia Easytools.']];
    }
    // Gmail ignoruje kropki w nazwie skrzynki (bartosz.kalwinski = bartoszkalwinski) – szukamy bez kropek (2026-11-24)
    $gmail = $typ === 'email' && preg_match('/@(gmail|googlemail)\.com$/', $wartosc) === 1;
    $warunek = $gmail ? "REPLACE(LOWER(buyer_email), '.', '') = ? AND (LOWER(buyer_email) LIKE '%@gmail.com' OR LOWER(buyer_email) LIKE '%@googlemail.com')"
        : "LOWER($kolumna) = ?";
    $st = $pdo->prepare("SELECT * FROM $tab WHERE $warunek ORDER BY created_at DESC, id DESC LIMIT " . (BON_PANEL_SZUKAJ_MAX + 1));
    $st->execute([$gmail ? str_replace('.', '', $wartosc) : $wartosc]);
    $wiersze = $st->fetchAll();
    $wiersze20 = array_slice($wiersze, 0, BON_PANEL_SZUKAJ_MAX);
    $dodatki = bon_panel_karty_dodatki($k, $pdo, array_map(static fn($b) => (int)$b['id'], $wiersze20));
    $teraz ??= bon_teraz();
    // P-10 (2026-11-24): poza adminem numer bonu znalezionego po e-mailu / zamówieniu jest zamaskowany – zamiast numeru uchwyt
    // związany z sesją; pełny numer dopiero po potwierdzeniu 4 ostatnich znaków przez pacjenta (bon_panel_potwierdz_numer)
    $maskuj = $sesja !== null && $sesja['rola'] !== 'admin';
    $bony = array_map(static function ($b) use ($k, $sesja, $maskuj, $teraz, $dodatki) {
        $status = bon_status_efektywny($b, $teraz);
        $doKonca = (int)$teraz->setTime(0, 0)->diff(new DateTimeImmutable(substr((string)$b['expires_at'], 0, 10), new DateTimeZone(BON_STREFA)))->format('%r%a');
        $d = $dodatki[(int)$b['id']] ?? [];
        return ($maskuj
            ? ['kod' => null, 'kod_maska' => bon_panel_maska((string)$b['code']), 'uchwyt' => bon_panel_uchwyt($k, $sesja, (int)$b['id'])]
            : ['kod' => $b['code']]) + [
            'status' => $status,
            'saldo' => bon_zl((int)bon_grosze($b['balance_pln'])),
            'wartosc' => bon_zl((int)bon_grosze($b['initial_pln'])),
            'wazny_do' => substr((string)$b['expires_at'], 0, 10),
            'kupiono' => substr((string)$b['created_at'], 0, 10),
            // karty wyników (Bartek 2026-11-24): nazwa i kolor bonu, „Dla”, ostatnia realizacja, kończąca się ważność, ostrzeżenia
            'nazwa' => bon_nazwa($k, $b),
            'kolor' => match (bon_rodzaj($k, $b)) { 200 => 'zielen', 300 => 'czerwony', 500 => 'zloty', default => 'granat' },
            'dla' => $b['dla'] ?: null,
            'ostatnia_realizacja' => $d['realizacja'] ?? null,
            'konczy_sie' => $status === 'valid' && $doKonca >= 0 && $doKonca <= BON_PRZEDLUZENIE_OKNO_DNI,
            'mail_odbity' => $d['mail_odbity'] ?? false,
            // grupowanie bonów z jednego zamówienia (koszyk mieszany, ilość > 1) – skrót, nie numer zamówienia
            'grupa' => substr(hash('sha256', (string)$b['easytools_order_id']), 0, 10),
        ];
    }, $wiersze20);
    // kolejność: najpierw bony, którymi można płacić, potem pozostałe; w każdej grupie najnowsze pierwsze (zapytanie już tak sortuje)
    usort($bony, static fn($a, $b) => ($a['status'] === 'valid' ? 0 : 1) <=> ($b['status'] === 'valid' ? 0 : 1));
    return [200, ['ok' => true, 'typ' => $typ, 'bony' => $bony, 'wiecej' => count($wiersze) > BON_PANEL_SZUKAJ_MAX]];
}

/** Dla kart wyników: ostatnia realizacja (data) i znacznik „mail nie dotarł” – jednym zapytaniem na tabelę. */
function bon_panel_karty_dodatki(array $k, PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $miejsca = implode(',', array_fill(0, count($ids), '?'));
    $wynik = [];
    $st = $pdo->prepare('SELECT voucher_id, MAX(redeemed_at) AS ost FROM ' . bon_tabela($k, 'redemptions') . " WHERE voucher_id IN ($miejsca) GROUP BY voucher_id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) $wynik[(int)$r['voucher_id']]['realizacja'] = (string)$r['ost'];
    $st = $pdo->prepare('SELECT voucher_id, akcja FROM ' . bon_tabela($k, 'voucher_admin_log') . " WHERE voucher_id IN ($miejsca) AND akcja IN ('mail', 'mail-odbity') ORDER BY kiedy DESC, id DESC");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) {
        $id = (int)$r['voucher_id'];
        if (!isset($wynik[$id]['mail_odbity'])) $wynik[$id]['mail_odbity'] = $r['akcja'] === 'mail-odbity';
    }
    return $wynik;
}

// --- P-10 (2026-11-24, wpis 14:35): bon znaleziony po e-mailu kupującego albo zamówieniu – numer zamaskowany do potwierdzenia ---
// Numer bonu jest tajny, adres kupującego nie: ktoś znający adres mógłby przyjść „z bonem”. Poza adminem wyszukiwanie po e-mailu /
// zamówieniu nie zwraca numeru, tylko uchwyt (id bonu + HMAC z csrf sesji – ważny tylko w tej sesji). Bez pełnego numeru nie ma
// realizacji (redeem.php wymaga numeru), PDF-u ani zmian. Pełny numer: po wpisaniu 4 ostatnich znaków podanych przez pacjenta.
const BON_P10_PROB_NA_GODZINE = 5;

function bon_panel_maska(string $kod): string {
    return explode('-', $kod)[0] . '-••••-••••';
}

function bon_panel_uchwyt(array $k, array $sesja, int $id): string {
    return $id . '.' . substr(hash_hmac('sha256', 'uchwyt|' . $id, (string)$sesja['csrf'] . '|' . (string)($k['easytools_webhook_sekret'] ?? '')), 0, 24);
}

/** Bon z uchwytu tej sesji albo null (zły, z innej sesji, nieistniejący bon). */
function bon_panel_z_uchwytu(array $k, PDO $pdo, array $sesja, string $uchwyt): ?array {
    if (!preg_match('/^(\d{1,10})\.[a-f0-9]{24}$/', $uchwyt, $m) || !hash_equals(bon_panel_uchwyt($k, $sesja, (int)$m[1]), $uchwyt)) return null;
    $st = $pdo->prepare('SELECT * FROM ' . bon_tabela($k, 'vouchers') . ' WHERE id = ?');
    $st->execute([(int)$m[1]]);
    return $st->fetch() ?: null;
}

/**
 * Bon otwarty z wyników wyszukiwania (uchwyt). Admin dostaje pełny bon jak po numerze. Pozostali: te same dane (saldo, ważność,
 * stan, historia), ale numer zamaskowany i bez czynności, które wymagają numeru albo go ujawniają (realizacja, PDF, przedłużenie,
 * cofnięcia, rezygnacja); zostaje „Wyślij bon ponownie na adres kupującego” (bez zmiany adresu).
 */
function bon_panel_historia_uchwyt(array $k, PDO $pdo, array $sesja, string $uchwyt, ?DateTimeImmutable $teraz = null): array {
    $bon = bon_panel_z_uchwytu($k, $pdo, $sesja, $uchwyt);
    if (!$bon) return [404, ['ok' => false, 'blad' => 'Nie udało się otworzyć bonu – wyszukaj go jeszcze raz.']];
    [$kod, $odp] = bon_panel_historia($k, $pdo, $sesja, (string)$bon['code'], $teraz);
    if ($kod !== 200 || $sesja['rola'] === 'admin') return [$kod, $odp];
    return [200, array_replace($odp, [
        'kod' => null, 'kod_maska' => bon_panel_maska((string)$bon['code']), 'zamaskowany' => true, 'uchwyt' => $uchwyt,
        'przedluzenie' => null, 'cofnij_realizacje' => null, 'rezygnacja_mozliwa' => false,
        'obsluga' => ['email' => null, 'wyslij' => $bon['status'] === 'valid', 'pdf' => false],
    ])];
}

/** Potwierdzenie numeru przez pacjenta: 4 ostatnie znaki. [kod HTTP, odpowiedź] – po sukcesie pełny numer. Limit 5 prób na bon na godzinę. */
function bon_panel_potwierdz_numer(array $k, PDO $pdo, array $sesja, string $uchwyt, string $koncowka, ?DateTimeImmutable $teraz = null): array {
    $bon = bon_panel_z_uchwytu($k, $pdo, $sesja, $uchwyt);
    if (!$bon) return [404, ['ok' => false, 'blad' => 'Nie udało się otworzyć bonu – wyszukaj go jeszcze raz.']];
    if (!bon_limit_ok($pdo, $k, 'bon|' . $bon['id'], 'p10', BON_P10_PROB_NA_GODZINE, 3600)) {
        return [429, ['ok' => false, 'blad' => 'Za dużo prób dla tego bonu. Spróbuj za godzinę albo wyślij bon ponownie na adres kupującego.']];
    }
    $wpisane = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $koncowka) ?? '');
    $prawdziwe = substr(str_replace('-', '', (string)$bon['code']), -4);
    if (strlen($wpisane) !== 4 || !hash_equals($prawdziwe, $wpisane)) {
        return [403, ['ok' => false, 'blad' => 'To nie ten numer. Poproś pacjenta o bon albo wyślij go ponownie na adres kupującego.']];
    }
    bon_historia($k, $pdo, (int)$bon['id'], 'numer-potwierdzony', 'numer potwierdzony przez pacjenta (4 ostatnie znaki)', $sesja['login'], $teraz);
    return [200, ['ok' => true, 'kod' => $bon['code']]];
}

// --- przedłużenie o 3 miesiące (krok 4): każda rola panelu; reguły w bon_przedluz (lib/bon.php) ---

function bon_panel_przedluz(array $k, PDO $pdo, array $sesja, string $wpisany, ?DateTimeImmutable $teraz = null): array {
    if (bon_normalizuj_kod($wpisany, (string)$k['kod_prefix']) === null) return [400, ['ok' => false, 'blad' => 'Numer bonu ma postać EWA-XXXX-XXXX.']];
    $pdo->beginTransaction();
    try {
        $bon = bon_panel_znajdz($k, $pdo, $wpisany, true);
        if (!$bon) { $pdo->rollBack(); return [404, ['ok' => false, 'blad' => 'Nie ma bonu o tym numerze.']]; }
        $wynik = bon_przedluz($k, $pdo, $bon, $sesja['login'], $teraz);
        if ($wynik[0] === 200) $pdo->commit(); else $pdo->rollBack();
        return $wynik;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function bon_panel_imie(array $k, string $login): string {
    if ($login === 'klient') return 'Klient (strona)';
    if ($login === 'system') return 'System';
    $konto = $k['panel_konta'][$login] ?? null;
    return is_array($konto) && !empty($konto['imie']) ? (string)$konto['imie'] : $login;
}

// --- realizacja ---

/**
 * $dane: kod, pozycje [{id, cena?}], kwota_reczna ("123,50" | ""), notatka, client_token.
 * Zwraca [kod HTTP, odpowiedź]. $poOdczycie – tylko testy: wołane po odczycie bonu, przed zapisem (symulacja drugiego urządzenia).
 */
function bon_panel_realizuj(array $k, PDO $pdo, array $sesja, array $dane, ?DateTimeImmutable $teraz = null, ?callable $poOdczycie = null): array {
    $teraz ??= bon_teraz();
    $token = (string)($dane['client_token'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) return [400, ['ok' => false, 'blad' => 'Brak identyfikatora realizacji – odśwież stronę.']];
    if (bon_normalizuj_kod((string)($dane['kod'] ?? ''), (string)$k['kod_prefix']) === null) return [400, ['ok' => false, 'blad' => 'Numer bonu ma postać EWA-XXXX-XXXX.']];

    // ten sam token = ta sama realizacja (podwójne kliknięcie, ponowione żądanie po zerwanym połączeniu)
    $powtorka = bon_panel_po_tokenie($k, $pdo, $token, (string)$dane['kod']);
    if ($powtorka) return $powtorka;

    // --- suma z cennika (ceny z przeglądarki tylko w dozwolonym zakresie pozycji „od” / „od–do”) ---
    $uslugi = bon_panel_uslugi($k);
    $pozycje = [];
    $suma = 0;
    foreach ((array)($dane['pozycje'] ?? []) as $p) {
        $id = is_array($p) ? (string)($p['id'] ?? '') : '';
        $u = $uslugi[$id] ?? null;
        if (!$u) return [400, ['ok' => false, 'blad' => 'Nieznany zabieg na liście – odśwież stronę.']];
        $cena = $u['cena'];
        if ($u['od'] || $u['cena_do'] !== null) {
            $surowa = $p['cena'] ?? null;
            if (is_string($surowa)) $surowa = trim($surowa);
            $podana = null;
            if ($surowa !== null && $surowa !== '') {
                // wpisana cena, której nie da się odczytać → błąd, nie cicha zamiana na cenę z cennika (podgląd w panelu pokazał inną kwotę)
                $podana = is_int($surowa) || is_float($surowa) || is_string($surowa) ? bon_grosze($surowa) : null;
                if ($podana === null) return [400, ['ok' => false, 'blad' => $u['nazwa'] . ': cena w złotych, np. 250 albo 250,50.']];
            }
            if ($podana !== null) {
                $max = $u['cena_do'] ?? BON_PANEL_MAX_ZL * 100;
                if ($podana < $u['cena'] || $podana > $max) {
                    return [400, ['ok' => false, 'blad' => $u['nazwa'] . ': cena musi być od ' . bon_zl($u['cena']) . ($u['cena_do'] !== null ? ' do ' . bon_zl($u['cena_do']) : '') . ' zł.']];
                }
                $cena = $podana;
            }
        }
        $pozycje[] = ['id' => $id, 'nazwa' => $u['nazwa'], 'cena_pln' => bon_zl($cena)];
        $suma += $cena;
    }
    $reczna = trim((string)($dane['kwota_reczna'] ?? ''));
    if ($reczna !== '') {
        $g = bon_grosze($reczna);
        if ($g === null || $g <= 0) return [400, ['ok' => false, 'blad' => 'Kwota ręczna: wpisz liczbę, np. 120 albo 120,50.']];
        $pozycje[] = ['id' => 'reczna', 'nazwa' => 'Kwota ręczna', 'cena_pln' => bon_zl($g)];
        $suma += $g;
    }
    if ($suma <= 0) return [400, ['ok' => false, 'blad' => 'Wybierz zabieg albo wpisz kwotę.']];
    if ($suma > BON_PANEL_MAX_ZL * 100) return [400, ['ok' => false, 'blad' => 'Suma ponad ' . BON_PANEL_MAX_ZL . ' zł – sprawdź kwoty.']];
    $typ = count($pozycje) === 1 && $pozycje[0]['id'] === 'reczna' ? 'reczna' : 'zabieg';
    $notatka = bon_tekst((string)($dane['notatka'] ?? ''), 255);

    $tabB = bon_tabela($k, 'vouchers');
    $tabR = bon_tabela($k, 'redemptions');
    try {
        $pdo->beginTransaction();
        $bon = bon_panel_znajdz($k, $pdo, (string)$dane['kod'], true);
        if (!$bon) { $pdo->rollBack(); return [404, ['ok' => false, 'blad' => 'Nie ma bonu o tym numerze.']]; }
        $status = bon_status_efektywny($bon, $teraz);
        if ($status !== 'valid') {
            $pdo->rollBack();
            return [409, ['ok' => false, 'status' => $status, 'blad' => bon_panel_powod_odmowy($status)]];
        }
        if ($poOdczycie) $poOdczycie($pdo, $bon);

        $saldo = (int)bon_grosze($bon['balance_pln']);
        $zdjeto = min($suma, $saldo);
        $doplata = $suma - $zdjeto;
        $nowe = $saldo - $zdjeto;
        $nowyStatus = $nowe === 0 ? 'used' : 'valid';
        // warunek na stare saldo i status: gdyby ktoś zmienił bon między odczytem a zapisem – 0 wierszy, nic nie schodzi
        $up = $pdo->prepare("UPDATE $tabB SET balance_pln = :nowe, status = :st, used_at = :u WHERE id = :id AND balance_pln = :stare AND status = 'valid'");
        $up->execute([
            ':nowe' => bon_zl($nowe), ':st' => $nowyStatus, ':u' => $nowe === 0 ? $teraz->format('Y-m-d H:i:s') : null,
            ':id' => $bon['id'], ':stare' => bon_zl($saldo),
        ]);
        if ($up->rowCount() !== 1) {
            $pdo->rollBack();
            return [409, ['ok' => false, 'blad' => 'Bon zmienił się w trakcie (inne urządzenie?). Otwórz go ponownie i sprawdź saldo.']];
        }
        $pdo->prepare("INSERT INTO $tabR (voucher_id, amount_pln, doplata_pln, typ, pozycje, notatka, redeemed_at, redeemed_by, client_token)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$bon['id'], bon_zl($zdjeto), bon_zl($doplata), $typ, json_encode($pozycje, JSON_UNESCAPED_UNICODE),
                $notatka === '' ? null : $notatka, $teraz->format('Y-m-d H:i:s'), $sesja['login'], $token]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // ten sam token z drugiego żądania w tej samej chwili – zwracamy wynik tamtego
        if (($e->errorInfo[0] ?? '') === '23000') {
            $powtorka = bon_panel_po_tokenie($k, $pdo, $token, (string)$dane['kod']);
            if ($powtorka) return $powtorka;
        }
        throw $e;
    }

    return [200, [
        'ok' => true,
        'kod' => $bon['code'],
        'do_kasy' => bon_zl($suma),       // wartość zabiegów do nabicia na kasę fiskalną (panel paragonu nie wystawia)
        'zdjeto' => bon_zl($zdjeto),
        'doplata' => bon_zl($doplata),
        'saldo_przed' => bon_zl($saldo),
        'saldo' => bon_zl($nowe),
        'status' => $nowyStatus,
        'pozycje' => $pozycje,
    ]];
}

function bon_panel_powod_odmowy(string $status): string {
    return [
        'used' => 'Saldo bonu jest wykorzystane.',
        'expired' => 'Bon stracił ważność.',
        'refunded' => 'Bon został zwrócony – nie można go realizować.',
        'held' => 'Bon czeka na zatwierdzenie (sprawdza Bartek). Na razie nie realizujemy.',
        'withdrawn' => 'Kupujący zrezygnował z bonu (czeka na zwrot pieniędzy) – nie można go realizować.',
    ][$status] ?? 'Tego bonu nie można teraz realizować.';
}

/** Realizacja o tym tokenie już jest → [200, jej wynik z „powtorzona”] albo [409] przy innym bonie; brak → null. */
function bon_panel_po_tokenie(array $k, PDO $pdo, string $token, string $wpisanyKod): ?array {
    $st = $pdo->prepare('SELECT r.amount_pln, r.doplata_pln, r.pozycje, v.code, v.balance_pln, v.status FROM ' . bon_tabela($k, 'redemptions') . ' r JOIN '
        . bon_tabela($k, 'vouchers') . ' v ON v.id = r.voucher_id WHERE r.client_token = ?');
    $st->execute([$token]);
    $r = $st->fetch();
    if (!$r) return null;
    if ($r['code'] !== bon_normalizuj_kod($wpisanyKod, (string)$k['kod_prefix'])) return [409, ['ok' => false, 'blad' => 'Identyfikator realizacji użyty przy innym bonie – odśwież stronę.']];
    $zdjeto = (int)bon_grosze($r['amount_pln']);
    $doplata = (int)bon_grosze($r['doplata_pln']);
    return [200, ['ok' => true, 'powtorzona' => true, 'kod' => $r['code'], 'do_kasy' => bon_zl($zdjeto + $doplata), 'zdjeto' => bon_zl($zdjeto),
        'doplata' => bon_zl($doplata), 'saldo' => bon_zl((int)bon_grosze($r['balance_pln'])), 'status' => $r['status'],
        'pozycje' => json_decode((string)$r['pozycje'], true) ?: []]];
}

// --- admin (rola admin – Bartek; rejestracja rezygnacji także konto z 'rezygnacje' => true – Ewa) ---

/**
 * $dane: kod, akcja (waznosc | zatwierdz | zwrot | rezygnacja | cofnij-rezygnacje), data (YYYY-MM-DD: przy waznosc nowa data,
 * przy rezygnacja dzień wysłania / złożenia oświadczenia), otrzymano (YYYY-MM-DD, przy rezygnacja; brak = dziś),
 * kanal (mail | gabinet, przy rezygnacja), powod (opcjonalnie; wymagany przy cofnięciu i przy rezygnacji po terminie).
 * Zwraca [kod HTTP, odpowiedź, ?zamówienie do wysłania maila (po zatwierdzeniu wstrzymanego)].
 */
function bon_panel_admin(array $k, PDO $pdo, array $sesja, array $dane, ?DateTimeImmutable $teraz = null): array {
    $akcja = (string)($dane['akcja'] ?? '');
    // rezygnacja i cofnięcie ostatniej realizacji: także konto z uprawnieniem rezygnacji (Ewa); reszta tylko admin
    // P-11: „Oznacz zwrot” także Ewa – ale tylko na bonie z rezygnacją (zwrot zrobiony w Easytools), sprawdzane niżej
    $wolno = $sesja['rola'] === 'admin' || (in_array($akcja, ['rezygnacja', 'cofnij-realizacje', 'zwrot'], true) && !empty($sesja['rezygnacje']));
    if (!$wolno) return [403, ['ok' => false, 'blad' => 'Ta zmiana jest tylko dla administratora.']];
    $teraz ??= bon_teraz();
    $powod = bon_tekst((string)($dane['powod'] ?? ''), 150);
    $tabB = bon_tabela($k, 'vouchers');
    $doMaila = null;

    $pdo->beginTransaction();
    try {
        $bon = bon_panel_znajdz($k, $pdo, (string)($dane['kod'] ?? ''), true);
        if (!$bon) { $pdo->rollBack(); return [404, ['ok' => false, 'blad' => 'Nie ma bonu o tym numerze.']]; }

        if ($akcja === 'waznosc') {
            $data = (string)($dane['data'] ?? '');
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data, new DateTimeZone(BON_STREFA));
            if (!$d || $d->format('Y-m-d') !== $data) { $pdo->rollBack(); return [400, ['ok' => false, 'blad' => 'Data w formacie RRRR-MM-DD.']]; }
            if ($data < $teraz->format('Y-m-d')) { $pdo->rollBack(); return [400, ['ok' => false, 'blad' => 'Nowa data nie może być w przeszłości.']]; }
            $stara = substr((string)$bon['expires_at'], 0, 10);
            // przedłużenie wygasłego bonu z saldem: status w bazie zostaje valid (wygasły liczony z daty), więc znów działa
            $pdo->prepare("UPDATE $tabB SET expires_at = ? WHERE id = ?")->execute([$data, $bon['id']]);
            $opis = "$stara → $data";
        } elseif ($akcja === 'zatwierdz') {
            if ($bon['status'] !== 'held') { $pdo->rollBack(); return [409, ['ok' => false, 'blad' => 'Zatwierdzić można tylko bon wstrzymany.']]; }
            $pdo->prepare("UPDATE $tabB SET status = 'valid' WHERE id = ? AND status = 'held'")->execute([$bon['id']]);
            $opis = 'wstrzymany → ważny' . ($bon['status_uwagi'] ? ' (było: ' . mb_substr((string)$bon['status_uwagi'], 0, 120) . ')' : '');
            $doMaila = (string)$bon['easytools_order_id'];
        } elseif ($akcja === 'zwrot') {
            if ($bon['status'] === 'refunded') { $pdo->rollBack(); return [409, ['ok' => false, 'blad' => 'Bon jest już oznaczony jako zwrócony.']]; }
            if ($sesja['rola'] !== 'admin' && $bon['status'] !== 'withdrawn') { $pdo->rollBack(); return [403, ['ok' => false, 'blad' => 'Oznaczyć zwrot bonu bez rezygnacji może tylko Bartek.']]; }
            $pdo->prepare("UPDATE $tabB SET status = 'refunded' WHERE id = ?")->execute([$bon['id']]);
            // do zwrotu = mniejsza z dwóch: saldo albo wpłata (wpis 16:47: bon 200 zł kupiony za 8 zł → 8 zł, nie 200 zł)
            $saldoZ = (int)bon_grosze($bon['balance_pln']);
            $opis = $bon['status'] . ' → zwrócony, saldo w chwili zwrotu ' . bon_zl($saldoZ) . ' zł, do zwrotu '
                . bon_zl(min($saldoZ, (int)bon_grosze($bon['paid_pln']))) . ' zł (mniejsza z: saldo, wpłata)';
            if ($bon['status'] === 'withdrawn') {
                // pieniądze oddane ręcznie w Easytools: oczekująca rezygnacja → 'zlecony' z zwrot_ref = 'recznie', żeby lista
                // oczekujących w kroku 3 nie zleciła drugiego zwrotu przez API (krok 3 nie odpytuje transakcji dla 'recznie')
                $pdo->prepare('UPDATE ' . bon_tabela($k, 'voucher_withdrawals') . " SET zwrot_stan = 'zlecony', zwrot_zlecono_at = ?, zwrot_kwota_pln = kwota_pln, zwrot_ref = 'recznie'
                               WHERE voucher_id = ? AND zwrot_stan = 'oczekuje'")->execute([$teraz->format('Y-m-d H:i:s'), $bon['id']]);
                $opis .= '; rezygnacja zamknięta (zwrot ręczny w Easytools)';
            }
        } elseif ($akcja === 'rezygnacja') {
            [$blad, $opis] = bon_panel_zarejestruj_rezygnacje($k, $pdo, $sesja, $bon, $dane, $powod, $teraz);
            if ($blad) { $pdo->rollBack(); return $blad; }
            $powod = ''; // już w opisie
        } elseif ($akcja === 'cofnij-rezygnacje') {
            // pomyłka (rezygnacja na nie tym bonie) albo klient się wycofał – tylko zanim zlecono zwrot (krok 1, 2026-11-23).
            // Wiersz rezygnacji zostaje ze stanem 'anulowana' (ponowna rezygnacja w terminie nadpisze ten sam wiersz).
            if ($powod === '') { $pdo->rollBack(); return [400, ['ok' => false, 'blad' => 'Wpisz powód cofnięcia (pomyłka, klient się wycofał).']]; }
            $tabW = bon_tabela($k, 'voucher_withdrawals');
            $w = $pdo->prepare("SELECT zwrot_stan FROM $tabW WHERE voucher_id = ?");
            $w->execute([$bon['id']]);
            $stan = $w->fetchColumn();
            if ($bon['status'] !== 'withdrawn' || $stan === false) { $pdo->rollBack(); return [409, ['ok' => false, 'blad' => 'Ten bon nie ma złożonej rezygnacji.']]; }
            if ($stan !== 'oczekuje') { $pdo->rollBack(); return [409, ['ok' => false, 'blad' => 'Zwrot został już zlecony – rezygnacji nie można cofnąć.']]; }
            $pdo->prepare("UPDATE $tabW SET zwrot_stan = 'anulowana' WHERE voucher_id = ? AND zwrot_stan = 'oczekuje'")->execute([$bon['id']]);
            $pdo->prepare("UPDATE $tabB SET status = 'valid' WHERE id = ? AND status = 'withdrawn'")->execute([$bon['id']]);
            $akcja = 'rezygnacja-cofnieta';
            $opis = 'rezygnacja cofnięta, bon znów ważny';
        } elseif ($akcja === 'cofnij-realizacje') {
            // pomyłka przy realizacji (zła kwota, zły bon) – tylko ostatnia realizacja, do 24 h; wiersz realizacji zostaje
            if ($powod === '') { $pdo->rollBack(); return [400, ['ok' => false, 'blad' => 'Wpisz powód cofnięcia (np. pomyłka w kwocie, nie ten bon).']]; }
            $r = bon_panel_cofniecie_mozliwe($k, $pdo, $bon, $teraz);
            if (!$r || (int)($dane['realizacja'] ?? 0) !== $r['id']) {
                $pdo->rollBack();
                return [409, ['ok' => false, 'blad' => 'Cofnąć można tylko ostatnią realizację, do ' . BON_COFNIECIE_GODZIN . ' godzin od niej. Odśwież bon i sprawdź historię.']];
            }
            $stare = (int)bon_grosze($bon['balance_pln']);
            $nowe = $stare + (int)bon_grosze($r['kwota']);
            $pdo->prepare("UPDATE $tabB SET balance_pln = ?, status = CASE WHEN status = 'used' THEN 'valid' ELSE status END WHERE id = ?")
                ->execute([bon_zl($nowe), $bon['id']]);
            $akcja = 'realizacja-cofnieta';
            // prefiks „realizacja #ID:” czyta bon_panel_cofniete_realizacje – nie zmieniać
            $opis = 'realizacja #' . $r['id'] . ': −' . $r['kwota'] . ' zł (' . ($r['zabiegi'] ?: 'zabieg') . ', ' . substr($r['kiedy'], 0, 16) . '), saldo '
                . bon_zl($stare) . ' → ' . bon_zl($nowe) . ' zł';
        } else {
            $pdo->rollBack();
            return [400, ['ok' => false, 'blad' => 'Nieznana akcja.']];
        }
        if ($powod !== '') $opis .= '; ' . $powod;
        bon_historia($k, $pdo, (int)$bon['id'], $akcja, $opis, $sesja['login'], $teraz);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [200, ['ok' => true, 'akcja' => $akcja, 'opis' => $opis], $doMaila];
}

/**
 * Rezygnacja, która przyszła mailem albo na papierze (krok 4, 2026-11-23). Wołana w transakcji bon_panel_admin, bon FOR UPDATE.
 * Dwie daty (decyzja 3, 2026-11-23): „data” = wysłanie maila / listu albo złożenie w gabinecie – tylko do oceny, czy klient
 * zmieścił się w 14 dniach (art. 30 ustawy o prawach konsumenta: wystarczy wysłać przed upływem terminu); „otrzymano” = dzień,
 * w którym gabinet dostał oświadczenie (domyślnie dziś) – od niego bon jest zablokowany i liczy się 14 dni na zwrot pieniędzy
 * (projekt § 14 ust. 18b i 19). Zapis: zlozono_at = wysłanie, potwierdzono_at = otrzymanie.
 * Tylko z bonu ważnego w bazie (held / used / refunded / withdrawn odmawia: cofnięcie rezygnacji przywraca 'valid', więc
 * rezygnacja z bonu wstrzymanego ominęłaby zatwierdzenie). Po terminie: tylko z wpisanym powodem (decyzja gabinetu), z adnotacją.
 * Kwota zwrotu = min(saldo teraz, zapłacono). Wiersz 'anulowana' (wcześniej cofnięta) nadpisywany – jeden wiersz na bon.
 * Zwraca [?[kod, odpowiedź] błędu, opis do historii].
 */
function bon_panel_zarejestruj_rezygnacje(array $k, PDO $pdo, array $sesja, array $bon, array $dane, string $powod, DateTimeImmutable $teraz): array {
    $blad = static fn(int $kod, string $tekst) => [[$kod, ['ok' => false, 'blad' => $tekst]], ''];
    $kanal = (string)($dane['kanal'] ?? '');
    if (!in_array($kanal, ['mail', 'gabinet'], true)) return $blad(400, 'Wybierz, jak przyszła rezygnacja: mailem albo na papierze w gabinecie.');
    $dzis = $teraz->format('Y-m-d');
    $data = (string)($dane['data'] ?? '');
    $otrzymano = (string)($dane['otrzymano'] ?? '');
    if ($otrzymano === '') $otrzymano = $dzis;
    foreach ([[$data, 'wysłania'], [$otrzymano, 'otrzymania']] as [$x, $nazwa]) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $x, new DateTimeZone(BON_STREFA));
        if (!$d || $d->format('Y-m-d') !== $x) return $blad(400, "Data $nazwa w formacie RRRR-MM-DD.");
        if ($x > $dzis) return $blad(400, "Data $nazwa nie może być z przyszłości.");
        if ($x < substr((string)$bon['created_at'], 0, 10)) return $blad(400, "Data $nazwa jest sprzed zakupu bonu.");
    }
    if ($otrzymano < $data) return $blad(400, 'Data otrzymania nie może być wcześniejsza niż data wysłania.');

    if ($bon['status'] !== 'valid') {
        return $blad(409, [
            'withdrawn' => 'Rezygnacja z tego bonu jest już zarejestrowana.',
            'held' => 'Bon jest wstrzymany (czeka na zatwierdzenie) – najpierw go zatwierdź albo oznacz zwrot.',
        ][$bon['status']] ?? bon_panel_powod_odmowy((string)$bon['status']));
    }
    $saldo = (int)bon_grosze($bon['balance_pln']);
    if ($saldo <= 0) return $blad(409, 'Saldo bonu jest wykorzystane – nie ma czego zwracać.');
    $wTerminie = bon_rezygnacja_w_terminie($bon, $data);
    $termin = bon_rezygnacja_termin($bon);
    if (!$wTerminie && $powod === '') {
        return $blad(400, 'Oświadczenie po terminie (14 dni minęło ' . $termin . '). Jeśli mimo to przyjmujesz rezygnację, wpisz powód.');
    }
    $kwota = min($saldo, (int)bon_grosze($bon['paid_pln']));

    $tabW = bon_tabela($k, 'voucher_withdrawals');
    $st = $pdo->prepare("SELECT zwrot_stan FROM $tabW WHERE voucher_id = ?");
    $st->execute([$bon['id']]);
    $stan = $st->fetchColumn();
    if ($stan !== false && $stan !== 'anulowana') return $blad(409, 'Rezygnacja z tego bonu jest już zarejestrowana.');
    // otrzymane dziś: dokładna chwila wpisu; wcześniej: sam dzień (godziny nie znamy)
    $potwierdzono = $otrzymano === $dzis ? $teraz->format('Y-m-d H:i:s') : $otrzymano . ' 00:00:00';
    $wartosci = [$data . ' 00:00:00', $potwierdzono, $kanal, $sesja['login'], bon_zl($saldo), bon_zl($kwota), $bon['id']];
    if ($stan === false) {
        $pdo->prepare("INSERT INTO $tabW (zlozono_at, potwierdzono_at, kanal, kto, saldo_pln, kwota_pln, voucher_id) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute($wartosci);
    } else {
        $pdo->prepare("UPDATE $tabW SET zlozono_at = ?, potwierdzono_at = ?, kanal = ?, kto = ?, saldo_pln = ?, kwota_pln = ?, zwrot_stan = 'oczekuje',
                       zwrot_zlecono_at = NULL, zwrot_kwota_pln = NULL, zwrot_potwierdzono_at = NULL, zwrot_ref = NULL, zwrot_blad = NULL
                       WHERE voucher_id = ? AND zwrot_stan = 'anulowana'")->execute($wartosci);
    }
    $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . " SET status = 'withdrawn' WHERE id = ? AND status = 'valid'")->execute([$bon['id']]);
    $zwrotDo = (new DateTimeImmutable($otrzymano, new DateTimeZone(BON_STREFA)))->modify('+14 days')->format('Y-m-d');
    $opis = ($kanal === 'mail' ? 'mailem' : 'na papierze w gabinecie') . ", wysłane $data, otrzymane $otrzymano, saldo " . bon_zl($saldo)
        . ' zł, do zwrotu ' . bon_zl($kwota) . " zł (pieniądze do $zwrotDo)"
        . ($wTerminie ? '' : " – PO TERMINIE (do $termin)") . ($powod !== '' ? '; ' . $powod : '');
    return [null, $opis];
}
