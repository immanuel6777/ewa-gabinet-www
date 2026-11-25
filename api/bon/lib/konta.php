<?php
// Konta panelu w bazie (wpis czatu 2026-11-25 13:20, polecenie Bartka 13:18; P-23 w PANEL-POMYSLY.md).
// Za przełącznikiem `konta_z_bazy` (lib/ustawienia.php, domyślnie false): przy false logowanie jak dotąd z `panel_konta` w konfigu,
// nowe tabele i kolumna sesji NIE są dotykane (kod można wrzucić przed migracją). Przy true:
//  - konta z tabeli voucher_accounts (hash bcrypt – ten sam format co w konfigu, więc przeniesienie kopiuje hashe, nie hasła);
//  - sesja pamięta wersję hasła konta (voucher_sessions.wersja_hasla): zmiana/reset hasła, „Wyloguj ze wszystkich urządzeń”
//    i wyłączenie konta podbijają wersję i kasują sesje → także zapamiętane urządzenia (90 dni) są wylogowane (SEC F6 / P-18);
//  - hasło tymczasowe (pokazane raz adminowi) = wymuszona zmiana przy następnym logowaniu; do tej chwili sesja może tylko zmienić
//    hasło (bon_http_wymagaj_sesji zwraca 403 {zmiana_hasla: true});
//  - każda zmiana konta → voucher_account_log (kto, kiedy, co) – nigdy hasła ani hashe.
// Migracja: lib/migracje/konta.sql. Plan wdrożenia: plan/BONY-KONTA-WDROZENIE.md.

declare(strict_types=1);

const BON_KONTA_MIN_HASLO = 10;
const BON_KONTA_ALFABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'; // bez 0/O/1/l/I – hasło przepisywane z ekranu

function bon_konta_z_bazy(array $k): bool {
    return ($k['konta_z_bazy'] ?? false) === true;
}

/**
 * Konto po loginie, w jednym kształcie dla konfigu i bazy:
 * ['haslo' => hash, 'imie', 'rola' => realizacja|admin, 'rezygnacje' => bool, 'aktywne' => bool, 'wersja' => ?int, 'zmiana_hasla' => bool]
 * albo null (brak konta).
 */
function bon_panel_konto(array $k, PDO $pdo, string $login): ?array {
    if (!bon_konta_z_bazy($k)) {
        $c = $k['panel_konta'][$login] ?? null;
        if (!is_array($c) || empty($c['haslo'])) return null;
        return ['haslo' => (string)$c['haslo'], 'imie' => (string)($c['imie'] ?? $login), 'rola' => ($c['rola'] ?? '') === 'admin' ? 'admin' : 'realizacja',
            'rezygnacje' => ($c['rezygnacje'] ?? false) === true, 'aktywne' => true, 'wersja' => null, 'zmiana_hasla' => false];
    }
    $st = $pdo->prepare('SELECT * FROM ' . bon_tabela($k, 'voucher_accounts') . ' WHERE login = ?');
    $st->execute([$login]);
    $r = $st->fetch();
    if (!$r) return null;
    return ['haslo' => (string)$r['haslo_hash'], 'imie' => (string)$r['imie'], 'rola' => $r['rola'] === 'admin' ? 'admin' : 'realizacja',
        'rezygnacje' => (int)$r['rezygnacje'] === 1, 'aktywne' => (int)$r['aktywne'] === 1, 'wersja' => (int)$r['wersja_hasla'],
        'zmiana_hasla' => (int)$r['zmiana_wymuszona'] === 1];
}

/** Imiona wszystkich kont z bazy (historia bonów pokazuje imię zamiast loginu) – raz na żądanie. */
function bon_konta_imiona(array $k, PDO $pdo): array {
    $imiona = [];
    foreach ($pdo->query('SELECT login, imie FROM ' . bon_tabela($k, 'voucher_accounts'))->fetchAll() as $r) $imiona[(string)$r['login']] = (string)$r['imie'];
    return $imiona; // kilka wierszy – bez pamięci podręcznej (konto dodane w trakcie żądania od razu z imieniem)
}

