<?php
// Bony bez webhooka (2026-11-23, plan/PROMPT-CLAUDE-CODE-BONY-ZWROTY.md, „Priorytet zero”): synchronizacja zamówień z API Easytools.
// Webhook Easytools nie dochodzi, więc bony powstają też z odpytywania API (https://developers.easy.tools, Easycart):
//   GET /orders?status=completed&product=<UUID produktu bonów>  → zamówienia bez wiersza w vouchers
//   GET /orders/{id}?include=line_items,customer                → wariant (nazwa), ilość, kwota w groszach, kupujący
//   GET /transactions?paid_from=… + GET /transactions/{id}?include=order → id transakcji (do zwrotów; zamówienie go nie podaje)
// Bon tworzy ta sama funkcja co webhook (bon_utworz_bony) – klucz unikalny (zamówienie, nr) pilnuje duplikatów, także gdy webhook
// kiedyś ruszy równolegle (webhook zapisuje order_uuid = ten sam UUID).
//
// Czego API NIE podaje (sprawdzone 23.11.2026 w dokumentacji i na prawdziwym zamówieniu): pól własnych „Dla/Od”, kodu rabatowego,
// identyfikatora ceny (tylko variant_name, np. „Bon 200 zł”), numeru zamówienia z panelu. Decyzja Bartka 23.11: bon z API bez Dla/Od.
// Wariant → nominał: mapa „warianty” w konfigu (po nazwie wariantu); nieznana nazwa = bon wstrzymany (held).
//
// Wywołują: /api/bon/synchronizuj.php (strona /bony/dziekujemy po powrocie z kasy, panel), lib/cron.php (cron OVH co godzinę).
// Tryb w konfigu: sync_tryb = 'wylaczony' | 'raport' (tylko pokazuje, co by zrobił – domyślny) | 'bon'.
// sync_od: zamówienia starsze niż ta chwila są pomijane (Bartek 23.11: od zamówienia z 23.11 10:07).

declare(strict_types=1);
require_once __DIR__ . '/bon.php';
require_once __DIR__ . '/mail.php';

const BON_ET_API = 'https://cart.easy.tools/api/v1';

