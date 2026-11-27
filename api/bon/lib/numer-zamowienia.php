<?php
// Karta #219 (Backend 2, 2026-11-27): krótki numer zamówienia Easytools (w panelu Easytools „#1604262”) przy bonie, żeby
// bon z panelu dało się jednoznacznie połączyć z zamówieniem w Easytools. API Easytools tego numeru NIE podaje – przychodzi
// tylko w webhooku (`order_id`, liczba obok `order_uuid`). Kolumna vouchers.easytools_order_nr (plan/bony-migracja-numer-zamowienia.sql).
//
// Skąd numer:
//  - dzwonek (bon_webhook_dzwonek w lib/bon.php): po synchronizacji, tylko podpisany single_product_bought z liczbowym order_id;
//  - cron (lib/cron.php): uzupełnienie wstecz z konfig/bony-webhook-log/ – dla bonów, które powstały później niż dzwonek
//    (API jeszcze nie „completed”), i dla bonów sprzed tej zmiany. Tylko podpisane wpisy logu, tylko bony bez numeru.
// Bon utworzony bez żadnego webhooka (webhook nie doszedł) zostaje bez numeru – w panelu jest wtedy tylko UUID, jak dotąd.
// Przed migracją (brak kolumny): zapis pomijany, w dzienniku informacja, bon i synchronizacja bez zmian, nigdy 500.

declare(strict_types=1);

const BON_NUMER_DNI = 30;     // uzupełnianie wstecz: bony zapłacone w ostatnich 30 dniach
const BON_NUMER_PLIKOW = 31;  // najwyżej tyle plików logu webhooków na przebieg (najnowsze pierwsze)
const BON_NUMER_MAKS = 200;   // najwyżej tyle zamówień uzupełnionych na przebieg
const BON_UUID_ZEROWY = '00000000-0000-0000-0000-000000000000';

/** order_id z webhooka → numer jako tekst („1604262”) albo null (brak, nie liczba, zero, za długi). */
function bon_numer_z_webhooka($v): ?string {
    if (is_int($v)) return $v > 0 ? (string)$v : null;
    return is_string($v) && preg_match('/^[1-9][0-9]{0,19}$/', $v) ? $v : null;
}

/** Wpis w panelu („#1604262”, „1604262”, „# 1604262”) → numer albo null. Od 5 cyfr – krótsze to raczej fragment. */
function bon_numer_z_wpisu(string $q): ?string {
    return preg_match('/^#?\s*([1-9][0-9]{4,19})$/', trim($q), $m) ? $m[1] : null;
}

/** Wyjątek bazy oznacza „nie ma kolumny” (MySQL 1054 Unknown column, SQLite „no such column”). */
function bon_blad_brak_kolumny(Throwable $e): bool {
    if (!$e instanceof PDOException) return false;
    return (int)($e->errorInfo[1] ?? 0) === 1054 || stripos($e->getMessage(), 'no such column') !== false
        || stripos($e->getMessage(), 'Unknown column') !== false;
}

/** Czy migracja #219 jest już w bazie (kolumna easytools_order_nr istnieje). */
function bon_numer_kolumna_jest(array $k, PDO $pdo): bool {
    try {
        $pdo->query('SELECT easytools_order_nr FROM ' . bon_tabela($k, 'vouchers') . ' WHERE 1 = 0');
        return true;
    } catch (PDOException $e) {
        if (bon_blad_brak_kolumny($e)) return false;
        throw $e;
    }
}

/** Numer do wszystkich bonów zamówienia, które jeszcze go nie mają (istniejący numer nie jest nadpisywany). Zwraca liczbę bonów. */
function bon_numer_zapisz(array $k, PDO $pdo, string $uuid, string $nr): int {
    $st = $pdo->prepare('UPDATE ' . bon_tabela($k, 'vouchers') . ' SET easytools_order_nr = ? WHERE easytools_order_id = ? AND easytools_order_nr IS NULL');
    $st->execute([$nr, strtolower($uuid)]);
    return $st->rowCount();
}

