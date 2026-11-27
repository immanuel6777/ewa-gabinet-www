<?php
// Raport dla Ewy i Bartka (wpisy czatu 2026-11-26 01:49 i 01:51): „Zrealizowano z bonów” za okres – kwota, którą Bartek
// wypłaca Ewie: suma realizacji po cofnięciach + lista realizacji z okresu (karta #210, Backend 2 26.11). Reszta raportu
// z 01:49 (sprzedane, zwroty, wygasłe, saldo, CSV) dojdzie później jako NOWE pola tej samej odpowiedzi (umowa z Frontem 1,
// 26.11 – istniejące pola się nie zmieniają).
// Uprawnienia na serwerze: admin i konto z uprawnieniem rezygnacji (poziom Gabinet); poziom Bon → 403.
// Daty Europe/Warsaw (redeemed_at zapisywane w BON_STREFA), granice dnia włącznie.

declare(strict_types=1);
require_once __DIR__ . '/panel.php';

const BON_RAPORT_OKRESY = ['dzis', 'tydzien', 'miesiac', 'rok'];
const BON_RAPORT_MAKS_DNI = 5 * 366 + 1; // zakres od–do najwyżej ok. 5 lat
const BON_RAPORT_LISTA_MAKS = 500;       // wierszy listy realizacji w jednej odpowiedzi (karta #210); reszta → lista_obcieta

/** Data RRRR-MM-DD → DateTimeImmutable (północ, Europe/Warsaw) albo null przy złym formacie / nieistniejącej dacie (31.02). */
function bon_raport_data(string $s): ?DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, new DateTimeZone(BON_STREFA));
    return ($d && $d->format('Y-m-d') === $s) ? $d : null;
}

/**
 * Okres raportu z parametrów GET: ?okres=dzis|tydzien|miesiac|rok albo ?od=RRRR-MM-DD&do=RRRR-MM-DD; brak = miesiac.
 * Bieżące okresy kończą się dziś (włącznie), tydzień od poniedziałku. → [200, ['okres','od','do']] albo [400, błąd].
 */
function bon_raport_okres(array $get, ?DateTimeImmutable $teraz = null): array {
    $dzis = ($teraz ?? bon_teraz())->setTimezone(new DateTimeZone(BON_STREFA))->setTime(0, 0);
    $od = isset($get['od']) ? trim((string)$get['od']) : '';
    $do = isset($get['do']) ? trim((string)$get['do']) : '';
    if ($od !== '' || $do !== '') {
        $dOd = bon_raport_data($od);
        $dDo = bon_raport_data($do);
        if (!$dOd || !$dDo) return [400, ['ok' => false, 'blad' => 'Podaj obie daty w formacie RRRR-MM-DD.']];
        if ($dOd > $dDo) return [400, ['ok' => false, 'blad' => 'Data „od” jest późniejsza niż „do”.']];
        if ((int)$dOd->diff($dDo)->days >= BON_RAPORT_MAKS_DNI) return [400, ['ok' => false, 'blad' => 'Zakres może mieć najwyżej 5 lat.']];
        return [200, ['okres' => 'zakres', 'od' => $dOd->format('Y-m-d'), 'do' => $dDo->format('Y-m-d')]];
    }
    $okres = isset($get['okres']) ? (string)$get['okres'] : 'miesiac';
    if (!in_array($okres, BON_RAPORT_OKRESY, true)) return [400, ['ok' => false, 'blad' => 'Nieznany okres.']];
    $start = match ($okres) {
        'dzis' => $dzis,
        'tydzien' => $dzis->modify('-' . ((int)$dzis->format('N') - 1) . ' days'), // poniedziałek bieżącego tygodnia
        'miesiac' => $dzis->modify('first day of this month'),
        'rok' => $dzis->setDate((int)$dzis->format('Y'), 1, 1),
    };
    return [200, ['okres' => $okres, 'od' => $start->format('Y-m-d'), 'do' => $dzis->format('Y-m-d')]];
}

/** Granice okresu do zapytań: [od 00:00:00, do + 1 dzień 00:00:00) w czasie polskim. */
function bon_raport_granice(string $od, string $do): array {
    return [$od . ' 00:00:00', (new DateTimeImmutable($do, new DateTimeZone(BON_STREFA)))->modify('+1 day')->format('Y-m-d 00:00:00')];
}

