<?php
// P-11 (2026-11-24, wpisy 15:40, 16:00, 16:19, 17:40): zwrot pieniędzy z panelu jednym przyciskiem przez API Easytools.
// API (developers.easy.tools → transactions): POST /transactions/{id}/refunds {amount: grosze} → 202 „Refund submitted…”, BEZ id
// zwrotu i bez statusu – zwrot asynchroniczny (Stripe / Tpay). Stan z GET /transactions/{id}?include=refunds: refunded_amount,
// remaining_refundable (pomniejszone o zwroty w toku), refund_state, refunds[] (tylko zakończone). Trybu próbnego nie ma.
// Stany zwrotu w voucher_withdrawals (bez migracji, krok 1): oczekuje → zlecony (zapisane PRZED wywołaniem API, w transakcji
// z blokadą wierszy) → potwierdzony (cron, bon 'refunded') albo nieudany (błąd API; ponowienie po sprawdzeniu w Easytools).

declare(strict_types=1);
require_once __DIR__ . '/easytools.php';

/**
 * Stan płatności transakcji w Easytools (linia „Płatność w Easytools” w panelu, potwierdzanie zwrotów):
 * ['zaplacono','zwrocono','do_zwrotu','w_toku' (grosze), 'stan', 'zwroty' => [[kwota, kiedy]]] albo ['blad' => opis].
 * w_toku = zapłacono − zwrócono − do_zwrotu (zwrot przyjęty przez dostawcę, jeszcze niezakończony).
 */
function bon_et_platnosc(array $k, string $transakcja): array {
    if ($transakcja === '' || !bon_et_klucz_ok($k)) return ['blad' => 'brak transakcji Easytools przy bonie'];
    try {
        $t = bon_et_get($k, '/transactions/' . rawurlencode($transakcja), ['include' => 'refunds']);
    } catch (Throwable $e) {
        // brak_polaczenia: zerwanie / timeout (bez odpowiedzi HTTP) – hamulec crona A1 liczy tylko takie błędy, nie 404
        return ['blad' => 'Easytools nie odpowiedział (' . mb_substr($e->getMessage(), 0, 120) . ')',
            'brak_polaczenia' => !preg_match('/: HTTP \d+/', $e->getMessage())];
    }
    $zaplacono = (int)($t['total'] ?? 0);
    $zwrocono = (int)($t['refunded_amount'] ?? 0);
    $doZwrotu = (int)($t['remaining_refundable'] ?? max(0, $zaplacono - $zwrocono));
    return [
        'zaplacono' => $zaplacono, 'zwrocono' => $zwrocono, 'do_zwrotu' => $doZwrotu, 'w_toku' => max(0, $zaplacono - $zwrocono - $doZwrotu),
        'stan' => (string)($t['refund_state'] ?? ''), 'zwrotna' => ($t['refundable'] ?? true) !== false,
        'zwroty' => array_map(static fn($r) => ['kwota' => (int)($r['amount'] ?? 0), 'kiedy' => (string)($r['refunded_at'] ?? '')], (array)($t['refunds'] ?? [])),
    ];
}

/** Linia „Płatność w Easytools” dla panelu (zł jako tekst) + ostrzeżenie A1: pieniądze oddane, a bon nadal działa. */
function bon_panel_platnosc(array $k, array $bon, ?array $p = null): ?array {
    $tr = (string)($bon['easytools_transaction_id'] ?? '');
    if ($tr === '') return ['blad' => 'Bon nie ma zapisanej transakcji Easytools – stan płatności sprawdź w panelu Easytools.'];
    $p ??= bon_et_platnosc($k, $tr); // już pobrane w tym żądaniu – bez drugiego wywołania API
    if (isset($p['blad'])) return ['blad' => $p['blad']];
    $odp = ['zaplacono' => bon_zl($p['zaplacono']), 'zwrocono' => bon_zl($p['zwrocono']), 'w_toku' => bon_zl($p['w_toku']), 'do_zwrotu' => bon_zl($p['do_zwrotu'])];
    if (($p['zwrocono'] > 0 || $p['w_toku'] > 0) && in_array($bon['status'], ['valid', 'used'], true)) {
        $odp['ostrzezenie'] = 'Pieniądze za to zamówienie zostały (albo są) zwracane w Easytools, a bon nadal działa. Sprawdź i oznacz zwrot.';
    }
    return $odp;
}