function bon_konta_log(array $k, PDO $pdo, string $login, string $akcja, string $szczegoly, string $kto): void {
    $pdo->prepare('INSERT INTO ' . bon_tabela($k, 'voucher_account_log') . ' (login, akcja, szczegoly, kto, kiedy) VALUES (?, ?, ?, ?, ?)')
        ->execute([$login, $akcja, mb_substr($szczegoly, 0, 255), $kto, bon_teraz()->format('Y-m-d H:i:s')]);
}

/** Podbija wersję hasła i kasuje sesje konta (poza $zostaw – bieżąca sesja przy własnej zmianie hasła, z nową wersją). */
function bon_konta_uniewaznij_sesje(array $k, PDO $pdo, string $login, ?string $zostawToken = null): int {
    $tabK = bon_tabela($k, 'voucher_accounts');
    $tabS = bon_tabela($k, 'voucher_sessions');
    $pdo->prepare("UPDATE $tabK SET wersja_hasla = wersja_hasla + 1, zmieniono_at = ? WHERE login = ?")->execute([bon_teraz()->format('Y-m-d H:i:s'), $login]);
    $st = $pdo->prepare("SELECT wersja_hasla FROM $tabK WHERE login = ?");
    $st->execute([$login]);
    $wersja = (int)$st->fetchColumn();
    if ($zostawToken !== null && preg_match('/^[a-f0-9]{64}$/', $zostawToken)) {
        $h = hash('sha256', $zostawToken);
        $pdo->prepare("DELETE FROM $tabS WHERE login = ? AND token_hash <> ?")->execute([$login, $h]);
        $pdo->prepare("UPDATE $tabS SET wersja_hasla = ? WHERE token_hash = ?")->execute([$wersja, $h]);
    } else {
        $pdo->prepare("DELETE FROM $tabS WHERE login = ?")->execute([$login]);
    }
    return $wersja;
}

function bon_konta_haslo_tymczasowe(int $dl = 12): string {
    $a = BON_KONTA_ALFABET;
    $h = '';
    for ($i = 0; $i < $dl; $i++) $h .= $a[random_int(0, strlen($a) - 1)];
    return $h;
}

/**
 * Blokada na zmianę uprawnień i wyłączanie kont (MySQL GET_LOCK; SQLite w testach – jeden proces): dwa żądania naraz
 * („admin A degraduje B, B degraduje A”) nie przejdą obu kontroli ostatniego admina (security 25.11, pkt 2).
 */
function bon_konta_blokada(array $k, PDO $pdo, bool $zaloz): bool {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return true;
    $nazwa = 'bon_konta_' . md5(bon_tabela($k, 'voucher_accounts'));
    if (!$zaloz) { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$nazwa]); return true; }
    $st = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $st->execute([$nazwa]);
    return (int)$st->fetchColumn() === 1;
}

/** Ile aktywnych adminów (poza $bez) – ostatniego admina nie da się wyłączyć ani zdegradować. */
function bon_konta_adminow(array $k, PDO $pdo, ?string $bez = null): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . bon_tabela($k, 'voucher_accounts') . " WHERE rola = 'admin' AND aktywne = 1 AND login <> ?");
    $st->execute([(string)$bez]);
    return (int)$st->fetchColumn();
}

/** Lista kont dla admina – bez hashy. */
function bon_konta_lista(array $k, PDO $pdo): array {
    $tabS = bon_tabela($k, 'voucher_sessions');
    $teraz = bon_teraz()->format('Y-m-d H:i:s');
    $rows = $pdo->query('SELECT login, imie, rola, rezygnacje, aktywne, zmiana_wymuszona, utworzono_at, haslo_zmienione_at FROM '
        . bon_tabela($k, 'voucher_accounts') . " ORDER BY aktywne DESC, CASE WHEN rola = 'admin' THEN 0 ELSE 1 END, login")->fetchAll();
    $sesje = $pdo->prepare("SELECT COUNT(*) FROM $tabS WHERE login = ? AND expires_at >= ?");
    return array_map(static function ($r) use ($sesje, $teraz) {
        $sesje->execute([$r['login'], $teraz]);
        return ['login' => (string)$r['login'], 'imie' => (string)$r['imie'], 'rola' => (string)$r['rola'], 'rezygnacje' => (int)$r['rezygnacje'] === 1,
            'aktywne' => (int)$r['aktywne'] === 1, 'zmiana_hasla' => (int)$r['zmiana_wymuszona'] === 1, 'utworzono' => (string)$r['utworzono_at'],
            'haslo_zmienione' => $r['haslo_zmienione_at'] !== null ? (string)$r['haslo_zmienione_at'] : null, 'sesji' => (int)$sesje->fetchColumn()];
    }, $rows);
}