/** id realizacji cofniętych (wszystkie bony) → [id => true]. Prefiks „realizacja #ID:” zapisuje cofnięcie w panel.php
 *  (bon_panel_admin, akcja cofnij-realizacje) – ten sam odczyt co bon_panel_cofniete_realizacje dla jednego bonu. */
function bon_raport_cofniete(array $k, PDO $pdo): array {
    $cofniete = [];
    $c = $pdo->query('SELECT szczegoly FROM ' . bon_tabela($k, 'voucher_admin_log') . " WHERE akcja = 'realizacja-cofnieta'");
    foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $s) if (preg_match('/^realizacja #(\d+):/', (string)$s, $m)) $cofniete[(int)$m[1]] = true;
    return $cofniete;
}

/** Suma realizacji z redeemed_at w [od 00:00, do + 1 dzień 00:00), bez cofniętych (niezależnie od daty cofnięcia). */
function bon_raport_zrealizowano(array $k, PDO $pdo, string $od, string $do): array {
    $st = $pdo->prepare('SELECT id, amount_pln FROM ' . bon_tabela($k, 'redemptions') . ' WHERE redeemed_at >= ? AND redeemed_at < ?');
    $st->execute(bon_raport_granice($od, $do));
    $wiersze = $st->fetchAll();
    $cofniete = $wiersze ? bon_raport_cofniete($k, $pdo) : [];
    $grosze = 0;
    $liczba = 0;
    foreach ($wiersze as $r) {
        if (isset($cofniete[(int)$r['id']])) continue;
        $grosze += (int)bon_grosze((string)$r['amount_pln']);
        $liczba++;
    }
    return ['zrealizowano' => bon_zl($grosze), 'realizacji' => $liczba];
}

/**
 * Lista realizacji z okresu (karta #210): najnowsze pierwsze, najwyżej $maks wierszy. Cofnięte ZOSTAJĄ na liście
 * z cofnieta = true (front je przekreśla); do zrealizowano / realizacji nie wchodzą (bon_raport_zrealizowano).
 * Wiersz = kształt historia[] z historia.php (bon_panel_historia) + kod (pełny – stuknięcie otwiera bon), nazwa, kolor.
 * Bez danych kupującego. → ['lista' => [...], 'lista_wszystkich' => int (z cofniętymi), 'lista_obcieta' => bool].
 */
function bon_raport_lista(array $k, PDO $pdo, string $od, string $do, int $maks = BON_RAPORT_LISTA_MAKS): array {
    $tabR = bon_tabela($k, 'redemptions');
    $granice = bon_raport_granice($od, $do);
    $st = $pdo->prepare("SELECT COUNT(*) FROM $tabR WHERE redeemed_at >= ? AND redeemed_at < ?");
    $st->execute($granice);
    $wszystkich = (int)$st->fetchColumn();
    if ($wszystkich === 0) return ['lista' => [], 'lista_wszystkich' => 0, 'lista_obcieta' => false];
    $maks = max(1, $maks); // liczba całkowita wpisana w SQL (LIMIT ? z emulacją PDO w MySQL idzie jako tekst)
    $st = $pdo->prepare('SELECT r.id, r.amount_pln, r.doplata_pln, r.typ, r.pozycje, r.notatka, r.redeemed_at, r.redeemed_by, v.code, v.initial_pln'
        . " FROM $tabR r JOIN " . bon_tabela($k, 'vouchers') . ' v ON v.id = r.voucher_id'
        . " WHERE r.redeemed_at >= ? AND r.redeemed_at < ? ORDER BY r.redeemed_at DESC, r.id DESC LIMIT $maks");
    $st->execute($granice);
    $cofniete = bon_raport_cofniete($k, $pdo);
    $lista = array_map(static fn($r) => [
        'id' => (int)$r['id'],
        'kod' => (string)$r['code'],
        'nazwa' => bon_nazwa($k, $r),
        'kolor' => bon_kolor($k, $r),
        'cofnieta' => isset($cofniete[(int)$r['id']]),
        'kiedy' => (string)$r['redeemed_at'],
        'zdjeto' => bon_zl((int)bon_grosze($r['amount_pln'])),
        'doplata' => bon_zl((int)bon_grosze($r['doplata_pln'])),
        'typ' => (string)$r['typ'],
        'pozycje' => $r['pozycje'] ? (json_decode((string)$r['pozycje'], true) ?: []) : [],
        'notatka' => $r['notatka'],
        'kto' => bon_panel_imie($k, (string)$r['redeemed_by']),
    ], $st->fetchAll());
    return ['lista' => $lista, 'lista_wszystkich' => $wszystkich, 'lista_obcieta' => $wszystkich > count($lista)];
}

