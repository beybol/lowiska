# 043 — Wędkarz: powiązanie z kontem i potwierdzenie rezerwacji (mail, tokeny, strony portalu)

## Opis problemu
Operator zakłada rezerwację na dane wędkarza, który może nie mieć konta. Zgodnie z
[ADR-024](../../adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md) tożsamość (konto) jest
globalna, a relacja z łowiskiem (`fishery_anglers`) lokalna; konto powstaje **dopiero po kliknięciu linku
przez samego wędkarza**, a operator nigdy nie pisze do `users`. Zadanie dostarcza przepływ: mail, token,
strony potwierdzenia/rezygnacji i obsługę konfliktu telefonu. Specyfikacja: README makiet etapu 3, ekrany G i H.

## Wymagania
- Po zapisie rezerwacji z adresem e-mail wysyłamy wiadomość (layout z 040) z dwiema akcjami: **„Potwierdź i
  połącz z kontem"** oraz **„Zrezygnuj / to nie ja"**; link z tokenem (przechowywany hash, ważność
  `link_expires_at`), jednorazowy.
- Operator **zawsze widzi ten sam neutralny komunikat** niezależnie od tego, czy konto istnieje (brak
  enumeracji); powiązanie e-mail → konto tylko po weryfikowanym adresie, telefon jest wyłącznie podpowiedzią.
- Strony portalu pod tokenem (trasy w `PortalRoutes`): potwierdzenie (z założeniem/zalogowaniem konta lub
  powiązaniem z istniejącym) i rezygnacja; komunikaty dla tokenu wygasłego i zużytego.
- **Konflikt telefonu** (profil vs podany przez łowisko): wybór „zostaw z profilu / użyj nowego i zapisz
  w profilu / użyj nowego tylko dla tego łowiska" → `phone_override` w relacji.
- Kliknięcie „Potwierdź i połącz" **potwierdza także rezerwację** (ustawia `confirmed_at`,
  `confirmation_source`) — wniosek W3 z makiet, zatwierdzony razem z projektem.
- Rezerwacja bez e-maila (sam telefon) zostaje w stanie `unlinked` i nie dostaje maili.
- Limity zapytań (rate limiting) dla tras tokenowych i wysyłki; logowanie zdarzeń (wysłano, potwierdzono,
  zrezygnowano) w kanale `reservations`.
- Zmiany kontaktu po stronie operatora po powiązaniu i cofanie dostępu (`revoked`) — **poza zakresem**
  (etap 4, wniosek W9; do potwierdzenia przy `/review-task`).

## Kryteria akceptacji
- [x] Mail z dwoma przyciskami trafia do wędkarza po zapisie rezerwacji; token ma hash i termin ważności.
- [x] Komunikat dla operatora jest identyczny dla istniejącego i nieistniejącego konta (test).
- [ ] Potwierdzenie linkiem łączy relację z kontem i potwierdza rezerwację; ponowne użycie tokenu odrzucone.
- [x] „Zrezygnuj" anuluje rezerwację i zwalnia sloty; „To nie ja" unieważnia token i oznacza rezerwację do weryfikacji
      przez operatora (bez anulacji).
- [x] Konflikt telefonu obsługuje trzy warianty; `phone_override` zapisany tylko dla tego łowiska.
- [x] Rate limiting działa na trasach tokenowych (test).
- [x] Wszystkie nowe teksty po polsku i angielsku; strony zgodne z `strona-publiczna.md`.
- [x] Pełny pakiet testów (T3 — zmiana tras i potencjalnie `User`/provider logowania).

## Zakres testów
- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** tworzenie i wiązanie kont dotyka `User` i przepływu logowania (T3), a nowe trasy tokenowe
  w portalu. Jeśli migracja nie będzie potrzebna (schemat z 039), tier można obniżyć w `/review-task`.

## Zakres wyłączeń
- Zmiany kontaktu przez operatora po powiązaniu (`contact_change_proposals`), cofanie dostępu i jego UI (etap 4).
- Pełne konta wędkarzy, profil, historia rezerwacji wędkarza (etap 4).
- Pytania prawne o RODO (TODO-3 pkt 6) — rozstrzyga biznes; zadanie nie zakłada treści zgód.

## Zmiany dokumentacji
- [x] `docs/conventions/strona-publiczna.md` — trasy tokenowe
- [x] `docs/conventions/autoryzacja.md` — powiązanie konta wyłącznie przez wędkarza
- [x] `docs/project/DECYZJE-I-TODO-BIZNESOWE.md` — jeśli wyjdą nowe pytania prawne
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne
- Zależność: 039 (`fishery_anglers`), 040 (layout maili), 041 (miejsce wysyłki po zapisie).
- Brak workera kolejki (`sync`, ADR-004) — wysyłka w żądaniu, odporna na błąd SMTP (błąd nie blokuje rezerwacji).
- Cloud Run: linki budowane z jednego adresu bazowego aplikacji (zaufanie do proxy — 002).

## Rozstrzygnięcia
- **W3 i W9** (makiety §9) zatwierdzone: kliknięcie „Potwierdź i połącz" potwierdza też rezerwację; `contact_change_proposals`
  i cofanie dostępu (`revoked`) → etap 4 (w etapie 3 zostaje wartość enuma).
- **„To nie ja" ≠ anulacja.** Zgodnie z makietą H: „Zrezygnuj" anuluje rezerwację (powód opcjonalny, zwolnienie slotów);
  wariant „To nie ja" **unieważnia prośbę o powiązanie** (token) i **oznacza rezerwację do weryfikacji przez operatora**
  (`needs_contact_at`), nie anuluje jej. Kryterium akceptacji poprawione poniżej.
- **Dwa tokeny:** `fishery_anglers.link_token_hash` (potwierdzenie/powiązanie) i `reservations.manage_token_hash`
  (rezygnacja/„to nie ja"), oba jednorazowe, w bazie tylko hash, ważność z `link_expires_at`; kolumny z 039.
- **Konto bez hasła:** tworzone z zweryfikowanym adresem (`email_verified_at`), bez hasła; logowanie później przez reset
  hasła / Google (ADR-019). Operator nigdy nie zapisuje do `users`. Zadanie nie otwiera sesji po kliknięciu (brak profilu — etap 4).
- **Wysyłka** wywoływana przez serwis tworzący rezerwację w 041 po zatwierdzeniu transakcji, przechwytując wyjątki transportu.
- **Tier T3 pozostaje** (dotyka `User` — wyzwalacz).
- **Limit próśb na adres** (ADR-024 pkt 8): maks. 3 oczekujące prośby na adres w całym portalu i maks. 30 wysyłek na dobę
  na łowisko; przekroczenie → rezerwacja powstaje, mail nie jest wysyłany, rezerwacja trafia do „Do kontaktu".

## Powiązane ADR-y
- [ADR-024](../../adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md) — rozstrzygnięty.
