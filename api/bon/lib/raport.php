<?php
// Raport dla Ewy i Bartka (wpisy czatu 2026-11-26 01:49 i 01:51): „Zrealizowano z bonów” za okres – kwota, którą Bartek
// wypłaca Ewie. Na teraz tylko suma realizacji po cofnięciach; reszta raportu z 01:49 (sprzedane, zwroty, wygasłe, saldo,
// lista, CSV) dojdzie później jako NOWE pola tej samej odpowiedzi (umowa z Frontem 1, 26.11 – istniejące pola się nie zmieniają).
// Uprawnienia na serwerze: admin i konto z uprawnieniem rezygnacji (poziom Gabinet); poziom Bon → 403.
// Daty Europe/Warsaw (redeemed_at zapisywane w BON_STREFA), granice dnia włącznie.

declare(strict_types=1);
require_once __DIR__ . '/panel.php';

const BON_RAPORT_OKRESY = ['dzis', 'tydzien', 'miesiac', 'rok'];
const BON_RAPORT_MAKS_DNI = 5 * 366 + 1; // zakres od–do najwyżej ok. 5 lat

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

/** Suma realizacji z redeemed_at w [od 00:00, do + 1 dzień 00:00), bez cofniętych (niezależnie od daty cofnięcia). */
function bon_raport_zrealizowano(array $k, PDO $pdo, string $od, string $do): array {
    $koniec = (new DateTimeImmutable($do, new DateTimeZone(BON_STREFA)))->modify('+1 day')->format('Y-m-d 00:00:00');
    $st = $pdo->prepare('SELECT id, amount_pln FROM ' . bon_tabela($k, 'redemptions') . ' WHERE redeemed_at >= ? AND redeemed_at < ?');
    $st->execute([$od . ' 00:00:00', $koniec]);
    $wiersze = $st->fetchAll();
    $cofniete = [];
    if ($wiersze) {
        // prefiks „realizacja #ID:” zapisuje cofnięcie w panel.php (bon_panel_admin, akcja cofnij-realizacje)
        $c = $pdo->query('SELECT szczegoly FROM ' . bon_tabela($k, 'voucher_admin_log') . " WHERE akcja = 'realizacja-cofnieta'");
        foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $s) if (preg_match('/^realizacja #(\d+):/', (string)$s, $m)) $cofniete[(int)$m[1]] = true;
    }
    $grosze = 0;
    $liczba = 0;
    foreach ($wiersze as $r) {
        if (isset($cofniete[(int)$r['id']])) continue;
        $grosze += (int)bon_grosze((string)$r['amount_pln']);
        $liczba++;
    }
    return ['zrealizowano' => bon_zl($grosze), 'realizacji' => $liczba];
}

/** GET raport.php: uprawnienia + okres + suma. → [kod HTTP, odpowiedź]. */
function bon_raport(array $k, PDO $pdo, array $sesja, array $get, ?DateTimeImmutable $teraz = null): array {
    if (($sesja['rola'] ?? '') !== 'admin' && empty($sesja['rezygnacje'])) {
        return [403, ['ok' => false, 'blad' => 'Raport jest dostępny w widoku Gabinet.']];
    }
    [$kod, $okres] = bon_raport_okres($get, $teraz);
    if ($kod !== 200) return [$kod, $okres];
    return [200, ['ok' => true] + $okres + bon_raport_zrealizowano($k, $pdo, $okres['od'], $okres['do'])];
}
