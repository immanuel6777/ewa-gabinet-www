<?php
// Panel realizacji bonów w gabinecie – logika (etap 3: 2026-11-21, plan/BONY-PLAN-TECHNICZNY.md sekcje 3–7).
// Wygląd panelu: strona Astro /panel-bonow (src/pages/panel-bonow.astro + src/scripts/panel-bonow.ts) woła cienkie
// pliki HTTP: sesja.php (logowanie), historia.php (bon + historia), redeem.php (realizacja), admin.php (tylko admin).
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

/** Sesja z ciasteczka → ['login','imie','rola','csrf'] albo null (brak, wygasła, konto usunięte z konfigu). */
function bon_panel_sesja(array $k, PDO $pdo, string $token, ?DateTimeImmutable $teraz = null): ?array {
    $k = bon_panel_konfig($k);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $pdo->prepare('SELECT login, csrf, expires_at FROM ' . bon_tabela($k, 'voucher_sessions') . ' WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $s = $st->fetch();
    if (!$s || (string)$s['expires_at'] < ($teraz ?? bon_teraz())->format('Y-m-d H:i:s')) return null;
    $konto = $k['panel_konta'][$s['login']] ?? null;
    if (!is_array($konto) || empty($konto['haslo'])) return null; // konto skreślone z konfigu = wylogowane wszędzie
    return ['login' => (string)$s['login'], 'imie' => (string)($konto['imie'] ?? $s['login']), 'rola' => bon_panel_rola($konto), 'csrf' => (string)$s['csrf']];
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
    $st = $pdo->prepare('SELECT amount_pln, doplata_pln, typ, pozycje, notatka, redeemed_at, redeemed_by FROM ' . bon_tabela($k, 'redemptions')
        . ' WHERE voucher_id = ? ORDER BY redeemed_at DESC, id DESC');
    $st->execute([$bon['id']]);
    $historia = array_map(static fn($r) => [
        'kiedy' => (string)$r['redeemed_at'],
        'zdjeto' => bon_zl((int)bon_grosze($r['amount_pln'])),
        'doplata' => bon_zl((int)bon_grosze($r['doplata_pln'])),
        'typ' => (string)$r['typ'],
        'pozycje' => $r['pozycje'] ? (json_decode((string)$r['pozycje'], true) ?: []) : [],
        'notatka' => $r['notatka'],
        'kto' => bon_panel_imie($k, (string)$r['redeemed_by']),
    ], $st->fetchAll());

    $odp = [
        'ok' => true,
        'kod' => $bon['code'],
        'status' => bon_status_efektywny($bon, $teraz),
        'saldo' => bon_zl((int)bon_grosze($bon['balance_pln'])),
        'wartosc' => bon_zl((int)bon_grosze($bon['initial_pln'])),
        'wazny_do' => substr((string)$bon['expires_at'], 0, 10),
        'dla' => $bon['dla'],
        'historia' => $historia,
    ];
    if ($sesja['rola'] === 'admin') {
        $odp['status_bazy'] = $bon['status'];
        $odp['uwagi'] = $bon['status_uwagi'];
        $odp['zamowienie'] = $bon['easytools_order_id'];
        $odp['zaplacono'] = bon_zl((int)bon_grosze($bon['paid_pln']));
        $odp['kupiono'] = (string)$bon['created_at'];
        $st = $pdo->prepare('SELECT akcja, szczegoly, kto, kiedy FROM ' . bon_tabela($k, 'voucher_admin_log') . ' WHERE voucher_id = ? ORDER BY kiedy DESC, id DESC');
        $st->execute([$bon['id']]);
        $odp['dziennik'] = array_map(static fn($r) => $r + ['kto_imie' => bon_panel_imie($k, (string)$r['kto'])], $st->fetchAll());
    }
    return [200, $odp];
}

function bon_panel_imie(array $k, string $login): string {
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

// --- admin (tylko rola admin – Bartek) ---

/**
 * $dane: kod, akcja (waznosc | zatwierdz | zwrot), data (YYYY-MM-DD, przy waznosc), powod (opcjonalnie).
 * Zwraca [kod HTTP, odpowiedź, ?zamówienie do wysłania maila (po zatwierdzeniu wstrzymanego)].
 */
function bon_panel_admin(array $k, PDO $pdo, array $sesja, array $dane, ?DateTimeImmutable $teraz = null): array {
    if ($sesja['rola'] !== 'admin') return [403, ['ok' => false, 'blad' => 'Ta zmiana jest tylko dla administratora.']];
    $teraz ??= bon_teraz();
    $akcja = (string)($dane['akcja'] ?? '');
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
            $pdo->prepare("UPDATE $tabB SET status = 'refunded' WHERE id = ?")->execute([$bon['id']]);
            $opis = $bon['status'] . ' → zwrócony, saldo w chwili zwrotu ' . bon_zl((int)bon_grosze($bon['balance_pln'])) . ' zł';
        } else {
            $pdo->rollBack();
            return [400, ['ok' => false, 'blad' => 'Nieznana akcja.']];
        }
        if ($powod !== '') $opis .= '; ' . $powod;
        $pdo->prepare('INSERT INTO ' . bon_tabela($k, 'voucher_admin_log') . ' (voucher_id, akcja, szczegoly, kto, kiedy) VALUES (?, ?, ?, ?, ?)')
            ->execute([$bon['id'], $akcja, mb_substr($opis, 0, 255), $sesja['login'], $teraz->format('Y-m-d H:i:s')]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [200, ['ok' => true, 'akcja' => $akcja, 'opis' => $opis], $doMaila];
}
