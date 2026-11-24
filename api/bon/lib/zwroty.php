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
        return ['blad' => 'Easytools nie odpowiedział (' . mb_substr($e->getMessage(), 0, 120) . ')'];
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
function bon_panel_platnosc(array $k, array $bon): ?array {
    $tr = (string)($bon['easytools_transaction_id'] ?? '');
    if ($tr === '') return ['blad' => 'Bon nie ma zapisanej transakcji Easytools – stan płatności sprawdź w panelu Easytools.'];
    $p = bon_et_platnosc($k, $tr);
    if (isset($p['blad'])) return ['blad' => $p['blad']];
    $odp = ['zaplacono' => bon_zl($p['zaplacono']), 'zwrocono' => bon_zl($p['zwrocono']), 'w_toku' => bon_zl($p['w_toku']), 'do_zwrotu' => bon_zl($p['do_zwrotu'])];
    if (($p['zwrocono'] > 0 || $p['w_toku'] > 0) && in_array($bon['status'], ['valid', 'used'], true)) {
        $odp['ostrzezenie'] = 'Pieniądze za to zamówienie zostały (albo są) zwracane w Easytools, a bon nadal działa. Sprawdź i oznacz zwrot.';
    }
    return $odp;
}

/**
 * „Zwróć pieniądze”: [kod HTTP, odpowiedź]. Tylko bon z rezygnacją, stan zwrotu oczekuje (albo nieudany po sprawdzeniu
 * w Easytools – $dane['sprawdzone'] = true). Kwota = min(kwota z rezygnacji, remaining_refundable). Stan „zlecony” zapisany
 * w bazie PRZED wywołaniem API (transakcja + FOR UPDATE) – drugi klik / drugie konto: 409 „Zwrot już zlecony”.
 * Odpowiedź z juz_zwrocony = true: Easytools nie ma już nic do zwrotu (zwrot zrobiony ręcznie) – panel proponuje „Oznacz zwrot”.
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
    if ($p['do_zwrotu'] <= 0) return [409, ['ok' => false, 'juz_zwrocony' => true, 'blad' => 'Ta płatność jest już zwrócona w Easytools.']];

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
        $kwota = min((int)bon_grosze($w['kwota_pln']), $p['do_zwrotu']);
        if ($kwota <= 0) { $pdo->rollBack(); return [409, ['ok' => false, 'blad' => 'Kwota do zwrotu wynosi 0 zł.']]; }
        $pdo->prepare("UPDATE $tabW SET zwrot_stan = 'zlecony', zwrot_zlecono_at = ?, zwrot_kwota_pln = ?, zwrot_ref = ?, zwrot_blad = NULL WHERE voucher_id = ?")
            ->execute([$teraz->format('Y-m-d H:i:s'), bon_zl($kwota), 'api ' . $teraz->format('Y-m-d H:i:s'), $bon['id']]);
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
