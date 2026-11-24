<?php
// Testy kroku 1 (2026-11-23, plan/PROMPT-CLAUDE-CODE-BONY-ZWROTY.md): historia bonu w jednym miejscu, status 'withdrawn'
// (rezygnacja złożona), jedna rezygnacja na bon, tokeny potwierdzeń. Woła je bon_testy() w lib/testy.php.

declare(strict_types=1);
require_once __DIR__ . '/panel.php';
require_once __DIR__ . '/mail.php';

function bon_testy_krok1(array $k, PDO $pdo): array {
    $wyniki = [];
    $t = static function (string $nazwa, bool $ok, string $szczegol = '') use (&$wyniki) { $wyniki[] = [$nazwa, $ok, $szczegol]; };
    $tabB = bon_tabela($k, 'vouchers');
    $historia = static function (int $id) use ($k, $pdo): array {
        $st = $pdo->prepare('SELECT akcja, szczegoly, kto FROM ' . bon_tabela($k, 'voucher_admin_log') . ' WHERE voucher_id = ? ORDER BY id');
        $st->execute([$id]);
        return $st->fetchAll();
    };

    // historia: utworzenie i wysyłka PDF zapisane przez „system”
    $p = bon_test_payload(['customer_email' => 'krok1@example.com']);
    [, , $zadanie] = bon_test_webhook($k, $pdo, $p);
    if ($zadanie) $zadanie();
    $b = bon_test_bony($k, $pdo, $p['order_id'])[0] ?? null;
    $h = $b ? $historia((int)$b['id']) : [];
    $t('historia: „utworzenie” i „mail” od systemu, bez e-maila w szczegółach', count($h) === 2 && $h[0]['akcja'] === 'utworzenie' && $h[1]['akcja'] === 'mail'
        && $h[0]['kto'] === 'system' && str_contains($h[0]['szczegoly'], 'źródło: webhook') && !str_contains(json_encode($h), '@'), json_encode($h, JSON_UNESCAPED_UNICODE));
    $wyjatek = false;
    try { bon_historia($k, $pdo, (int)($b['id'] ?? 0), 'cokolwiek', '', 'system'); } catch (InvalidArgumentException) { $wyjatek = true; }
    $t('historia: nieznana akcja → wyjątek (lista w BON_HISTORIA_AKCJE)', $wyjatek);
    $t('historia: „klient” i „system” z czytelną nazwą w panelu', bon_panel_imie($k, 'klient') === 'Klient (strona)' && bon_panel_imie($k, 'system') === 'System');

    // admin: zmiana zapisana w tej samej historii (kto = login)
    $sAdmin = ['login' => 'bartek', 'imie' => 'Bartek', 'rola' => 'admin', 'csrf' => 'x'];
    [$kodHttp] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $b['code'] ?? '', 'akcja' => 'waznosc', 'data' => bon_teraz()->modify('+1 year')->format('Y-m-d'), 'powod' => 'test']);
    $h = $b ? $historia((int)$b['id']) : [];
    $t('historia: zmiana ważności przez admina w tej samej historii', $kodHttp === 200 && end($h)['akcja'] === 'waznosc' && end($h)['kto'] === 'bartek');

    // status 'withdrawn' (rezygnacja złożona): realizacja zablokowana z powodem, zatwierdz go nie przywraca, verify bez salda
    $pdo->prepare("UPDATE $tabB SET status = 'withdrawn' WHERE id = ?")->execute([$b['id'] ?? 0]);
    $sEwa = ['login' => 'ewa', 'imie' => 'Ewa', 'rola' => 'realizacja', 'csrf' => 'x'];
    [$kodHttp, $odp] = bon_panel_realizuj($k, $pdo, $sEwa, ['kod' => $b['code'] ?? '', 'kwota_reczna' => '10', 'client_token' => bin2hex(random_bytes(12))]);
    $t('rezygnacja: realizacja w panelu zablokowana z powodem', $kodHttp !== 200 && str_contains((string)($odp['blad'] ?? ''), 'zrezygnował'), "$kodHttp " . json_encode($odp, JSON_UNESCAPED_UNICODE));
    [$kodHttp] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $b['code'] ?? '', 'akcja' => 'zatwierdz']);
    $st = $pdo->prepare("SELECT status FROM $tabB WHERE id = ?");
    $st->execute([$b['id'] ?? 0]);
    $t('rezygnacja: „zatwierdz” nie przywraca bonu (409, status bez zmian)', $kodHttp === 409 && $st->fetchColumn() === 'withdrawn');
    [$kodHttp, $odp] = bon_obsluz_verify($k, $b['code'] ?? '', '10.0.0.' . random_int(1, 250), static fn() => $pdo);
    $t('rezygnacja: verify pokazuje status withdrawn bez salda', $kodHttp === 200 && ($odp['status'] ?? '') === 'withdrawn' && !isset($odp['saldo']), json_encode($odp));

    // jedna rezygnacja na bon (UNIQUE voucher_id) – drugi wiersz, a więc drugi zwrot, niemożliwy
    $tabW = bon_tabela($k, 'voucher_withdrawals');
    $teraz = bon_teraz()->format('Y-m-d H:i:s');
    $ins = $pdo->prepare("INSERT INTO $tabW (voucher_id, zlozono_at, potwierdzono_at, kanal, kto, saldo_pln, kwota_pln) VALUES (?, ?, ?, 'strona', 'klient', '200.00', '200.00')");
    $ins->execute([$b['id'] ?? 0, $teraz, $teraz]);
    $drugi = false;
    try { $ins->execute([$b['id'] ?? 0, $teraz, $teraz]); } catch (PDOException $e) { $drugi = ($e->errorInfo[0] ?? '') === '23000'; }
    $t('rezygnacja: druga rezygnacja tego samego bonu odrzucona przez bazę', $drugi);
    $st = $pdo->query("SELECT zwrot_stan FROM $tabW WHERE voucher_id = " . (int)($b['id'] ?? 0));
    $t('rezygnacja: stan zwrotu na start „oczekuje”', $st->fetchColumn() === 'oczekuje');

    // cofnięcie rezygnacji (pomyłka / klient się wycofał): tylko z powodem i tylko przed zleceniem zwrotu
    $kodB = $b['code'] ?? '';
    [$kodHttp] = bon_panel_admin($k, $pdo, $sEwa, ['kod' => $kodB, 'akcja' => 'cofnij-rezygnacje', 'powod' => 'pomyłka']);
    $t('cofnięcie rezygnacji: konto bez roli admin → 403', $kodHttp === 403);
    [$kodHttp] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kodB, 'akcja' => 'cofnij-rezygnacje']);
    $t('cofnięcie rezygnacji: bez powodu → 400', $kodHttp === 400);
    $pdo->exec("UPDATE $tabW SET zwrot_stan = 'zlecony' WHERE voucher_id = " . (int)($b['id'] ?? 0));
    [$kodHttp, $odp] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kodB, 'akcja' => 'cofnij-rezygnacje', 'powod' => 'pomyłka']);
    $t('cofnięcie rezygnacji: po zleceniu zwrotu → 409, bon zostaje wstrzymany', $kodHttp === 409 && $pdo->query("SELECT status FROM $tabB WHERE id = " . (int)($b['id'] ?? 0))->fetchColumn() === 'withdrawn', json_encode($odp, JSON_UNESCAPED_UNICODE));
    $pdo->exec("UPDATE $tabW SET zwrot_stan = 'oczekuje' WHERE voucher_id = " . (int)($b['id'] ?? 0));
    [$kodHttp] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kodB, 'akcja' => 'cofnij-rezygnacje', 'powod' => 'klient się wycofał']);
    $h = $historia((int)($b['id'] ?? 0));
    $t('cofnięcie rezygnacji: bon ważny, wiersz „anulowana”, wpis w historii', $kodHttp === 200
        && $pdo->query("SELECT status FROM $tabB WHERE id = " . (int)($b['id'] ?? 0))->fetchColumn() === 'valid'
        && $pdo->query("SELECT zwrot_stan FROM $tabW WHERE voucher_id = " . (int)($b['id'] ?? 0))->fetchColumn() === 'anulowana'
        && end($h)['akcja'] === 'rezygnacja-cofnieta' && str_contains(end($h)['szczegoly'], 'klient się wycofał'));
    [$kodHttp] = bon_panel_admin($k, $pdo, $sAdmin, ['kod' => $kodB, 'akcja' => 'cofnij-rezygnacje', 'powod' => 'drugi raz']);
    $t('cofnięcie rezygnacji: bon bez złożonej rezygnacji → 409', $kodHttp === 409);
    $pdo->prepare("UPDATE $tabB SET status = 'withdrawn' WHERE id = ?")->execute([$b['id'] ?? 0]); // dalsze testy na bonie po rezygnacji

    // token potwierdzenia: w bazie tylko skrót
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO ' . bon_tabela($k, 'voucher_withdrawal_tokens') . ' (token_hash, voucher_id, formularz_at, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $b['id'] ?? 0, $teraz, bon_teraz()->modify('+24 hours')->format('Y-m-d H:i:s')]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . bon_tabela($k, 'voucher_withdrawal_tokens') . ' WHERE token_hash = ?');
    $st->execute([$token]);
    $t('token rezygnacji: tabela przyjmuje skrót, sam token nie trafia do bazy', (int)$st->fetchColumn() === 0);

    // nowe kolumny vouchers (przedłużenie, przypomnienie) są w schemacie
    $kol = $pdo->query("SELECT expires_at_pierwotna, przedluzony_at, przypomnienie_at FROM $tabB WHERE id = " . (int)($b['id'] ?? 0))->fetch();
    $t('schemat: kolumny przedłużenia i przypomnienia (NULL na start)', is_array($kol) && $kol['przedluzony_at'] === null && $kol['przypomnienie_at'] === null);

    return $wyniki;
}
