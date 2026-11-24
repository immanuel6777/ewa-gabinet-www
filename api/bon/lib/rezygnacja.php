<?php
// P-12 (2026-11-24, wpis doradcy 16:50, decyzja Bartka): rezygnacja zgłoszona przez kupującego na stronie /bon.
// W ciągu 14 dni od zapłaty zgłoszenie jest oświadczeniem o odstąpieniu – tworzy od razu zwykłą rezygnację (ten sam stan co
// rejestrowana w panelu): voucher_withdrawals z kanal = 'strona', zwrot_stan = 'oczekuje', bon 'withdrawn'. Bez migracji.
// Pomyłkę obsługuje istniejące „Cofnij rezygnację” w panelu; zwrot pieniędzy na start ręcznie (Easytools + „Oznacz zwrot”).
// Kontrakt z frontem: POST /api/bon/rezygnacja.php {kod, email} → {ok:true, kwota} | {ok:false, powod: email|termin|saldo|stan|limit}.
// Zły adres e-mail: odpowiedź JAWNA (powod 'email'), żeby kupujący z literówką wiedział, co poprawić; zgadywanie ogranicza
// limit 5 prób na bon na godzinę (+ limit na IP jak verify.php).

declare(strict_types=1);
require_once __DIR__ . '/bon.php';
require_once __DIR__ . '/mail.php';

const BON_REZYGNACJA_PROB_NA_GODZINE = 5;

/**
 * [kod HTTP, odpowiedź dla strony, ?kontekst do maili]. Kontekst (po sukcesie): bon (wiersz), kwota (grosze), zgloszono.
 * Adres e-mail kupującego nie trafia do odpowiedzi ani do dziennika.
 */
