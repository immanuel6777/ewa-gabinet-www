<?php
// #259 (Backend, 27.11): strażnik sprzedaży – czy każde zapłacone zamówienie bonu ma bon i wysłany mail. Bon powstaje dwiema
// drogami (dzwonek z webhooka, cron co godzinę); gdy obie zawiodą, klient zapłacił, a bonu nie ma – strażnik ma to zauważyć sam.
// Liczy NA ŻĄDANIE (api/bon/straznik.php), a nie w cronie: martwy cron nie może zamrozić wyniku na „w porządku”.
// Pyta workflow GitHuba `.github/workflows/straznik.yml` (co godzinę 7–22, decyzja Bartka 27.11 „A1”) i przy alarmie zakłada
// kartę „Pilne” (issue z etykietą `straznik`). Odpowiedź: same liczby i skrócone id zamówień – bez e-maili i kwot.
// Wyłącznik: 'straznik' => false w lib/ustawienia.php albo w konfigu (wtedy { wlaczony: false }, bez alarmu).

declare(strict_types=1);
require_once __DIR__ . '/easytools.php';

const BON_STRAZNIK_MIN = 30;       // zapłacone zamówienie bez bonu (albo bon bez maila) starsze niż tyle minut = alarm
const BON_STRAZNIK_CRON_MIN = 75;  // ostatnia synchronizacja z crona (co godzinę) starsza niż tyle minut = alarm
const BON_STRAZNIK_DNI = 2;        // okno wstecz: przebieg o 7:00 musi widzieć zakupy z całej nocy
const BON_STRAZNIK_CRON_PLIK = '/bony-cron-ok.txt'; // w konfig/ obok bony.php; pisze lib/cron.php po każdej synchronizacji

/** Token strażnika: pochodna log_token (HMAC), więc bez nowego wpisu w konfigu; wyciek z GitHuba = dostęp tylko do tych liczb. */
function bon_straznik_token(array $k): ?string {
    $t = (string)($k['log_token'] ?? '');
    return strlen($t) >= 32 ? hash_hmac('sha256', 'straznik', $t) : null;
}

/** Znacznik „cron żyje”: po każdym powrocie z bon_synchronizuj (także „pominięte” – cron działał). Błąd zapisu nie przerywa crona. */
function bon_straznik_znacznik(array $k): void {
    if ((string)($k['_katalog'] ?? '') === '') return;
    @file_put_contents($k['_katalog'] . BON_STRAZNIK_CRON_PLIK, (string)time(), LOCK_EX);
}

/**
 * Wynik strażnika. Alarm, gdy choć jeden powód:
 *  - zamówienie `completed` produktu bonów od sync_od (z ostatnich BON_STRAZNIK_DNI dni), starsze niż BON_STRAZNIK_MIN min, bez bonu w bazie
 *    (także pominięte przez synchronizację „brak e-maila” – to też zapłacone zamówienie bez bonu);
 *  - bon `valid` bez wysłanego maila, starszy niż BON_STRAZNIK_MIN min (ostatnie 14 dni – tyle ponawia synchronizacja);
 *  - cron bez synchronizacji od ponad BON_STRAZNIK_CRON_MIN min albo bez znacznika;
 *  - sync_tryb inny niż 'bon' albo brak konfiguracji synchronizacji (bony z API nie powstają);
 *  - błąd API Easytools (nie da się sprawdzić).
 */