/**
 * Przeniesienie kont z `panel_konta` (konfig) do bazy – jednorazowe, idempotentne (istniejące loginy pomija). Kopiuje hashe,
 * nie hasła; nic z nich nie trafia do odpowiedzi ani dziennika. Działa przy `konta_z_bazy` = false (zanim przełącznik się zmieni).
 */
function bon_konta_przenies(array $k, PDO $pdo, string $kto): array {
    $tab = bon_tabela($k, 'voucher_accounts');
    $teraz = bon_teraz()->format('Y-m-d H:i:s');
    $jest = $pdo->prepare("SELECT 1 FROM $tab WHERE login = ?");
    $dodaj = $pdo->prepare("INSERT INTO $tab (login, imie, rola, rezygnacje, haslo_hash, zmiana_wymuszona, aktywne, wersja_hasla, utworzono_at, zmieniono_at, haslo_zmienione_at)
                            VALUES (?, ?, ?, ?, ?, 0, 1, 1, ?, ?, NULL)");
    $wynik = ['przeniesione' => [], 'pominiete' => []];
    foreach ((array)($k['panel_konta'] ?? []) as $login => $c) {
        $login = strtolower(trim((string)$login));
        if (!is_array($c) || empty($c['haslo']) || !preg_match('/^[a-z0-9._-]{2,40}$/', $login)) { $wynik['pominiete'][] = $login; continue; }
        $jest->execute([$login]);
        if ($jest->fetchColumn()) { $wynik['pominiete'][] = $login; continue; }
        $rola = ($c['rola'] ?? '') === 'admin' ? 'admin' : 'realizacja';
        $dodaj->execute([$login, mb_substr((string)($c['imie'] ?? $login), 0, 60), $rola, ($c['rezygnacje'] ?? false) === true ? 1 : 0, (string)$c['haslo'], $teraz, $teraz]);
        bon_konta_log($k, $pdo, $login, 'przeniesienie', "z konfigu, rola $rola", $kto);
        $wynik['przeniesione'][] = $login;
    }
    return $wynik;
}

/**
 * Akcje kont (konta.php). Admin: lista, dodaj, haslo-tymczasowe, rola, wylacz, wlacz, wyloguj-wszedzie, przenies.
 * Każdy: zmien-haslo (stare + nowe, min. 10 znaków). Zwraca [kod HTTP, odpowiedź].
 */
function bon_panel_konta(array $k, PDO $pdo, array $sesja, array $d, string $token): array {
    $akcja = (string)($d['akcja'] ?? '');
    $ja = (string)$sesja['login'];
    $admin = $sesja['rola'] === 'admin';
    $tab = bon_tabela($k, 'voucher_accounts');
    $teraz = bon_teraz()->format('Y-m-d H:i:s');
    $blad = static fn(int $kod, string $tekst) => [$kod, ['ok' => false, 'blad' => $tekst]];

    if ($akcja === 'zmien-haslo') {
        if (!bon_konta_z_bazy($k)) return $blad(409, 'Hasła są jeszcze w konfiguracji – zmienia je Bartek.');
        // security 25.11, pkt 1: limit prób jak przy logowaniu – przejęta sesja nie zgadnie obecnego hasła bez końca.
        // Próba rezerwowana atomowo przed sprawdzeniem (bon_limit_ok), udana zmiana zeruje licznik.
        $okno = (int)($k['panel_limit_okno_min'] ?? 15) * 60;
        if (!bon_limit_ok($pdo, $k, 'haslo|' . $ja, 'panel-haslo', 5, $okno)) {
            return $blad(429, 'Za dużo prób zmiany hasła. Spróbuj ponownie za ' . (int)($k['panel_limit_okno_min'] ?? 15) . ' minut.');
        }
        $konto = bon_panel_konto($k, $pdo, $ja);
        $nowe = (string)($d['nowe'] ?? '');
        if (!$konto || !password_verify((string)($d['stare'] ?? ''), $konto['haslo'])) return $blad(403, 'Obecne hasło się nie zgadza.');
        if (mb_strlen($nowe) < BON_KONTA_MIN_HASLO) return $blad(400, 'Nowe hasło musi mieć co najmniej ' . BON_KONTA_MIN_HASLO . ' znaków.');
        if (password_verify($nowe, $konto['haslo'])) return $blad(400, 'Nowe hasło musi być inne niż obecne.');
        $pdo->prepare("UPDATE $tab SET haslo_hash = ?, zmiana_wymuszona = 0, haslo_zmienione_at = ? WHERE login = ?")->execute([password_hash($nowe, PASSWORD_DEFAULT), $teraz, $ja]);
        bon_konta_uniewaznij_sesje($k, $pdo, $ja, $token); // inne urządzenia wylogowane, to zostaje zalogowane
        bon_limit_oddaj($pdo, $k, 'haslo|' . $ja, 'panel-haslo', $okno, true);
        bon_konta_log($k, $pdo, $ja, 'zmiana-hasla', 'własne hasło zmienione, inne urządzenia wylogowane', $ja);
        return [200, ['ok' => true, 'opis' => 'Hasło zmienione. Inne urządzenia zostały wylogowane.']];
    }

    if (!$admin) return $blad(403, 'Kontami zarządza administrator.');

    if ($akcja === 'przenies') {
        $w = bon_konta_przenies($k, $pdo, $ja);
        return [200, ['ok' => true, 'przeniesione' => $w['przeniesione'], 'pominiete' => $w['pominiete']]];
    }
    if (!bon_konta_z_bazy($k)) return $blad(409, 'Konta są jeszcze w konfiguracji (konta_z_bazy = false).');
    if ($akcja === 'lista') {
        $hist = $pdo->query('SELECT login, akcja, szczegoly, kto, kiedy FROM ' . bon_tabela($k, 'voucher_account_log') . ' ORDER BY kiedy DESC, id DESC LIMIT 30')->fetchAll();
        return [200, ['ok' => true, 'konta' => bon_konta_lista($k, $pdo), 'historia' => $hist]];
    }

    $login = strtolower(trim((string)($d['login'] ?? '')));
    if (!preg_match('/^[a-z0-9._-]{2,40}$/', $login)) return $blad(400, 'Login: 2–40 znaków, małe litery, cyfry, kropka, myślnik.');
    $cel = bon_panel_konto($k, $pdo, $login);
    $rola = ($d['rola'] ?? '') === 'admin' ? 'admin' : 'realizacja';
    $rezygnacje = !empty($d['rezygnacje']);
    // Bartek 25.11: Kasa = realizacja, Biuro = realizacja + rezygnacje, Administrator = admin (nazwy w historii kont)
    $opisRoli = static fn(string $r, bool $rez) => $r === 'admin' ? 'Administrator' : ($rez ? 'Biuro' : 'Kasa');

    if ($akcja === 'dodaj') {
        if ($cel) return $blad(409, 'Konto o tym loginie już jest.');
        $imie = bon_tekst((string)($d['imie'] ?? ''), 60);
        if ($imie === '') return $blad(400, 'Podaj imię (widać je w historii bonów).');
        $haslo = bon_konta_haslo_tymczasowe();
        $pdo->prepare("INSERT INTO $tab (login, imie, rola, rezygnacje, haslo_hash, zmiana_wymuszona, aktywne, wersja_hasla, utworzono_at, zmieniono_at, haslo_zmienione_at)
                       VALUES (?, ?, ?, ?, ?, 1, 1, 1, ?, ?, NULL)")->execute([$login, $imie, $rola, $rezygnacje ? 1 : 0, password_hash($haslo, PASSWORD_DEFAULT), $teraz, $teraz]);
        bon_konta_log($k, $pdo, $login, 'dodanie', "rola: {$opisRoli($rola, $rezygnacje)}, hasło tymczasowe", $ja);
        return [200, ['ok' => true, 'login' => $login, 'haslo_tymczasowe' => $haslo]];
    }
    if (!$cel) return $blad(404, 'Nie ma takiego konta.');

    if ($akcja === 'haslo-tymczasowe') {
        $haslo = bon_konta_haslo_tymczasowe();
        $pdo->prepare("UPDATE $tab SET haslo_hash = ?, zmiana_wymuszona = 1, haslo_zmienione_at = ? WHERE login = ?")->execute([password_hash($haslo, PASSWORD_DEFAULT), $teraz, $login]);
        bon_konta_uniewaznij_sesje($k, $pdo, $login, $login === $ja ? $token : null);
        bon_konta_log($k, $pdo, $login, 'haslo-tymczasowe', 'nowe hasło tymczasowe, wszystkie urządzenia wylogowane', $ja);
        return [200, ['ok' => true, 'login' => $login, 'haslo_tymczasowe' => $haslo]];
    }
    if ($akcja === 'rola') {
        if ($login === $ja) return $blad(409, 'Nie zmieniasz roli własnego konta – poproś drugiego admina.');
        if (!bon_konta_blokada($k, $pdo, true)) return $blad(409, 'Inna zmiana kont jest w toku – spróbuj za chwilę.');
        try {
            $cel = bon_panel_konto($k, $pdo, $login) ?? $cel; // świeży stan pod blokadą
            if ($cel['rola'] === 'admin' && $rola !== 'admin' && bon_konta_adminow($k, $pdo, $login) === 0) return $blad(409, 'To ostatni admin – panel zostałby bez administratora.');
            $pdo->prepare("UPDATE $tab SET rola = ?, rezygnacje = ?, zmieniono_at = ? WHERE login = ?")->execute([$rola, $rezygnacje ? 1 : 0, $teraz, $login]);
        } finally {
            bon_konta_blokada($k, $pdo, false);
        }
        bon_konta_log($k, $pdo, $login, 'rola', "{$opisRoli($cel['rola'], $cel['rezygnacje'])} → {$opisRoli($rola, $rezygnacje)}", $ja);
        return [200, ['ok' => true]];
    }
    if ($akcja === 'wylacz' || $akcja === 'wlacz') {
        $wl = $akcja === 'wlacz';
        if (!$wl && $login === $ja) return $blad(409, 'Nie wyłączasz własnego konta.');
        if (!bon_konta_blokada($k, $pdo, true)) return $blad(409, 'Inna zmiana kont jest w toku – spróbuj za chwilę.');
        try {
            $cel = bon_panel_konto($k, $pdo, $login) ?? $cel; // świeży stan pod blokadą
            if (!$wl && $cel['rola'] === 'admin' && bon_konta_adminow($k, $pdo, $login) === 0) return $blad(409, 'To ostatni admin – panel zostałby bez administratora.');
            $pdo->prepare("UPDATE $tab SET aktywne = ?, zmieniono_at = ? WHERE login = ?")->execute([$wl ? 1 : 0, $teraz, $login]);
        } finally {
            bon_konta_blokada($k, $pdo, false);
        }
        if (!$wl) bon_konta_uniewaznij_sesje($k, $pdo, $login);
        bon_konta_log($k, $pdo, $login, $wl ? 'wlaczenie' : 'wylaczenie', $wl ? 'konto włączone' : 'konto wyłączone, wszystkie urządzenia wylogowane', $ja);
        return [200, ['ok' => true]];
    }
    if ($akcja === 'wyloguj-wszedzie') {
        bon_konta_uniewaznij_sesje($k, $pdo, $login, $login === $ja ? $token : null);
        bon_konta_log($k, $pdo, $login, 'wyloguj-wszedzie', $login === $ja ? 'inne urządzenia wylogowane' : 'wszystkie urządzenia wylogowane', $ja);
        return [200, ['ok' => true]];
    }
    return $blad(400, 'Nieznana akcja.');
}