// --- saldo pozostałe NA KONIEC OKRESU (karta #210, decyzja Bartka 26.11 ok. 23:55: „Tak, na koniec okresu”) ---
// Stan bonu na chwilę $koniec (= dzień „do” + 1, 00:00) odtwarzamy WSTECZ od dzisiejszego wiersza: cofamy zdarzenia z historii
// (voucher_admin_log) i realizacje z kiedy ≥ $koniec, od najnowszego. Saldo zmieniają tylko realizacje i ich cofnięcia
// (lib/panel.php: realizacja, cofnij-realizacje), status i datę ważności – tylko zdarzenia z listy niżej, każde z wpisem w historii.
// Nowa ścieżka zmiany statusu, daty albo salda BEZ wpisu w historii = błędne saldo w raporcie (pilnują niezmienniki w testy-raport.php).
// Cofnięta realizacja = korekta pomyłki: nie liczy się nigdy, niezależnie od daty cofnięcia (tak samo jak w „zrealizowano”).

const BON_RAPORT_ZDARZENIA_STANU = ['zatwierdz', 'rezygnacja', 'rezygnacja-cofnieta', 'zwrot', 'zwrot-potwierdzony', 'waznosc', 'przedluzenie'];

/**
 * Stan jednego bonu na chwilę $koniec ('RRRR-MM-DD GG:MM:SS', czas polski). Czysta funkcja (bez bazy).
 * $bon: status, balance_pln, expires_at, created_at (wiersz vouchers); $realizacje: [['kwota_pln','kiedy','cofnieta'], …];
 * $zdarzenia: [['akcja','szczegoly','kiedy'(,'id')], …] w dowolnej kolejności.
 * → null (bonu jeszcze nie było) albo ['status' => valid|held|withdrawn|refunded|used, 'saldo' => grosze, 'wazny_do' => RRRR-MM-DD].
 */
function bon_raport_stan_na(array $bon, array $realizacje, array $zdarzenia, string $koniec): ?array {
    if ((string)$bon['created_at'] >= $koniec) return null;
    $saldo = (int)bon_grosze($bon['balance_pln']);
    foreach ($realizacje as $r) {
        if (empty($r['cofnieta']) && (string)$r['kiedy'] >= $koniec) $saldo += (int)bon_grosze($r['kwota_pln']); // realizacja po końcu okresu
    }
    $status = (string)$bon['status'];
    $wazny = substr((string)$bon['expires_at'], 0, 10);
    $po = array_values(array_filter($zdarzenia, static fn($z) => (string)$z['kiedy'] >= $koniec && in_array($z['akcja'], BON_RAPORT_ZDARZENIA_STANU, true)));
    usort($po, static fn($a, $b) => [(string)$b['kiedy'], (int)($b['id'] ?? 0)] <=> [(string)$a['kiedy'], (int)($a['id'] ?? 0)]); // najnowsze pierwsze
    foreach ($po as $z) {
        $s = (string)$z['szczegoly'];
        switch ($z['akcja']) {
            case 'zatwierdz': $status = 'held'; break;                     // „wstrzymany → ważny”
            case 'rezygnacja': $status = 'valid'; break;                   // valid → withdrawn
            case 'rezygnacja-cofnieta': $status = 'withdrawn'; break;      // withdrawn → valid
            case 'zwrot-potwierdzony': $status = 'withdrawn'; break;       // zwrot zlecony przy rezygnacji (lib/zwroty.php)
            case 'zwrot':                                                  // „<status> → zwrócony, …” (lib/panel.php, Oznacz zwrot)
                $status = preg_match('/^(valid|held|withdrawn|used|expired)\s*→/u', $s, $m) ? ($m[1] === 'expired' ? 'valid' : $m[1]) : 'valid';
                break;
            case 'waznosc':                                                // „RRRR-MM-DD → RRRR-MM-DD[; powód]”
            case 'przedluzenie':                                           // „RRRR-MM-DD → RRRR-MM-DD (o 3 mies.)”
                if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*→\s*\d{4}-\d{2}-\d{2}/u', $s, $m)) $wazny = $m[1];
                break;
        }
    }
    if ($status === 'valid' || $status === 'used') $status = $saldo > 0 ? 'valid' : 'used'; // wykorzystany = saldo 0
    return ['status' => $status, 'saldo' => $saldo, 'wazny_do' => $wazny];
}