/**
 * Dzwonek: zapis numeru po synchronizacji. Nigdy nie rzuca – wynik trafia do zdarzenia „dzwonek” (webhook.php).
 * ['nr', 'zapisano'] | ['nr', 'brak_kolumny' => true] | ['nr', 'blad' => opis].
 */
function bon_numer_po_dzwonku(array $k, PDO $pdo, string $uuid, string $nr): array {
    try {
        return ['nr' => $nr, 'zapisano' => bon_numer_zapisz($k, $pdo, $uuid, $nr)];
    } catch (Throwable $e) {
        return bon_blad_brak_kolumny($e) ? ['nr' => $nr, 'brak_kolumny' => true] : ['nr' => $nr, 'blad' => bon_opis_bledu($e)];
    }
}

/**
 * Cron: uzupełnienie wstecz z logu webhooków. Najpierw bony bez numeru z ostatnich BON_NUMER_DNI dni – gdy ich nie ma,
 * żaden plik nie jest otwierany. Pliki od najnowszego, najwyżej BON_NUMER_PLIKOW; tylko wpisy z podpis_ok === true (każde
 * podpisane zdarzenie Easytools niesie tę samą parę order_uuid ↔ order_id, także product_access_expired). Idempotentnie.
 * Zwraca liczby (bez danych osobowych); brak kolumny = ['brak_kolumny' => true], bez wyjątku.
 */
function bon_numer_uzupelnij(array $k, PDO $pdo, ?DateTimeImmutable $teraz = null): array {
    $teraz ??= bon_teraz();
    $wynik = ['brakujacych' => 0, 'plikow' => 0, 'pasujacych' => 0, 'uzupelniono' => 0];
    try {
        $st = $pdo->prepare('SELECT DISTINCT easytools_order_id FROM ' . bon_tabela($k, 'vouchers') . ' WHERE easytools_order_nr IS NULL AND created_at >= ?');
        $st->execute([$teraz->modify('-' . BON_NUMER_DNI . ' days')->format('Y-m-d H:i:s')]);
        $brak = array_flip(array_map(static fn($z) => strtolower((string)$z), $st->fetchAll(PDO::FETCH_COLUMN)));
    } catch (PDOException $e) {
        if (bon_blad_brak_kolumny($e)) return $wynik + ['brak_kolumny' => true];
        throw $e;
    }
    $wynik['brakujacych'] = count($brak);
    if (!$brak) return $wynik;

    $pliki = glob((string)$k['_katalog'] . '/bony-webhook-log/*.log') ?: [];
    rsort($pliki);
    $mapa = [];
    foreach (array_slice($pliki, 0, BON_NUMER_PLIKOW) as $plik) {
        $wynik['plikow']++;
        foreach (@file($plik, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linia) {
            $w = json_decode($linia, true);
            if (!is_array($w) || ($w['podpis_ok'] ?? null) !== true || !is_array($w['zdarzenie'] ?? null)) continue;
            $z = $w['zdarzenie'];
            $uuid = is_string($z['order_uuid'] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $z['order_uuid']) ? strtolower($z['order_uuid']) : null;
            if ($uuid === null || $uuid === BON_UUID_ZEROWY || !isset($brak[$uuid]) || isset($mapa[$uuid])) continue;
            $nr = bon_numer_z_webhooka($z['order_id'] ?? null);
            if ($nr !== null) $mapa[$uuid] = $nr;
        }
        if (count($mapa) === count($brak)) break;
    }
    $wynik['pasujacych'] = count($mapa);
    foreach (array_slice($mapa, 0, BON_NUMER_MAKS, true) as $uuid => $nr) $wynik['uzupelniono'] += bon_numer_zapisz($k, $pdo, (string)$uuid, $nr);
    return $wynik;
}