/** Wywołanie API Easytools: [kod HTTP, zdekodowany JSON]. Testy podstawiają $k['easytools_http'] (callable). */
function bon_et_api(array $k, string $metoda, string $sciezka, array $q = [], ?array $body = null): array {
    if (isset($k['easytools_http']) && is_callable($k['easytools_http'])) return ($k['easytools_http'])($metoda, $sciezka, $q, $body);
    $url = BON_ET_API . $sciezka . ($q ? '?' . http_build_query($q) : '');
    $naglowki = ['Authorization: Bearer ' . (string)$k['easytools_api_key'], 'Accept: application/json'];
    if (!empty($k['easytools_sklep'])) $naglowki[] = 'X-Easycart-Store: ' . $k['easytools_sklep'];
    $c = curl_init($url);
    // REVIEW nr 5: panel przy kasie podaje easytools_timeout = 4 s; synchronizacja, cron i zwroty – domyślne 15 s
    $limit = max(1, (int)($k['easytools_timeout'] ?? 15));
    $opcje = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $limit, CURLOPT_CONNECTTIMEOUT => min(5, $limit), CURLOPT_CUSTOMREQUEST => $metoda];
    if ($body !== null) {
        $naglowki[] = 'Content-Type: application/json';
        $opcje[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $opcje[CURLOPT_HTTPHEADER] = $naglowki;
    curl_setopt_array($c, $opcje);
    $odp = curl_exec($c);
    $kod = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $blad = curl_error($c);
    curl_close($c);
    if ($odp === false) throw new RuntimeException('API Easytools: ' . $blad);
    $dane = json_decode((string)$odp, true);
    return [$kod, is_array($dane) ? $dane : []];
}

/** GET z wymaganym 200; inaczej wyjątek z kodem i komunikatem API (bez klucza). */
function bon_et_get(array $k, string $sciezka, array $q = []): array {
    [$kod, $d] = bon_et_api($k, 'GET', $sciezka, $q);
    if ($kod !== 200) throw new RuntimeException("API Easytools $sciezka: HTTP $kod " . mb_substr((string)($d['message'] ?? ''), 0, 150));
    return $d;
}

function bon_et_klucz_ok(array $k): bool {
    $klucz = (string)($k['easytools_api_key'] ?? '');
    return $klucz !== '' && !str_starts_with($klucz, 'WKLEJ');
}

/** nazwa wariantu z API → nominał z mapy „warianty” (liczba zł | 'dowolna') albo null. Bez wielkości liter i twardych spacji. */
function bon_et_nominal(array $k, string $nazwa): int|float|string|null {
    return bon_wariant_nominal($k, $nazwa);
}

/**
 * Zamówienie z API (include=line_items,customer) → dane dla bon_utworz_bony. $zaplacono = „date” transakcji (chwila zapłaty);
 * bez niej created_at zamówienia (zawsze przed zapłatą, zwykle tego samego dnia) – nie chwila synchronizacji.
 */
function bon_et_dane_zamowienia(array $zam, ?string $transakcja, ?string $zaplacono = null): array {
    // koszyk mieszany (2026-11-24, wpis 17:30): WSZYSTKIE pozycje bez order bumpów, każda ze swoim wariantem, ilością i kwotą
    // (amount_total = pozycja razem w groszach; bez niego amount × quantity). Wcześniej brana była tylko pierwsza pozycja.
    $linie = array_values(array_filter((array)($zam['line_items'] ?? []), static fn($l) => is_array($l) && empty($l['is_order_bump'])));
    $linia = $linie[0] ?? null;
    $pozycje = array_map(static fn($l) => [
        'wariant' => (string)($l['variant_name'] ?? ''),
        'ilosc' => $l['quantity'] ?? 1,
        'zaplacono' => is_int($l['amount_total'] ?? null) ? $l['amount_total']
            : (is_int($l['amount'] ?? null) && is_int($l['quantity'] ?? null) ? $l['amount'] * $l['quantity'] : null),
    ], $linie);
    $email = (string)($zam['customer']['email'] ?? $zam['customer_email'] ?? '');
    // REVIEW nr 10: kontrola sumy – pozycje bonów razem vs kwota zamówienia bez order bumpów (tylko gdy wszystkie kwoty są znane)
    $bumpy = array_filter((array)($zam['line_items'] ?? []), static fn($l) => is_array($l) && !empty($l['is_order_bump']));
    $bumpyRazem = array_sum(array_map(static fn($l) => is_int($l['amount_total'] ?? null) ? $l['amount_total'] : 0, $bumpy));
    $znane = $pozycje && is_int($zam['amount'] ?? null) && !in_array(null, array_column($pozycje, 'zaplacono'), true)
        && !array_filter($bumpy, static fn($l) => !is_int($l['amount_total'] ?? null));
    return [
        'kontrola_razem' => $znane ? ['pozycje' => array_sum(array_column($pozycje, 'zaplacono')), 'zamowienie' => $zam['amount'] - $bumpyRazem] : null,
        'pozycje' => count($pozycje) > 1 ? $pozycje : null, // jedna pozycja: jak dotąd (pola niżej)
        'zrodlo' => 'api',
        'zamowienie' => (string)$zam['id'],
        'transakcja' => $transakcja,
        'email' => $email,
        'imie' => (string)($zam['customer']['name'] ?? ''),
        'nip' => '',
        'waluta' => (string)($linia['currency'] ?? $zam['currency'] ?? ''),
        'ilosc' => $linia['quantity'] ?? $zam['quantity'] ?? null,
        'zaplacono' => is_int($linia['amount_total'] ?? null) ? $linia['amount_total'] : (is_int($zam['amount'] ?? null) ? $zam['amount'] : null),
        'wariant' => (string)($linia['variant_name'] ?? ''),
        'promo' => '',
        'dla' => null,
        'od' => null,
        'zaplacono_at' => $zaplacono ?: (is_string($zam['created_at'] ?? null) ? $zam['created_at'] : null),
    ];
}

/**
 * Id transakcji zamówienia: zamówienie go nie podaje, transakcja zna swoje zamówienie. Kandydaci z dnia zakupu
 * o tej samej kwocie i e-mailu, potwierdzenie przez GET /transactions/{id}?include=order. Null, gdy nie znaleziono.
 * $data dostaje „date” transakcji (chwila zapłaty, ISO 8601), gdy transakcja się znalazła.
 */
function bon_et_transakcja(array $k, array $zam, ?string &$data = null): ?string {
    try { $kupiono = new DateTimeImmutable((string)($zam['created_at'] ?? '')); } catch (Throwable) { return null; }
    // dzień wcześniej (strefa filtra API nieopisana, zob. bon_synchronizuj); płatność jest zawsze po utworzeniu zamówienia
    $lista = bon_et_get($k, '/transactions', ['paid_from' => $kupiono->modify('-1 day')->format('Y-m-d'), 'per_page' => 100, 'sort' => 'date']);
    $email = mb_strtolower((string)($zam['customer_email'] ?? $zam['customer']['email'] ?? ''), 'UTF-8');
    foreach ((array)($lista['items'] ?? []) as $t) {
        if (!is_array($t) || (int)($t['total'] ?? -1) !== (int)($zam['amount'] ?? -2)) continue;
        if ($email !== '' && mb_strtolower((string)($t['customer_email'] ?? ''), 'UTF-8') !== $email) continue;
        $pelna = bon_et_get($k, '/transactions/' . rawurlencode((string)$t['id']), ['include' => 'order']);
        if ((string)($pelna['order']['id'] ?? '') === (string)$zam['id']) {
            $data = is_string($pelna['date'] ?? null) ? $pelna['date'] : (is_string($t['date'] ?? null) ? $t['date'] : null);
            return (string)$t['id'];
        }
    }
    return null;
}

/** e-mail w raporcie/dzienniku: a***@domena */
function bon_et_maska_email(string $e): string {
    $p = strpos($e, '@');
    return $p === false ? '***' : mb_substr($e, 0, 1) . '***' . substr($e, $p);
}

/**
 * Synchronizacja. $zrodlo: 'strona' (z odstępem minimalnym sync_odstep_s, domyślnie 20 s), 'panel', 'cron'.
 * Zwraca [raport (do dziennika i odpowiedzi panelu, bez danych osobowych), zadania wysyłki PDF do wykonania PO odpowiedzi
 * (tablica: id zamówienia => callable)].
 */
function bon_synchronizuj(array $k, PDO $pdo, string $zrodlo, ?DateTimeImmutable $teraz = null): array {
    $teraz ??= bon_teraz();
    $tryb = (string)($k['sync_tryb'] ?? 'raport');
    $raport = ['zrodlo' => $zrodlo, 'tryb' => $tryb];
    if ($tryb === 'wylaczony') return [$raport + ['pominieto' => 'sync_tryb = wylaczony'], []];
    if (!bon_et_klucz_ok($k)) return [$raport + ['pominieto' => 'brak easytools_api_key'], []];
    $produkt = (string)($k['easytools_produkt_uuid'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/i', $produkt)) return [$raport + ['pominieto' => 'brak easytools_produkt_uuid'], []];
    // bez sync_od nic nie ruszamy – inaczej wróciłyby stare zamówienia testowe (Bartek 23.11: start od zamówienia z 10:07)
    if (trim((string)($k['sync_od'] ?? '')) === '') return [$raport + ['pominieto' => 'brak sync_od'], []];

    $tab = bon_tabela($k, 'vouchers');
    $blokada = 'bon_sync_' . md5($tab);
    if (!bon_blokada($pdo, $blokada)) return [$raport + ['pominieto' => 'synchronizacja w toku'], []];
    try {
        // odstęp minimalny dla wywołań ze strony (publiczny endpoint nie może zalewać API); znacznik w pliku obok konfigu
        if ($zrodlo === 'strona') {
            $plik = $k['_katalog'] . '/bony-sync-strona.txt';
            $ostatnio = is_file($plik) ? (int)@file_get_contents($plik) : 0;
            if (time() - $ostatnio < (int)($k['sync_odstep_s'] ?? 20)) return [$raport + ['pominieto' => 'odstęp'], []];
            @file_put_contents($plik, (string)time(), LOCK_EX);
        }

        $od = new DateTimeImmutable((string)$k['sync_od'], new DateTimeZone(BON_STREFA));
        $okno = $teraz->modify('-' . max(1, (int)($k['sync_dni'] ?? 7)) . ' days');
        $start = $od > $okno ? $od : $okno;

        // 1. opłacone zamówienia produktu bonów z okna (max 5 stron po 100)
        $zamowienia = [];
        for ($strona = 1; $strona <= 5; $strona++) {
            // dzień wcześniej: API nie mówi, czy filtr dat liczy w UTC, a zakup 00:00–01:00 w Polsce to poprzedni dzień w UTC;
            // właściwą granicę pilnuje porównanie z $od niżej
            $lista = bon_et_get($k, '/orders', ['status' => 'completed', 'product' => $produkt, 'created_from' => $start->modify('-1 day')->format('Y-m-d'),
                'per_page' => 100, 'page' => $strona]);
            foreach ((array)($lista['items'] ?? []) as $z) {
                if (!is_array($z) || !is_string($z['id'] ?? null)) continue;
                if (new DateTimeImmutable((string)$z['created_at']) < $od) continue; // przed sync_od (dokładna godzina)
                $zamowienia[$z['id']] = $z;
            }
            if ($strona >= (int)($lista['pagination']['total_pages'] ?? 1)) break;
        }
        $raport['zamowien'] = count($zamowienia);

        $istnieja = [];
        if ($zamowienia) {
            $st = $pdo->prepare("SELECT DISTINCT easytools_order_id FROM $tab WHERE easytools_order_id IN (" . implode(',', array_fill(0, count($zamowienia), '?')) . ')');
            $st->execute(array_keys($zamowienia));
            $istnieja = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        }

        // 2. bony dla brakujących (od najstarszego)
        $zadania = [];
        $nowe = [];
        foreach (array_reverse($zamowienia) as $id => $z) {
            if (isset($istnieja[$id])) continue;
            try {
                $pelne = bon_et_get($k, '/orders/' . rawurlencode($id), ['include' => 'line_items,customer']);
                if (($pelne['status'] ?? '') !== 'completed') continue;
                $transakcja = $zaplacono = null;
                try { $transakcja = bon_et_transakcja($k, $pelne, $zaplacono); } catch (Throwable) {} // brak id transakcji nie blokuje bonu
                $dane = bon_et_dane_zamowienia($pelne, $transakcja, $zaplacono);
                $dane['nominal'] = bon_et_nominal($k, $dane['wariant']);
                if ($dane['pozycje']) {
                    foreach ($dane['pozycje'] as &$p) $p['nominal'] = bon_et_nominal($k, $p['wariant']);
                    unset($p);
                }
                $wpis = ['zamowienie' => $id,
                    'kwota' => bon_zl($dane['pozycje'] ? (int)array_sum(array_column($dane['pozycje'], 'zaplacono')) : (int)$dane['zaplacono']),
                    'wariant' => $dane['pozycje'] ? implode(' + ', array_column($dane['pozycje'], 'wariant')) : $dane['wariant'],
                    'email' => bon_et_maska_email($dane['email']), 'transakcja' => $transakcja !== null];
                if ($tryb !== 'bon') { $nowe[] = $wpis + ['wynik' => 'raport: bon by powstał']; continue; }
                if (!filter_var($dane['email'], FILTER_VALIDATE_EMAIL)) { $nowe[] = $wpis + ['wynik' => 'brak e-maila – pominięte']; continue; }
                [, $odp, $zadanie] = bon_utworz_bony($k, $pdo, $dane) + [2 => null];
                $nowe[] = $wpis + ['wynik' => $odp];
                if ($zadanie) $zadania[$id] = $zadanie;
            } catch (Throwable $e) {
                $nowe[] = ['zamowienie' => $id, 'blad' => bon_opis_bledu($e)['opis']];
            }
        }
        $raport['nowe'] = $nowe;

        if ($tryb === 'bon') {
            // 3. uzupełnienie id transakcji przy bonach bez niego (np. z webhooka), najwyżej 5 zamówień na przebieg
            $bez = $pdo->prepare("SELECT DISTINCT easytools_order_id FROM $tab WHERE easytools_transaction_id IS NULL AND LENGTH(easytools_order_id) = 36 AND created_at >= ? ORDER BY easytools_order_id LIMIT 5");
            $bez->execute([$teraz->modify('-30 days')->format('Y-m-d H:i:s')]);
            $uzupelniono = 0;
            foreach ($bez->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$id)) continue; // tylko UUID (id liczbowe z webhooka API nie zna)
                try {
                    $t = bon_et_transakcja($k, bon_et_get($k, '/orders/' . rawurlencode((string)$id)));
                    if ($t !== null) {
                        $pdo->prepare("UPDATE $tab SET easytools_transaction_id = ? WHERE easytools_order_id = ? AND easytools_transaction_id IS NULL")->execute([$t, $id]);
                        $uzupelniono++;
                    }
                } catch (Throwable) {}
            }
            if ($uzupelniono) $raport['uzupelniono_transakcji'] = $uzupelniono;

            // 4. bony ważne bez wysłanego maila (nieudana wcześniej wysyłka) – dokończ, z ostatnich 14 dni
            $bezMaila = $pdo->prepare("SELECT DISTINCT easytools_order_id FROM $tab WHERE status = 'valid' AND mail_wyslany_at IS NULL AND created_at >= ?");
            $bezMaila->execute([$teraz->modify('-14 days')->format('Y-m-d H:i:s')]);
            foreach ($bezMaila->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $id = (string)$id;
                $zadania[$id] ??= static fn() => bon_wyslij_zamowienie($k, $pdo, $id);
            }
            $raport['do_wysylki'] = count($zadania);
        }
        return [$raport, $zadania]; // klucz = id zamówienia
    } finally {
        bon_zwolnij($pdo, $blokada);
    }
}

/** Wykonanie zadań wysyłki po odpowiedzi; wynik każdego do dziennika. */
function bon_synchronizuj_wyslij(array $k, array $zadania, string $zrodlo): void {
    foreach ($zadania as $zadanie) {
        try {
            bon_zdarzenie($k, 'mail', ['po' => 'synchronizacja-' . $zrodlo] + $zadanie());
        } catch (Throwable $e) {
            error_log('bony/synchronizacja (wysyłka): ' . $e->getMessage());
            bon_zdarzenie($k, 'blad', ['plik' => 'synchronizacja (wysyłka)'] + bon_opis_bledu($e));
            try { bon_powiadom($k, 'Bon: błąd przy wysyłce PDF', 'Wyjątek: ' . $e->getMessage() . "\nKolejna synchronizacja (cron co godzinę) dokończy wysyłkę."); } catch (Throwable) {}
        }
    }
}

/** Raport dla panelu: liczby i krótkie wiersze, bez e-maili. */
function bon_synchronizuj_dla_panelu(array $r): array {
    if (($r['pominieto'] ?? '') === 'synchronizacja w toku') return ['tekst' => 'Pobieranie zamówień już trwa – sprawdź za chwilę.'];
    if (isset($r['pominieto'])) return ['tekst' => 'Pobieranie zamówień z Easytools jest wyłączone w konfiguracji (' . $r['pominieto'] . ').'];
    $nowe = array_values(array_filter((array)($r['nowe'] ?? []), static fn($w) => isset($w['wynik']['bonow']) || isset($w['blad']) || is_string($w['wynik'] ?? null)));
    $ile = 0;
    foreach ($nowe as $w) $ile += (int)($w['wynik']['bonow'] ?? 0);
    $tekst = $r['tryb'] === 'bon'
        ? ($ile ? "Nowe bony: $ile (z zamówień: " . count($nowe) . ').' : 'Brak nowych zamówień.')
        : 'Tryb raportu: bony nie powstają (sync_tryb). Zamówień bez bonu: ' . count($nowe) . '.';
    if (array_filter($nowe, static fn($w) => isset($w['blad']))) $tekst .= ' Część zamówień z błędem – szczegóły w dzienniku.';
    return ['tekst' => $tekst, 'zamowien' => (int)($r['zamowien'] ?? 0), 'nowych_bonow' => $ile];
}