/**
 * REVIEW nr 1–2 (24.11): ocena zwrotu bonu przyciskiem, wspólna dla „Zwróć pieniądze” i widoku bonu (napis przycisku).
 * Wychodzi z Easytools = zapłacono − do_zwrotu (zwrócone + w toku). Znane panelowi = suma zwrot_kwota_pln rezygnacji
 * INNYCH bonów tej transakcji w stanie zlecony/potwierdzony (z API i „Oznacz zwrot” = ręcznie). Nadwyżka = zwrot, którego
 * panel nie zlecił (ręczny w Easytools, chargeback) – albo, przy ponowieniu po HTTP 0, nasz zwrot, który jednak poszedł.
 * Wynik: kwota (grosze, która naprawdę wyjdzie), nieznany (grosze), wiele (≥ 2 bony w transakcji), kod: null | przyjety |
 * juz_zwrocony | nie_zlecony | ponowienie_zablokowane, blokada (tekst dla panelu albo null), oznacz (panel proponuje „Oznacz zwrot”).
 * $p = bon_et_platnosc (bez błędu), $w = wiersz voucher_withdrawals.
 */
function bon_zwrot_ocena(array $k, PDO $pdo, array $bon, array $w, array $p): array {
    $tabB = bon_tabela($k, 'vouchers');
    $tabW = bon_tabela($k, 'voucher_withdrawals');
    $tr = (string)$bon['easytools_transaction_id'];
    $st = $pdo->prepare("SELECT b.id, w.zwrot_stan, w.zwrot_kwota_pln FROM $tabB b LEFT JOIN $tabW w ON w.voucher_id = b.id WHERE b.easytools_transaction_id = ?");
    $st->execute([$tr]);
    $znane = 0;
    $bonow = 0;
    foreach ($st->fetchAll() as $r) {
        $bonow++;
        if ((int)$r['id'] !== (int)$bon['id'] && in_array($r['zwrot_stan'], ['zlecony', 'potwierdzony'], true)) $znane += (int)bon_grosze($r['zwrot_kwota_pln'] ?? 0);
    }
    $wyszlo = max(0, $p['zaplacono'] - $p['do_zwrotu']);
    $nieznany = max(0, $wyszlo - $znane);
    $wiele = $bonow > 1;
    $kwota = min((int)bon_grosze($w['kwota_pln']), $p['do_zwrotu']);
    $odp = ['kwota' => $kwota, 'nieznany' => $nieznany, 'wiele' => $wiele, 'kod' => null, 'blokada' => null, 'oznacz' => false];

    // ponowienie po braku odpowiedzi (HTTP 0): zwrot poszedł, gdy nieznana nadwyżka pokrywa kwotę zlecenia i remaining_refundable
    // spadło od zdjęcia przed POST (zwrot_ref „api … rem=N”) co najmniej o tę kwotę → stan „zlecony” bez drugiego POST
    if ($w['zwrot_stan'] === 'nieudany' && str_starts_with((string)$w['zwrot_blad'], 'HTTP 0')) {
        $zlecona = (int)bon_grosze($w['zwrot_kwota_pln'] ?? 0);
        $rem = preg_match('/ rem=(\d+)/', (string)$w['zwrot_ref'], $m) ? (int)$m[1] : null;
        if ($zlecona > 0 && $nieznany >= $zlecona && ($rem === null || $rem - $p['do_zwrotu'] >= $zlecona)) {
            return ['kod' => 'przyjety', 'kwota' => $zlecona] + $odp;
        }
        if ($wiele) {
            return ['kod' => 'ponowienie_zablokowane', 'oznacz' => true, 'blokada' => 'Poprzednie zlecenie zwrotu zostało bez odpowiedzi Easytools, a w zamówieniu jest kilka bonów – '
                . 'nie ponawiaj z panelu. Zgłoś Bartkowi albo zwróć pieniądze w Easytools i kliknij „Oznacz zwrot”.'] + $odp;
        }
    }
    // cała płatność oddana przy jednym bonie: zwrot zrobiony ręcznie – panel proponuje „Oznacz zwrot” (wpis 16:19)
    if ($p['do_zwrotu'] <= 0 && !$wiele) return ['kod' => 'juz_zwrocony', 'oznacz' => true, 'blokada' => 'Ta płatność jest już zwrócona w Easytools – oznacz zwrot.'] + $odp;
    if ($nieznany > 0) {
        return ['kod' => 'nie_zlecony', 'oznacz' => true, 'blokada' => 'W Easytools jest zwrot ' . bon_zl($nieznany) . ' zł, którego panel nie zlecił. '
            . 'Sprawdź w Easytools; jeśli to zwrot za ten bon, kliknij „Oznacz zwrot”.'] + $odp;
    }
    return $odp;
}