// $krok (2026-11-24, wpis 16:47): 'sprawdz' = te same warunki i ten sam licznik prób, ale NIC nie zapisuje (strona pokazuje okno
// potwierdzenia dopiero po poprawnym adresie, z kwotą do zwrotu); 'zglos' = zgłoszenie (serwer sprawdza wszystko od nowa).
function bon_rezygnacja_ze_strony(array $k, PDO $pdo, string $wpisanyKod, string $email, string $ip, ?DateTimeImmutable $teraz = null, string $krok = 'zglos'): array {
    $odmowa = static fn(int $http, string $powod) => [$http, ['ok' => false, 'powod' => $powod], null];
    $kod = bon_normalizuj_kod($wpisanyKod, (string)$k['kod_prefix']);
    if ($kod === null) return $odmowa(400, 'stan');
    if (!bon_limit_ok($pdo, $k, $ip, 'rezygnacja-ip', (int)($k['verify_limit_na_minute'] ?? 20))) return $odmowa(429, 'limit');
    // limit na bon liczy tylko NIEUDANE sprawdzenia adresu (prośba frontu do #74): udane podejście (sprawdz + zglos) nie zużywa
    // prób, a zgadywanie adresu nadal blokuje 5 pomyłek na godzinę. Tu tylko odczyt licznika; zwiększenie – przy złym adresie.
    if (bon_limit_licznik($pdo, $k, 'bon|' . $kod, 'rezygnacja-bon', 3600) >= BON_REZYGNACJA_PROB_NA_GODZINE) return $odmowa(429, 'limit');
    $teraz ??= bon_teraz();
    $tabB = bon_tabela($k, 'vouchers');
    $tabW = bon_tabela($k, 'voucher_withdrawals');

    $pdo->beginTransaction();
    try {
        $zablokuj = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $st = $pdo->prepare("SELECT * FROM $tabB WHERE code = ?" . $zablokuj);
        $st->execute([$kod]);
        $bon = $st->fetch();
        if (!$bon) { $pdo->rollBack(); return $odmowa(404, 'stan'); }
        $status = bon_status_efektywny($bon, $teraz);
        $saldo = (int)bon_grosze($bon['balance_pln']);
        if ($status === 'expired') { $pdo->rollBack(); return $odmowa(409, 'termin'); }
        if ($status === 'used' || ($status === 'valid' && $saldo <= 0)) { $pdo->rollBack(); return $odmowa(409, 'saldo'); }
        if ($status !== 'valid') { $pdo->rollBack(); return $odmowa(409, 'stan'); } // już rezygnacja, wstrzymany, zwrócony
        if (!bon_rezygnacja_w_terminie($bon, $teraz->format('Y-m-d'))) { $pdo->rollBack(); return $odmowa(409, 'termin'); }
        // adres z zakupu: bez wielkości liter i spacji na brzegach
        if (mb_strtolower(trim($email)) === '' || mb_strtolower(trim($email)) !== mb_strtolower(trim((string)$bon['buyer_email']))) {
            $pdo->rollBack();
            bon_limit_ok($pdo, $k, 'bon|' . $kod, 'rezygnacja-bon', BON_REZYGNACJA_PROB_NA_GODZINE, 3600); // licz pomyłkę
            return $odmowa(400, 'email');
        }
        $st = $pdo->prepare("SELECT zwrot_stan FROM $tabW WHERE voucher_id = ?");
        $st->execute([$bon['id']]);
        $stan = $st->fetchColumn();
        if ($stan !== false && $stan !== 'anulowana') { $pdo->rollBack(); return $odmowa(409, 'stan'); }

        $kwota = bon_kwota_zwrotu($bon, $saldo); // wpis 18:00: proporcjonalnie do zapłaconej ceny
        if ($krok === 'sprawdz') { // krok 1: wszystko się zgadza – kwota do zwrotu, bez zapisu
            $pdo->rollBack();
            // „krok” w odpowiedzi (prośba frontu): stary serwer na krok=sprawdz robił pełne zgłoszenie i odpowiadał {ok, kwota} –
            // po tym polu front odróżnia „sprawdzone” od „już zgłoszone”
            return [200, ['ok' => true, 'kwota' => bon_zl($kwota), 'krok' => 'sprawdz'], null];
        }
        $chwila = $teraz->format('Y-m-d H:i:s');
        $wartosci = [$chwila, $chwila, 'strona', 'klient', bon_zl($saldo), bon_zl($kwota), $bon['id']];
        if ($stan === false) {
            $pdo->prepare("INSERT INTO $tabW (zlozono_at, potwierdzono_at, kanal, kto, saldo_pln, kwota_pln, voucher_id) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute($wartosci);
        } else {
            $pdo->prepare("UPDATE $tabW SET zlozono_at = ?, potwierdzono_at = ?, kanal = ?, kto = ?, saldo_pln = ?, kwota_pln = ?, zwrot_stan = 'oczekuje',
                           zwrot_zlecono_at = NULL, zwrot_kwota_pln = NULL, zwrot_potwierdzono_at = NULL, zwrot_ref = NULL, zwrot_blad = NULL
                           WHERE voucher_id = ? AND zwrot_stan = 'anulowana'")->execute($wartosci);
        }
        $pdo->prepare("UPDATE $tabB SET status = 'withdrawn' WHERE id = ? AND status = 'valid'")->execute([$bon['id']]);
        $zwrotDo = $teraz->modify('+14 days')->format('Y-m-d');
        bon_historia($k, $pdo, (int)$bon['id'], 'rezygnacja', 'zgłoszona na stronie /bon ' . $teraz->format('d.m.Y H:i') . ', saldo ' . bon_zl($saldo)
            . ' zł, do zwrotu ' . bon_zl($kwota) . " zł (pieniądze do $zwrotDo)", 'klient', $teraz);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [200, ['ok' => true, 'kwota' => bon_zl($kwota)], ['bon' => $bon, 'kwota' => $kwota, 'zgloszono' => $teraz]];
}

/**
 * Dwa maile po rezygnacji ze strony (wpis 16:50): do kupującego (potwierdzenie) i do obsługi (mail_obsluga = bony@, Reply-To =
 * kupujący, link do panelu). Ta sama droga co mail z bonem (Mailjet, przy zerwaniu zapas SMTP OVH). Nieudana wysyłka nie cofa
 * rezygnacji – alert na mail_powiadomienie (alerty@). Zwraca ['kupujacy' => ?błąd, 'obsluga' => ?błąd].
 */
function bon_rezygnacja_maile(array $k, array $ctx, ?PDO $pdo = null): array {
    $bon = $ctx['bon'];
    $kod = (string)$bon['code'];
    $kwota = bon_kwota_tekst((int)$ctx['kwota']);
    $kiedy = $ctx['zgloszono']->format('d.m.Y') . ' o ' . $ctx['zgloszono']->format('H:i');
    $wynik = [];
    $wynik['kupujacy'] = bon_rezygnacja_potwierdzenie($k, $pdo, $bon, (int)$ctx['kwota'], $kiedy, 'system', false);

    $obsluga = array_values(array_filter((array)($k['mail_obsluga'] ?? [])));
    if ($obsluga) {
        $kZ = array_replace($k, ['mail_odpowiedz' => (string)$bon['buyer_email']]); // odpowiedź z bony@ trafia prosto do kupującego
        $tekstO = "Rezygnacja zgłoszona na stronie /bon $kiedy.\n\nBon: $kod\nKwota do zwrotu: $kwota (saldo z chwili zgłoszenia przeliczone do zapłaconej ceny)\n"
            . 'Zamówienie Easytools: ' . $bon['easytools_order_id'] . "\nData zakupu: " . substr((string)$bon['created_at'], 0, 16) . "\n"
            . 'Panel: https://www.ewa-gabinet.pl/panel-bonow?nr=' . rawurlencode($kod) . "\n\n"
            . "Zwrot: w Easytools (transakcja zamówienia), potem w panelu „Oznacz zwrot”. Pieniądze w ciągu 14 dni od zgłoszenia.\n"
            . "Odpowiedź na tę wiadomość trafi do kupującego.\n";
        foreach ($obsluga as $do) $wynik['obsluga'] = bon_mail_prosty($kZ, (string)$do, "Rezygnacja z bonu $kod (zgłoszona na stronie)", $tekstO, [
            'naglowek' => 'Rezygnacja ze strony /bon', 'wewnetrzny' => true,
            'przyciski' => [['Otwórz bon w panelu', 'https://www.ewa-gabinet.pl/panel-bonow?nr=' . rawurlencode($kod)]],
        ]);
    }
    $bledy = array_filter($wynik);
    if ($bledy) {
        bon_powiadom($k, 'Bon: nie wysłano maila o rezygnacji – ' . $kod, "Rezygnacja ze strony jest zapisana (bon zablokowany), ale mail nie wyszedł:\n"
            . implode("\n", array_map(static fn($kto, $b) => "$kto: $b", array_keys($bledy), $bledy)) . "\nPotwierdź kupującemu ręcznie.");
    }
    return $wynik;
}

// --- potwierdzenie rezygnacji do kupującego (2026-11-24, wpisy 17:33 i 17:35: § 14 ust. 18a obiecuje mail przy KAŻDEJ rezygnacji) ---
// Ten sam mail dla rezygnacji ze strony /bon, zarejestrowanej w panelu (mail / papier) i wysłanej ponownie. Wynik wysyłki
// zapisany w historii bonu (akcja 'potwierdzenie'): „wysłane na adres … (GG:MM)” albo „NIE wysłane: …” – z tego panel pokazuje
// stałą linię przy rezygnacji. Nieudana wysyłka: alert na mail_powiadomienie (alerty@), chyba że $bezAlertu.

/** Wyślij potwierdzenie rezygnacji. $kiedy – data (i godzina) zgłoszenia jako tekst. Zwraca ?błąd. */
function bon_rezygnacja_potwierdzenie(array $k, ?PDO $pdo, array $bon, int $kwotaGr, string $kiedy, string $kto, bool $alert = true): ?string {
    $kod = (string)$bon['code'];
    $kwota = bon_kwota_tekst($kwotaGr);
    $pomoc = trim((string)($k['telefon_pomoc_bony'] ?? ''));
    $godz = trim((string)($k['telefon_pomoc_bony_godziny'] ?? ''));
    $tel = $pomoc !== '' ? ' albo zadzwonić: ' . $pomoc . ($godz !== '' ? " ($godz)" : '') : '';
    // wpis 18:00: bon kupiony z rabatem – kwota niższa niż saldo, bo przeliczona do zapłaconej ceny
    $przel = $kwotaGr < (int)bon_grosze($bon['balance_pln']) ? ' (saldo bonu przeliczone do zapłaconej ceny)' : '';
    // wpis 19:26: „to pomyłka” = mailto na bony@ z gotowym tematem i treścią; cofa Ewa albo Bartek w panelu (samoobsługa linkiem – po starcie)
    $mailtoDo = trim((string)($k['mail_odpowiedz'] ?? '')) ?: 'bony@ewa-gabinet.pl';
    $mailto = 'mailto:' . $mailtoDo . '?subject=' . rawurlencode("Cofnięcie rezygnacji – bon $kod")
        . '&body=' . rawurlencode("Dzień dobry,\n\nproszę o cofnięcie rezygnacji z bonu $kod i zostawienie bonu aktywnego (zmieniam zdanie / to nie ja zgłaszałem(-am) rezygnację).\n\nPozdrawiam");
    $tekst = "Dzień dobry,\n\nprzyjęliśmy rezygnację z bonu $kod zgłoszoną $kiedy. Kwota do zwrotu: $kwota$przel. "
        . "Pieniądze wrócą tą samą metodą płatności w ciągu 14 dni. Bon od tej chwili nie działa.\n\n"
        . "Zmieniasz zdanie albo to nie Ty zgłaszałeś(-aś) rezygnację? Napisz na $mailtoDo (temat: Cofnięcie rezygnacji – bon $kod), "
        . "a zostawimy bon aktywny. Możesz też odpisać na tę wiadomość$tel.\n\nPozdrawiamy serdecznie\nEWA Gabinet podologiczny\nhttps://www.ewa-gabinet.pl\n";
    $adres = (string)$bon['buyer_email'];
    $blad = bon_mail_prosty($k, $adres, "Rezygnacja z bonu $kod – potwierdzenie", $tekst, [
        'naglowek' => 'Rezygnacja z bonu', 'przyciski' => [['Zmieniam zdanie / to nie ja – zostaw bon aktywny', $mailto]],
    ]);
    if ($pdo) {
        bon_historia($k, $pdo, (int)$bon['id'], 'potwierdzenie', $blad === null
            ? 'wysłane na adres ' . $adres . ' (' . bon_teraz()->format('H:i') . ')'
            : 'NIE wysłane: ' . mb_substr($blad, 0, 150), $kto);
    }
    if ($blad !== null && $alert) {
        bon_powiadom($k, 'Bon: potwierdzenie rezygnacji NIE wyszło – ' . $kod, "Rezygnacja jest zapisana (bon zablokowany), ale mail z potwierdzeniem do kupującego nie wyszedł.\n"
            . "Bon: $kod\nBłąd: $blad\nW panelu przy rezygnacji: „Wyślij potwierdzenie ponownie”.");
    }
    return $blad;
}

/** Ostatni wynik wysyłki potwierdzenia rezygnacji (z historii, po zgłoszeniu): ['wyslane' => bool, 'opis' => tekst, 'kiedy'] albo null. */
function bon_rezygnacja_stan_potwierdzenia(array $k, PDO $pdo, int $voucherId, string $odKiedy): ?array {
    $st = $pdo->prepare('SELECT szczegoly, kiedy FROM ' . bon_tabela($k, 'voucher_admin_log') . " WHERE voucher_id = ? AND akcja = 'potwierdzenie' AND szczegoly NOT LIKE 'wycofanie:%' AND kiedy >= ? ORDER BY kiedy DESC, id DESC LIMIT 1");
    $st->execute([$voucherId, $odKiedy]);
    $r = $st->fetch();
    return $r ? ['wyslane' => str_starts_with((string)$r['szczegoly'], 'wysłane'), 'opis' => (string)$r['szczegoly'], 'kiedy' => (string)$r['kiedy']] : null;
}

/**
 * Panel: potwierdzenie rezygnacji zarejestrowanej w panelu (automatycznie po zapisie) albo wysłane ponownie (przycisk).
 * [kod HTTP, odpowiedź z 'potwierdzenie' => ['wyslane', 'adres', 'godzina'] i ewentualnym 'blad'].
 */
function bon_panel_wyslij_potwierdzenie(array $k, PDO $pdo, array $sesja, string $wpisany): array {
    if ($sesja['rola'] !== 'admin' && empty($sesja['rezygnacje'])) return [403, ['ok' => false, 'blad' => 'Potwierdzenie rezygnacji wysyłają Bartek albo Ewa.']];
    $kod = bon_normalizuj_kod($wpisany, (string)$k['kod_prefix']);
    $st = $pdo->prepare('SELECT * FROM ' . bon_tabela($k, 'vouchers') . ' WHERE code = ?');
    $st->execute([(string)$kod]);
    $bon = $st->fetch();
    if (!$bon) return [404, ['ok' => false, 'blad' => 'Nie ma bonu o tym numerze.']];
    $st = $pdo->prepare('SELECT * FROM ' . bon_tabela($k, 'voucher_withdrawals') . " WHERE voucher_id = ? AND zwrot_stan <> 'anulowana'");
    $st->execute([$bon['id']]);
    $w = $st->fetch();
    if (!$w) return [409, ['ok' => false, 'blad' => 'Ten bon nie ma zarejestrowanej rezygnacji.']];
    $zl = new DateTimeImmutable((string)$w['zlozono_at'], new DateTimeZone(BON_STREFA));
    $kiedy = $zl->format('d.m.Y') . match ((string)$w['kanal']) {
        'strona' => ' o ' . $zl->format('H:i') . ' na stronie /bon', 'mail' => ' (mailem)', default => ' (w gabinecie)',
    };
    $blad = bon_rezygnacja_potwierdzenie($k, $pdo, $bon, (int)bon_grosze($w['kwota_pln']), $kiedy, $sesja['login']);
    $wynik = ['wyslane' => $blad === null, 'adres' => (string)$bon['buyer_email'], 'godzina' => bon_teraz()->format('H:i')];
    return [200, ['ok' => $blad === null, 'potwierdzenie' => $wynik] + ($blad === null ? [] : ['blad' => 'Potwierdzenie NIE wyszło: ' . $blad])];
}

/**
 * Po cofnięciu rezygnacji: mail „rezygnacja wycofana, bon znów działa” – tylko gdy potwierdzenie rezygnacji wcześniej wyszło.
 * Zwraca null, gdy maila nie było po co wysyłać, inaczej ['wyslane' => bool, 'adres', 'blad' => ?tekst].
 */
function bon_rezygnacja_wycofanie_mail(array $k, PDO $pdo, array $bon, string $zlozono, string $kto): ?array {
    $stan = bon_rezygnacja_stan_potwierdzenia($k, $pdo, (int)$bon['id'], $zlozono);
    if (!$stan || !$stan['wyslane']) return null; // kupujący nie dostał potwierdzenia – nie ma czego odwoływać
    $kod = (string)$bon['code'];
    $tekst = "Dzień dobry,\n\nrezygnacja z bonu $kod została wycofana. Bon znów działa – saldo i data ważności bez zmian.\n\n"
        . "Jeśli to pomyłka, odpisz na tę wiadomość.\n\nPozdrawiamy serdecznie\nEWA Gabinet podologiczny\nhttps://www.ewa-gabinet.pl\n";
    $blad = bon_mail_prosty($k, (string)$bon['buyer_email'], "Bon $kod znów działa – rezygnacja wycofana", $tekst, ['naglowek' => 'Bon znów działa']);
    bon_historia($k, $pdo, (int)$bon['id'], 'potwierdzenie', $blad === null ? 'wycofanie: wysłane na adres ' . $bon['buyer_email'] : 'wycofanie: NIE wysłane: ' . mb_substr($blad, 0, 150), $kto);
    if ($blad !== null) {
        bon_powiadom($k, 'Bon: mail o wycofaniu rezygnacji NIE wyszedł – ' . $kod, "Rezygnacja cofnięta (bon znów ważny), ale mail do kupującego nie wyszedł.\nBon: $kod\nBłąd: $blad\nNapisz do kupującego ręcznie.");
    }
    return ['wyslane' => $blad === null, 'adres' => (string)$bon['buyer_email'], 'blad' => $blad];
}

/**
 * Mail bez załączników tą samą drogą co mail z bonem: Mailjet, przy zerwaniu połączenia zapas SMTP OVH. Zwraca ?błąd.
 * HTML w szablonie maila z bonem (wpis 19:26, bon_mail_szablon); $o: naglowek, przyciski [[tekst, adres]], wewnetrzny (bony@, alerty@).
 */
function bon_mail_prosty(array $k, string $do, string $temat, string $tekst, array $o = []): ?string {
    $html = bon_mail_szablon((string)($o['naglowek'] ?? 'Bon podarunkowy'), bon_mail_tekst_na_html($tekst), (array)($o['przyciski'] ?? []), !empty($o['wewnetrzny']));
    $odp = bon_zapas_wymuszony($k)
        ? ['blad' => 'test zapasu', 'polaczenie' => true]
        : bon_mailjet_wyslij($k, $do, $temat, $tekst, $html, []);
    if ($odp['blad'] !== null && !empty($odp['polaczenie']) && bon_zapas_wlaczony($k)) {
        $w = bon_mail_wiadomosc($k, $do, $temat, $tekst, $html, []);
        $s = $w === null ? ['blad' => 'zły mail_nadawca'] : bon_smtp_wyslij($k, $w);
        return $s['blad'] === null || !empty($s['mozliwe_wyslane']) ? null : 'Mailjet: ' . $odp['blad'] . ' | SMTP: ' . $s['blad'];
    }
    return $odp['blad'] === null ? null : (string)$odp['blad'];
}
