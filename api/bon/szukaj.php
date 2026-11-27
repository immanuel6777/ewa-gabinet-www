<?php
// Panel realizacji bonów – wyszukiwanie (krok 4: 2026-11-23). Tylko po zalogowaniu (każda rola), JSON + X-CSRF.
//   POST {q} – numer bonu (→ {typ: kod, kod}), e-mail kupującego albo id zamówienia Easytools (→ {typ, bony: [...], wiecej}).
//   POST {q, fragment: true} – karta #201: fragment e-maila albo id zamówienia (min. 3 znaki, admin i Ewa) → {typ: fragment, bony (z „email”), wiecej}.
//   POST {q, fragment: true, segment: 1|2} – Bartek 27.11: 4 znaki numeru bonu na pozycji (1 = po prefiksie, 2 = na końcu), tylko numer;
//   te same uprawnienia, limit i kształt odpowiedzi co fragment; zły segment albo q (nie 4 znaki z alfabetu numeru) → 400.
//   #227: karta w `bony` dla admina i Gabinetu ma `zwrot: {kwota: "5.80", stan: oczekuje|zlecony|potwierdzony|nieudany, przeliczona}` albo null
//   (bez rezygnacji / anulowana) – jak `rezygnacja` w historia.php; kasa (tryb Bon) – bez pola.
// POST, nie GET: e-mail kupującego nie trafia do logów serwera ani historii przeglądarki. Logika: lib/panel.php.

declare(strict_types=1);
require __DIR__ . '/lib/panel-http.php';

[$k, $pdo, $token] = bon_http_start(['POST']);
try {
    $sesja = bon_http_wymagaj_sesji($k, $pdo, $token);
    $d = bon_http_json_body($sesja);
    $fragment = !empty($d['fragment']); // #201: szukanie po fragmencie adresu / zamówienia (admin i Ewa)
    $segment = $d['segment'] ?? null; // 1 | 2 (liczba z JSON); inna wartość → 400 w bon_panel_szukaj
    [$kod, $odp] = bon_panel_szukaj($k, $pdo, (string)($d['q'] ?? ''), null, $sesja, $fragment, $segment); // P-10: poza adminem numery zamaskowane
    bon_json($kod, $odp);
    // bez wpisanego tekstu (e-mail = dane osobowe): tylko rodzaj wyszukiwania i liczba wyników. Wyjątek #201: przy fragmencie ślad,
    // CO wpisano – klucz z „mail”, więc log.php go ukrywa (diagnostyka.php), a w pliku zdarzeń na serwerze zostaje do wglądu Bartka
    bon_zdarzenie($k, 'szukaj', ['http' => $kod, 'kto' => $sesja['login'], 'typ' => $fragment ? 'fragment' : (string)($odp['typ'] ?? ''), 'wynikow' => count($odp['bony'] ?? [])]
        + ($segment !== null ? ['segment' => is_int($segment) ? $segment : 'zly'] : [])
        + ($fragment ? ['szukany_fragment_mail' => mb_substr(trim((string)($d['q'] ?? '')), 0, 100)] : []));
} catch (Throwable $e) {
    error_log('bony/szukaj: ' . $e->getMessage());
    bon_zdarzenie($k, 'blad', ['plik' => 'szukaj'] + bon_opis_bledu($e));
    bon_json(500, ['ok' => false, 'blad' => 'Błąd serwera. Spróbuj za chwilę.']);
}