/**
 * Kontrola, czy odtwarzanie wstecz ma na czym stać (np. do log.php): saldo w bazie = wartość − realizacje nie cofnięte,
 * bon zwrócony ma w historii „zwrot” / „zwrot-potwierdzony”, bon z rezygnacją – „rezygnacja”. $ids = tylko te bony (null = wszystkie).
 * → [['kod' => EWA-…, 'problem' => opis], …] po id bonu; pusta lista = zgodnie. Bez danych kupującego.
 */
function bon_raport_niezgodnosci(array $k, PDO $pdo, ?array $ids = null): array {
    $tabB = bon_tabela($k, 'vouchers');
    $tabL = bon_tabela($k, 'voucher_admin_log');
    $gdzie = $ids === null ? '' : ($ids ? ' WHERE b.id IN (' . implode(',', array_map('intval', $ids)) . ')' : ' WHERE 1 = 0');
    $bony = $pdo->query("SELECT b.id, b.code, b.status, b.initial_pln, b.balance_pln,
            (SELECT COUNT(*) FROM $tabL l WHERE l.voucher_id = b.id AND l.akcja IN ('zwrot', 'zwrot-potwierdzony')) AS zwrotow,
            (SELECT COUNT(*) FROM $tabL l WHERE l.voucher_id = b.id AND l.akcja = 'rezygnacja') AS rezygnacji
        FROM $tabB b$gdzie ORDER BY b.id")->fetchAll();
    if (!$bony) return [];
    $cofniete = bon_raport_cofniete($k, $pdo);
    $zdjeto = [];
    // #274 (uwaga z przeglądu #234): przy liście bonów tylko ich realizacje, nie cała tabela
    $gdzieR = $ids === null ? '' : ' WHERE voucher_id IN (' . implode(',', array_map('intval', $ids)) . ')';
    foreach ($pdo->query('SELECT id, voucher_id, amount_pln FROM ' . bon_tabela($k, 'redemptions') . $gdzieR)->fetchAll() as $r) {
        if (!isset($cofniete[(int)$r['id']])) $zdjeto[(int)$r['voucher_id']] = ($zdjeto[(int)$r['voucher_id']] ?? 0) + (int)bon_grosze($r['amount_pln']);
    }
    $wynik = [];
    foreach ($bony as $b) {
        $problemy = [];
        $oczekiwane = (int)bon_grosze($b['initial_pln']) - ($zdjeto[(int)$b['id']] ?? 0);
        if ($oczekiwane !== (int)bon_grosze($b['balance_pln'])) $problemy[] = 'saldo ' . bon_zl((int)bon_grosze($b['balance_pln'])) . ' zł, a wartość − realizacje = ' . bon_zl($oczekiwane) . ' zł';
        if ($b['status'] === 'refunded' && (int)$b['zwrotow'] === 0) $problemy[] = 'zwrócony bez wpisu zwrotu w historii';
        if ($b['status'] === 'withdrawn' && (int)$b['rezygnacji'] === 0) $problemy[] = 'rezygnacja bez wpisu w historii';
        if ($problemy) $wynik[] = ['kod' => (string)$b['code'], 'problem' => implode('; ', $problemy)];
    }
    return $wynik;
}

/**
 * #274: kontrola zgodności dla log.php (?saldo=1): bony ruszane w ostatnich $dni dniach (utworzone, z realizacją albo wpisem
 * w historii), najwyżej $maks (najnowsze), sprawdzone bon_raport_niezgodnosci. Numery zamaskowane (EWA-****-XXXX), bez danych kupującego.
 */
function bon_raport_kontrola(array $k, PDO $pdo, int $dni = 30, int $maks = 500): array {
    $od = bon_teraz()->modify('-' . max(1, $dni) . ' days')->format('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT id FROM ' . bon_tabela($k, 'vouchers') . ' WHERE created_at >= ?
        UNION SELECT voucher_id FROM ' . bon_tabela($k, 'redemptions') . ' WHERE redeemed_at >= ?
        UNION SELECT voucher_id FROM ' . bon_tabela($k, 'voucher_admin_log') . ' WHERE kiedy >= ? ORDER BY 1 DESC');
    $st->execute([$od, $od, $od]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $obcieto = count($ids) > $maks;
    $ids = array_slice($ids, 0, max(1, $maks));
    $niez = bon_raport_niezgodnosci($k, $pdo, $ids);
    return ['dni' => $dni, 'sprawdzono' => count($ids), 'obcieto' => $obcieto, 'niezgodnych' => count($niez), 'na_chwile' => bon_teraz()->format('Y-m-d H:i:s'),
        'bony' => array_map(static fn($n) => ['kod' => bon_kod_maska($n['kod']), 'problem' => $n['problem']], array_slice($niez, 0, 50))];
}

/**
 * Saldo pozostałe na koniec dnia $do (dla „do” w przyszłości – na dziś). Liczy się bon ważny tego dnia (ważny do ≥ dzień)
 * z saldem > 0; wstrzymany (held) osobno. Rezygnacja, zwrot, wykorzystany i po terminie – poza saldem.
 * → ['pozostalo' => "0.00", 'bonow' => int, 'wstrzymane' => "0.00", 'wstrzymanych' => int, 'na_dzien' => RRRR-MM-DD].
 */
function bon_raport_saldo(array $k, PDO $pdo, string $do, ?DateTimeImmutable $teraz = null): array {
    $dzis = ($teraz ?? bon_teraz())->setTimezone(new DateTimeZone(BON_STREFA))->format('Y-m-d');
    $dzien = min($do, $dzis);
    [, $koniec] = bon_raport_granice($dzien, $dzien);
    $st = $pdo->prepare('SELECT id, status, balance_pln, expires_at, created_at FROM ' . bon_tabela($k, 'vouchers') . ' WHERE created_at < ?');
    $st->execute([$koniec]);
    $bony = $st->fetchAll();
    $real = [];
    $zd = [];
    if ($bony) {
        $cofniete = bon_raport_cofniete($k, $pdo);
        $st = $pdo->prepare('SELECT id, voucher_id, amount_pln, redeemed_at FROM ' . bon_tabela($k, 'redemptions') . ' WHERE redeemed_at >= ?');
        $st->execute([$koniec]);
        foreach ($st->fetchAll() as $r) {
            $real[(int)$r['voucher_id']][] = ['kwota_pln' => $r['amount_pln'], 'kiedy' => (string)$r['redeemed_at'], 'cofnieta' => isset($cofniete[(int)$r['id']])];
        }
        $akcje = implode(',', array_map(static fn($a) => $pdo->quote($a), BON_RAPORT_ZDARZENIA_STANU));
        $st = $pdo->prepare('SELECT id, voucher_id, akcja, szczegoly, kiedy FROM ' . bon_tabela($k, 'voucher_admin_log') . " WHERE kiedy >= ? AND akcja IN ($akcje)");
        $st->execute([$koniec]);
        foreach ($st->fetchAll() as $z) $zd[(int)$z['voucher_id']][] = $z;
    }
    $pozostalo = 0; $bonow = 0; $wstrzymane = 0; $wstrzymanych = 0;
    foreach ($bony as $b) {
        $s = bon_raport_stan_na($b, $real[(int)$b['id']] ?? [], $zd[(int)$b['id']] ?? [], $koniec);
        if (!$s || $s['saldo'] <= 0 || $s['wazny_do'] < $dzien) continue;
        if ($s['status'] === 'valid') { $pozostalo += $s['saldo']; $bonow++; }
        elseif ($s['status'] === 'held') { $wstrzymane += $s['saldo']; $wstrzymanych++; }
    }
    return ['pozostalo' => bon_zl($pozostalo), 'bonow' => $bonow, 'wstrzymane' => bon_zl($wstrzymane), 'wstrzymanych' => $wstrzymanych, 'na_dzien' => $dzien];
}

/** GET raport.php: uprawnienia + okres + suma + lista realizacji. → [kod HTTP, odpowiedź]. */
function bon_raport(array $k, PDO $pdo, array $sesja, array $get, ?DateTimeImmutable $teraz = null): array {
    if (($sesja['rola'] ?? '') !== 'admin' && empty($sesja['rezygnacje'])) {
        return [403, ['ok' => false, 'blad' => 'Raport jest dostępny w widoku Gabinet.']];
    }
    [$kod, $okres] = bon_raport_okres($get, $teraz);
    if ($kod !== 200) return [$kod, $okres];
    return [200, ['ok' => true] + $okres + bon_raport_zrealizowano($k, $pdo, $okres['od'], $okres['do'])
        + bon_raport_lista($k, $pdo, $okres['od'], $okres['do']) + ['saldo' => bon_raport_saldo($k, $pdo, $okres['do'], $teraz)]];
}