function bon_straznik(array $k, PDO $pdo, ?DateTimeImmutable $teraz = null): array {
    if (($k['straznik'] ?? true) === false) return ['wlaczony' => false, 'alarm' => false, 'powody' => []];
    $teraz ??= bon_teraz();
    $w = ['wlaczony' => true, 'alarm' => false, 'powody' => [], 'zamowien' => null, 'bez_bonu' => null, 'bez_bonu_id' => [],
        'bez_maila' => 0, 'cron_minut' => null, 'api_blad' => false];
    $granica = $teraz->modify('-' . BON_STRAZNIK_MIN . ' minutes');

    // 1. cron
    $plik = (string)($k['_katalog'] ?? '') . BON_STRAZNIK_CRON_PLIK;
    $czas = is_file($plik) ? (int)@file_get_contents($plik) : 0;
    if ($czas > 0) {
        $w['cron_minut'] = intdiv(max(0, $teraz->getTimestamp() - $czas), 60);
        if ($w['cron_minut'] > BON_STRAZNIK_CRON_MIN) $w['powody'][] = "cron: brak synchronizacji od {$w['cron_minut']} min";
    } else {
        $w['powody'][] = 'cron: brak znacznika synchronizacji (cron nie działa albo nie zapisuje w konfig/)';
    }

    // 2. zapłacone zamówienia bez bonu
    $tab = bon_tabela($k, 'vouchers');
    $tryb = (string)($k['sync_tryb'] ?? 'raport');
    $produkt = (string)($k['easytools_produkt_uuid'] ?? '');
    if ($tryb !== 'bon') $w['powody'][] = "konfiguracja: sync_tryb = $tryb – bony z API nie powstają";
    if (!bon_et_klucz_ok($k) || !preg_match('/^[0-9a-f-]{36}$/i', $produkt) || trim((string)($k['sync_od'] ?? '')) === '') {
        $w['powody'][] = 'konfiguracja: brak klucza API, produktu albo sync_od – nie da się sprawdzić zamówień';
    } else {
        try {
            $od = new DateTimeImmutable((string)$k['sync_od'], new DateTimeZone(BON_STREFA));
            $okno = $teraz->modify('-' . BON_STRAZNIK_DNI . ' days');
            $start = $od > $okno ? $od : $okno;
            $zamowienia = [];
            for ($strona = 1; $strona <= 5; $strona++) {
                $lista = bon_et_get($k, '/orders', ['status' => 'completed', 'product' => $produkt,
                    'created_from' => $start->modify('-1 day')->format('Y-m-d'), 'per_page' => 100, 'page' => $strona]);
                foreach ((array)($lista['items'] ?? []) as $z) {
                    if (!is_array($z) || !is_string($z['id'] ?? null) || !is_string($z['created_at'] ?? null)) continue;
                    $utw = new DateTimeImmutable($z['created_at']);
                    if ($utw < $start) continue;     // przed sync_od (testy) albo poza oknem
                    if ($utw > $granica) continue;   // świeże – synchronizacja ma jeszcze czas
                    $zamowienia[strtolower($z['id'])] = $utw;
                }
                if ($strona >= (int)($lista['pagination']['total_pages'] ?? 1)) break;
            }
            $w['zamowien'] = count($zamowienia);
            $brak = [];
            if ($zamowienia) {
                $st = $pdo->prepare("SELECT DISTINCT LOWER(easytools_order_id) FROM $tab WHERE LOWER(easytools_order_id) IN ("
                    . implode(',', array_fill(0, count($zamowienia), '?')) . ')');
                $st->execute(array_keys($zamowienia));
                $sa = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
                foreach ($zamowienia as $id => $utw) if (!isset($sa[$id])) $brak[$id] = $utw;
            }
            $w['bez_bonu'] = count($brak);
            ksort($brak);
            $w['bez_bonu_id'] = array_map(static fn($id) => substr((string)$id, 0, 8), array_slice(array_keys($brak), 0, 5));
            if ($brak) {
                $najstarsze = intdiv($teraz->getTimestamp() - min(array_map(static fn($d) => $d->getTimestamp(), $brak)), 60);
                $w['powody'][] = 'sprzedaż: ' . count($brak) . ' zapłacone zamówienie(-a) bez bonu (najstarsze ' . $najstarsze . ' min)';
            }
        } catch (Throwable $e) {
            $w['api_blad'] = true;
            $opis = preg_replace('/[^\s@"]+@[^\s@"]+\.[^\s@"]+/', '***', mb_substr($e->getMessage(), 0, 120)) ?? '';
            $w['powody'][] = 'API Easytools: nie da się sprawdzić zamówień (' . $opis . ')';
        }
    }

    // 3. bony bez wysłanego maila (klient zapłacił, bon jest, ale PDF nie doszedł)
    $st = $pdo->prepare("SELECT COUNT(*) FROM $tab WHERE status = 'valid' AND mail_wyslany_at IS NULL AND created_at <= ? AND created_at >= ?");
    $st->execute([$granica->format('Y-m-d H:i:s'), $teraz->modify('-14 days')->format('Y-m-d H:i:s')]);
    $w['bez_maila'] = (int)$st->fetchColumn();
    if ($w['bez_maila'] > 0) $w['powody'][] = "mail: {$w['bez_maila']} bon(y) bez wysłanego maila od ponad " . BON_STRAZNIK_MIN . ' min';

    $w['alarm'] = $w['powody'] !== [];
    return $w;
}