/**
 * „Zwróć pieniądze”: [kod HTTP, odpowiedź]. Tylko bon z rezygnacją, stan zwrotu oczekuje (albo nieudany po sprawdzeniu
 * w Easytools – $dane['sprawdzone'] = true). Kwota i blokady z bon_zwrot_ocena (REVIEW nr 1–2). Stan „zlecony” zapisany
 * w bazie PRZED wywołaniem API (transakcja + FOR UPDATE) – drugi klik / drugie konto: 409 „Zwrot już zlecony”.
 * Odpowiedź z juz_zwrocony = true: Easytools nie ma już nic do zwrotu (zwrot zrobiony ręcznie) – panel proponuje „Oznacz zwrot”.
 * nie_zlecony = w Easytools jest zwrot spoza panelu; ponowienie_zablokowane = kilka bonów po HTTP 0; przyjety = zwrot po HTTP 0
 * jednak poszedł (stan „zlecony”, bez POST, cron go potwierdzi).
 */
function bon_panel_zwroc(array $k, PDO $pdo, array $sesja, array $dane, ?DateTimeImmutable $teraz = null): array {
    if ($sesja['rola'] !== 'admin' && empty($sesja['rezygnacje'])) return [403, ['ok' => false, 'blad' => 'Zwrot pieniędzy robią Bartek albo Ewa.']];
    $teraz ??= bon_teraz();
    $tabB = bon_tabela($k, 'vouchers');
    $tabW = bon_tabela($k, 'voucher_withdrawals');
    $bon = bon_panel_znajdz($k, $pdo, (string)($dane['kod'] ?? ''));
    if (!$bon) return [404, ['ok' => false, 'blad' => 'Nie ma bonu o tym numerze.']];
    $tr = (string)($bon['easytools_transaction_id'] ?? '');
    if ($tr === '') return [409, ['ok' => false, 'blad' => 'Bon nie ma zapisanej transakcji Easytools – zwróć pieniądze w Easytools i kliknij „Oznacz zwrot”.']];

    // już zlecony z panelu: od razu 409 (zwrot w toku obniża remaining_refundable do 0 – bez tego sprawdzenia panel mówiłby
    // „już zwrócone w Easytools” zamiast „Zwrot już zlecony”); ostateczne sprawdzenie i tak niżej, pod blokadą
    $st = $pdo->prepare("SELECT zwrot_stan FROM $tabW WHERE voucher_id = ?");
    $st->execute([$bon['id']]);
    if (in_array($st->fetchColumn(), ['zlecony', 'potwierdzony'], true)) return [409, ['ok' => false, 'blad' => 'Zwrot już zlecony.']];

    // stan płatności PRZED blokadą (wywołanie sieciowe poza transakcją bazy)
    $p = bon_et_platnosc($k, $tr);
    if (isset($p['blad'])) return [502, ['ok' => false, 'blad' => 'Nie udało się sprawdzić płatności w Easytools. Spróbuj za chwilę. (' . $p['blad'] . ')']];

    $pdo->beginTransaction();
    try {
        $zablokuj = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $st = $pdo->prepare("SELECT * FROM $tabB WHERE id = ?" . $zablokuj);
        $st->execute([$bon['id']]);
        $bon = $st->fetch();
        $st = $pdo->prepare("SELECT * FROM $tabW WHERE voucher_id = ?" . $zablokuj);
        $st->execute([$bon['id']]);
        $w = $st->fetch();
        if ($bon['status'] !== 'withdrawn' || !$w) { $pdo->rollBack(); return [409, ['ok' => false, 'blad' => 'Zwrot z panelu jest możliwy tylko dla bonu z zarejestrowaną rezygnacją.']]; }
        if ($w['zwrot_stan'] === 'zlecony' || $w['zwrot_stan'] === 'potwierdzony') { $pdo->rollBack(); return [409, ['ok' => false, 'blad' => 'Zwrot już zlecony.']]; }
        if ($w['zwrot_stan'] === 'nieudany' && empty($dane['sprawdzone'])) {
            $pdo->rollBack();
            return [409, ['ok' => false, 'blad' => 'Poprzednie zlecenie zwrotu się nie udało. Sprawdź w Easytools, że zwrot nie poszedł, i potwierdź ponowienie.']];
        }
        if ($w['zwrot_stan'] !== 'oczekuje' && $w['zwrot_stan'] !== 'nieudany') { $pdo->rollBack(); return [409, ['ok' => false, 'blad' => 'Ta rezygnacja nie czeka na zwrot.']]; }
        $ocena = bon_zwrot_ocena($k, $pdo, $bon, $w, $p);
        if ($ocena['kod'] === 'przyjety') {
            // zwrot po HTTP 0 jednak poszedł: „zlecony” z PIERWOTNĄ datą zlecenia (cron pomija zwroty sprzed zlecono − 5 min), bez POST
            $pdo->prepare("UPDATE $tabW SET zwrot_stan = 'zlecony', zwrot_blad = NULL WHERE voucher_id = ? AND zwrot_stan = 'nieudany'")->execute([$bon['id']]);
            $pdo->commit();
            bon_historia($k, $pdo, (int)$bon['id'], 'zwrot-zlecony', 'Easytools przyjął wcześniejsze zlecenie mimo braku odpowiedzi: ' . bon_zl($ocena['kwota'])
                . " zł, transakcja $tr (bez ponownego zlecenia)", $sesja['login'], $teraz);
            return [200, ['ok' => true, 'przyjety' => true, 'kwota' => bon_zl($ocena['kwota']),
                'opis' => 'Easytools przyjął wcześniejsze zlecenie zwrotu ' . bon_zl($ocena['kwota']) . ' zł – nie zlecono drugi raz']];
        }
        if ($ocena['kod'] !== null) {
            $pdo->rollBack();
            return [409, ['ok' => false, $ocena['kod'] => true, 'blad' => $ocena['blokada']]];
        }
        $kwota = $ocena['kwota'];
        if ($kwota <= 0) { $pdo->rollBack(); return [409, ['ok' => false, 'blad' => 'Kwota do zwrotu wynosi 0 zł.']]; }
        // rem = remaining_refundable przed POST: przy ponowieniu po HTTP 0 widać, czy zwrot jednak poszedł (REVIEW nr 2)
        $pdo->prepare("UPDATE $tabW SET zwrot_stan = 'zlecony', zwrot_zlecono_at = ?, zwrot_kwota_pln = ?, zwrot_ref = ?, zwrot_blad = NULL WHERE voucher_id = ?")
            ->execute([$teraz->format('Y-m-d H:i:s'), bon_zl($kwota), 'api ' . $teraz->format('Y-m-d H:i:s') . ' rem=' . $p['do_zwrotu'], $bon['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // zlecenie w Easytools (stan „zlecony” już zapisany – równoległy klik dostał 409)
    try {
        [$http, $odp] = bon_et_api($k, 'POST', '/transactions/' . rawurlencode($tr) . '/refunds', [], ['amount' => $kwota]);
    } catch (Throwable $e) {
        $http = 0;
        $odp = ['message' => 'brak odpowiedzi (' . mb_substr($e->getMessage(), 0, 100) . ')'];
    }
    $opis = bon_zl($kwota) . ' zł, transakcja ' . $tr;
    if ($http === 202 || $http === 200) {
        bon_historia($k, $pdo, (int)$bon['id'], 'zwrot-zlecony', "zlecony w Easytools z panelu: $opis", $sesja['login'], $teraz);
        bon_powiadom($k, 'Bon: zlecono zwrot ' . bon_zl($kwota) . ' zł – ' . $bon['code'], "Zwrot zlecony z panelu przez {$sesja['login']}.\n"
            . "Bon: {$bon['code']}\nKwota: " . bon_zl($kwota) . " zł\nZamówienie Easytools: {$bon['easytools_order_id']}\nTransakcja: $tr\n"
            . "Easytools zaksięguje zwrot po potwierdzeniu przez operatora płatności (BLIK nawet kilka dni). Panel pokaże „Zwrot potwierdzony”.");
        return [200, ['ok' => true, 'kwota' => bon_zl($kwota), 'opis' => 'zwrot ' . bon_zl($kwota) . ' zł zlecony w Easytools']];
    }
    $komunikat = mb_substr((string)($odp['message'] ?? ('HTTP ' . $http)), 0, 200);
    // 422 „nic do zwrotu” (zwrot zrobiony ręcznie w międzyczasie) – cofamy „zlecony” do „oczekuje”, panel proponuje „Oznacz zwrot”
    if ($http === 422 && preg_match('/exceeds|nothing|no remaining|already/i', $komunikat) && bon_et_platnosc($k, $tr)['do_zwrotu'] === 0) {
        $pdo->prepare("UPDATE $tabW SET zwrot_stan = 'oczekuje', zwrot_zlecono_at = NULL, zwrot_kwota_pln = NULL, zwrot_ref = NULL WHERE voucher_id = ?")->execute([$bon['id']]);
        return [409, ['ok' => false, 'juz_zwrocony' => true, 'blad' => 'Ta płatność jest już zwrócona w Easytools.']];
    }
    $pdo->prepare("UPDATE $tabW SET zwrot_stan = 'nieudany', zwrot_blad = ? WHERE voucher_id = ?")->execute(["HTTP $http: $komunikat", $bon['id']]);
    bon_historia($k, $pdo, (int)$bon['id'], 'zwrot-nieudany', "Easytools odmówił albo nie odpowiedział (HTTP $http: $komunikat); $opis", $sesja['login'], $teraz);
    bon_powiadom($k, 'Bon: zwrot NIE został zlecony – ' . $bon['code'], "Zlecenie zwrotu z panelu się nie udało (HTTP $http: $komunikat).\n"
        . "Bon: {$bon['code']}\nZamówienie Easytools: {$bon['easytools_order_id']}\n"
        . ($http === 0 ? "Brak odpowiedzi – zwrot MÓGŁ pójść. Sprawdź w Easytools, zanim zlecisz ponownie.\n" : "Sprawdź w Easytools i zleć ponownie albo zwróć ręcznie.\n"));
    return [502, ['ok' => false, 'blad' => $http === 0
        ? 'Easytools nie odpowiedział – zwrot mógł pójść. Sprawdź w Easytools, zanim spróbujesz ponownie.'
        : "Easytools nie przyjął zwrotu ($komunikat). Sprawdź w Easytools; możesz ponowić albo zwrócić ręcznie i kliknąć „Oznacz zwrot”."]];
}

/**
 * REVIEW nr 3 (A1, 24.11): cron – zwrot w Easytools, o którym panel nie wie, przy bonie, który nadal działa.
 * Transakcje bonów valid/used z ostatnich $dni dni, porcjami po $porcja (kursor w konfig/bony-a1-kursor.txt, po końcu od początku).
 * Gdy zapłacono − do_zwrotu (zwrócone + w toku) > zwroty znane panelowi (rezygnacje zlecony/potwierdzony, z API i „Oznacz zwrot”)
 * → jeden alert na alerty@ z numerami działających bonów (znacznik w konfig/bony-a1-alerty/ z kwotą; nowy alert tylko, gdy kwota
 * spoza panelu urośnie). Bez automatycznego blokowania bonu (decyzja 21:37). Sporów/chargebacków API Easytools nie opisuje – jeśli
 * bank cofa płatność jako zwrot, złapie to ten sam warunek.
 */
function bon_zwroty_a1(array $k, PDO $pdo, ?DateTimeImmutable $teraz = null, int $porcja = 60, int $dni = 200): array {
    $teraz ??= bon_teraz();
    $tabB = bon_tabela($k, 'vouchers');
    $tabW = bon_tabela($k, 'voucher_withdrawals');
    $katalog = (string)($k['_katalog'] ?? '');
    $plikKursor = $katalog !== '' ? $katalog . '/bony-a1-kursor.txt' : '';
    $od = $plikKursor !== '' && is_file($plikKursor) ? max(0, (int)@file_get_contents($plikKursor)) : 0;
    $st = $pdo->prepare("SELECT DISTINCT easytools_transaction_id FROM $tabB WHERE status IN ('valid', 'used') AND easytools_transaction_id IS NOT NULL
                         AND easytools_transaction_id <> '' AND created_at >= ? ORDER BY easytools_transaction_id");
    $st->execute([$teraz->modify("-$dni days")->format('Y-m-d H:i:s')]);
    $wszystkie = $st->fetchAll(PDO::FETCH_COLUMN);
    if ($od >= count($wszystkie)) $od = 0;
    $porcjaTr = array_slice($wszystkie, $od, $porcja);
    $wynik = ['transakcji' => count($wszystkie), 'sprawdzono' => 0, 'bledy' => 0, 'alerty' => 0, 'przerwano' => false];
    $bledyZRzedu = 0;
    foreach ($porcjaTr as $tr) {
        $p = bon_et_platnosc($k, (string)$tr);
        if (isset($p['blad'])) {
            $wynik['bledy']++;
            // hamulec: Easytools nie odpowiada – 3 zerwania z rzędu kończą przebieg (każde to do 15 s), kursor zostaje, następny cron
            // powtórzy; odpowiedź HTTP z błędem (np. 404 starej transakcji) nie liczy się do hamulca
            if (empty($p['brak_polaczenia'])) continue;
            if (++$bledyZRzedu >= 3) { $wynik['przerwano'] = true; break; }
            continue;
        }
        $bledyZRzedu = 0;
        $wynik['sprawdzono']++;
        $st = $pdo->prepare("SELECT b.code, b.status, b.easytools_order_id, w.zwrot_stan, w.zwrot_kwota_pln FROM $tabB b LEFT JOIN $tabW w ON w.voucher_id = b.id
                             WHERE b.easytools_transaction_id = ? ORDER BY b.nr_w_zamowieniu, b.id");
        $st->execute([$tr]);
        $znane = 0;
        $dzialaja = [];
        $czekaja = []; // bony z rezygnacją czekającą na zwrot – najpewniej to za nie oddano pieniądze ręcznie
        $zamowienie = '';
        foreach ($st->fetchAll() as $r) {
            $zamowienie = (string)$r['easytools_order_id'];
            if (in_array($r['zwrot_stan'], ['zlecony', 'potwierdzony'], true)) $znane += (int)bon_grosze($r['zwrot_kwota_pln'] ?? 0);
            if (in_array($r['status'], ['valid', 'used'], true)) $dzialaja[] = $r['code'];
            if ($r['status'] === 'withdrawn' && in_array($r['zwrot_stan'], ['oczekuje', 'nieudany'], true)) $czekaja[] = $r['code'];
        }
        $spoza = max(0, $p['zaplacono'] - $p['do_zwrotu']) - $znane;
        if ($spoza <= 0 || !$dzialaja) continue;
        // jeden alert na transakcję; ponownie tylko, gdy kwota spoza panelu urosła
        $plik = $katalog !== '' ? $katalog . '/bony-a1-alerty/' . md5((string)$tr) . '.txt' : '';
        if ($plik !== '' && is_file($plik) && (int)@file_get_contents($plik) >= $spoza) continue;
        // bon z rezygnacją czekającą na zwrot pierwszy (temat i przycisk w mailu otwierają pierwszy numer) – „Oznacz zwrot” na
        // działającym bonie zablokowałby bon, za który pieniądze nie wróciły
        bon_powiadom($k, 'Bon: zwrot w Easytools spoza panelu – ' . ($czekaja[0] ?? $dzialaja[0]), "W Easytools oddano pieniądze za zamówienie, a panel tego zwrotu nie zlecił ani nie oznaczył.\n"
            . ($czekaja ? 'Czeka na zwrot (rezygnacja): ' . implode(', ', $czekaja) . " – najpewniej to za ten bon; oznacz zwrot przy nim.\n" : '')
            . 'Bony, które nadal działają: ' . implode(', ', $dzialaja) . "\n"
            . 'Zapłacono: ' . bon_zl($p['zaplacono']) . ' zł, zwrócono: ' . bon_zl($p['zwrocono']) . ' zł, w toku: ' . bon_zl($p['w_toku']) . " zł\n"
            . 'Znane panelowi: ' . bon_zl($znane) . ' zł, spoza panelu: ' . bon_zl($spoza) . " zł\n"
            . "Zamówienie Easytools: $zamowienie\nTransakcja: $tr\n"
            . "Sprawdź w Easytools, za który bon to zwrot, i kliknij przy nim „Oznacz zwrot”. Do tego czasu bon można zrealizować przy kasie.");
        bon_zdarzenie($k, 'a1-zwrot-spoza-panelu', ['transakcja' => (string)$tr, 'bony' => array_map('bon_kod_maska', $dzialaja), 'spoza_gr' => $spoza, 'znane_gr' => $znane]);
        if ($plik !== '' && (is_dir(dirname($plik)) || @mkdir(dirname($plik), 0700, true))) @file_put_contents($plik, (string)$spoza, LOCK_EX);
        $wynik['alerty']++;
    }
    if ($plikKursor !== '' && !$wynik['przerwano']) {
        @file_put_contents($plikKursor, (string)($od + count($porcjaTr) >= count($wszystkie) ? 0 : $od + count($porcjaTr)), LOCK_EX);
    }
    return $wynik;
}

/**
 * Cron: potwierdzanie zleconych zwrotów. Dla każdej rezygnacji „zlecony” (z API) czyta transakcję: zwrot zakończony = na liście
 * refunds[] jest pozycja o tej kwocie, zakończona po zleceniu i jeszcze nieprzypisana (kilka bonów z jednej transakcji).
 * Wtedy: zwrot_stan = 'potwierdzony', bon 'refunded', wpis w historii. Zwraca podsumowanie do dziennika.
 */
function bon_zwroty_potwierdz(array $k, PDO $pdo, ?DateTimeImmutable $teraz = null): array {
    $teraz ??= bon_teraz();
    $tabB = bon_tabela($k, 'vouchers');
    $tabW = bon_tabela($k, 'voucher_withdrawals');
    $st = $pdo->query("SELECT w.voucher_id, w.zwrot_kwota_pln, w.zwrot_zlecono_at, b.easytools_transaction_id, b.code FROM $tabW w JOIN $tabB b ON b.id = w.voucher_id
                       WHERE w.zwrot_stan = 'zlecony' AND w.zwrot_ref LIKE 'api %' ORDER BY w.zwrot_zlecono_at, w.voucher_id");
    $wynik = ['sprawdzono' => 0, 'potwierdzono' => 0];
    $uzyte = []; // transakcja => indeksy refunds[] już przypisane
    $cache = [];
    foreach ($st->fetchAll() as $w) {
        $tr = (string)$w['easytools_transaction_id'];
        $wynik['sprawdzono']++;
        $cache[$tr] ??= bon_et_platnosc($k, $tr);
        $p = $cache[$tr];
        if (isset($p['blad'])) continue;
        $kwota = (int)bon_grosze($w['zwrot_kwota_pln']);
        $zlecono = new DateTimeImmutable((string)$w['zwrot_zlecono_at'], new DateTimeZone(BON_STREFA));
        foreach ($p['zwroty'] as $i => $r) {
            if (isset($uzyte[$tr][$i]) || $r['kwota'] !== $kwota) continue;
            try { $kiedy = new DateTimeImmutable($r['kiedy']); } catch (Throwable) { continue; }
            if ($kiedy < $zlecono->modify('-5 minutes')) continue; // zwrot sprzed zlecenia (np. ręczny) – nie ten
            $uzyte[$tr][$i] = true;
            $pdo->prepare("UPDATE $tabW SET zwrot_stan = 'potwierdzony', zwrot_potwierdzono_at = ? WHERE voucher_id = ? AND zwrot_stan = 'zlecony'")
                ->execute([$teraz->format('Y-m-d H:i:s'), $w['voucher_id']]);
            $pdo->prepare("UPDATE $tabB SET status = 'refunded' WHERE id = ?")->execute([$w['voucher_id']]);
            bon_historia($k, $pdo, (int)$w['voucher_id'], 'zwrot-potwierdzony', 'Easytools zaksięgował zwrot ' . bon_zl($kwota) . ' zł (' . $kiedy->setTimezone(new DateTimeZone(BON_STREFA))->format('d.m.Y H:i') . ')', 'system', $teraz);
            $wynik['potwierdzono']++;
            break;
        }
    }
    return $wynik;
}
