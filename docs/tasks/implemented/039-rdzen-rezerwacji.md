# 039 — Rdzeń rezerwacji: model, sloty, zamrożone warunki, zapis z blokadą

## Opis problemu
System zna konfigurację sprzedaży (stanowiska, sezony, reguły, cennik, usługi, blokady), ale **nie ma
rezerwacji**. Bez nich nie da się odpowiedzieć na pytanie „czy stanowisko jest wolne" (`PositionAvailability`
nie ma powodu `Occupied`), a każdy z kanałów — operator (etap 3) i wędkarz (etap 4) — musiałby budować własną
ścieżkę zapisu. Zadanie zakłada warstwę danych i jedną ścieżkę zapisu, na której stoją zadania 040–046.
Decyzje: [ADR-024](../../adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md),
[ADR-025](../../adr/ADR-025-zajetosc-stanowiska-jako-sloty-przedzialowe.md),
[ADR-026](../../adr/ADR-026-zamrozenie-warunkow-rezerwacji.md), aktualizacja
[ADR-015](../../adr/ADR-015-warstwa-oferty-pobytu.md); wnioski dla modelu: `docs/project/mockups/etap3/README.md` §9.

## Wymagania
- Tabele i modele:
  - `fishery_anglers` — relacja wędkarza z łowiskiem (ADR-024): `fishery_id`, `user_id` (nullable),
    `status` (unlinked/pending/linked/revoked), `given_name`, `email`, `phone`, `phone_override`, `notes`,
    `link_token_hash`, `link_expires_at`, `linked_at`, `revoked_at`. **Sam kształt i zapis**; przepływ
    powiązania z kontem to zadanie 043.
  - `reservations` — `fishery_id`, `position_id`, `fishery_angler_id`, `status`, `payment_status`
    (opłacona / nieopłacona / na miejscu / zwrot), `confirm_by`, `confirmed_at`, `confirmation_source`,
    `source` (operator/admin; kanał online dojdzie w etapie 4), `created_by`, `internal_note`,
    pola anulowania (`cancelled_at`, `cancelled_by`, `cancel_reason`, `cancel_message`) oraz
    `needs_contact_at`, `contacted_at` (lista „Do kontaktu", ADR-027).
  - `reservation_slots` — `reservation_id`, `position_id`, `slot_type` (`fishing_day`), `starts_at`,
    `ends_at` (UTC); `unique(position_id, starts_at)` jako siatka bezpieczeństwa (ADR-025).
  - `reservation_terms` (1:1) — kolumny skalarne (kwoty, waluta, liczba uczestników, `schema_version`) +
    JSON dla rozbicia ceny, polityki zwrotu, atrybutów stanowiska, pozycji usług i wyjątków od reguł;
    dokumenty przez id wersji (ADR-026, ADR-017).
- **Jedna ścieżka zapisu** (serwis): blokada wiersza stanowiska (`lockForUpdate`) → ponowne sprawdzenie
  `PositionAvailability` → zapis rezerwacji, slotów i terms w jednej transakcji; liczniki usług z
  limitem egzemplarzy zliczane pod tą samą blokadą; pojemność jako walidacja. Anulacja i wygaśnięcie
  usuwają sloty (rezerwacja zostaje w historii).
- `PositionAvailability`: nowy powód `SaleUnavailabilityReason::Occupied`, kolejność: stan stanowiska →
  okres sprzedaży → blokada → **zajętość** → zawieszenia cech (ADR-012). Semantyka przedziałów jak blokady.
- **Wyjątki operatora** (cena uzgodniona, naruszenie reguł sprzedaży, rezerwacja na zablokowanym stanowisku)
  zapisywane w `reservation_terms` z powodem, kto i kiedy; kanał wędkarza nigdy nie omija blokady (ADR-027 pkt 7).
- **Wycena usług dodatkowych w warstwie oferty** (aktualizacja ADR-015): `StayOffer` zwraca także pozycje usług
  (jednostka rozliczenia, liczba, limit egzemplarzy).
- Kanał dziennika zmian `reservations` (Spatie activitylog), niepodlegający czyszczeniu; zmiana warunków tylko
  jawną, logowaną operacją (ADR-026).
- Brak interfejsu — serwis i modele weryfikowane testami; ekrany w zadaniach 041–042.

## Kryteria akceptacji
- [x] Migracje tworzą 5 tabel (`fishery_anglers`, `reservations`, `reservation_slots`, `reservation_terms`,
      `reservation_service_items`) z ograniczeniami z wymagań, bez rozbicia na kilka rewizji.
- [x] Zapis rezerwacji przechodzi wyłącznie przez serwis; dwa równoległe zapisy tego samego terminu na tym
      samym stanowisku — drugi dostaje `Occupied` (test na dwóch połączeniach lub symulacji blokady).
- [x] Częściowe nakładanie (13–15 vs 14–16) jest odrzucane; przyleganie (13–15, 15–17) dozwolone.
- [x] `PositionAvailability` zwraca `Occupied` dla zajętej doby; istniejące testy 016–019 i portalu zielone.
- [x] `reservation_terms` kopiuje cenę z rozbiciem, politykę zwrotu i atrybuty; późniejsza zmiana cennika
      nie zmienia istniejącej rezerwacji (test).
- [x] Wyjątki operatora zapisują powód, autora i czas; kanał wędkarza nie przepuszcza naruszenia blokady.
- [x] Anulacja i wygaśnięcie usuwają sloty; rezerwacja i terms zostają.
- [x] `StayOffer` wycenia usługi dodatkowe; testy 020 zielone.
- [x] Wpisy w kanale `reservations` dla utworzenia, zmiany warunków i anulacji.
- [x] Pełny pakiet testów (T3 — migracje).

## Zakres testów
- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** migracje (obowiązkowy wyzwalacz T3) oraz zmiana `PositionAvailability`, na której stoją
  kalendarz 019, portal 033 i usługi 020.

## Zakres wyłączeń
- Ekrany operatora (041), kalendarz (042), przepływ powiązania z kontem i maile (043, 040), kolizje (044),
  ustawienia i wygasanie (045), publiczna dostępność (046).
- Kanał online, koszyk, blokada czasowa zamówienia, płatności i faktyczne zwroty (etap 4).
- Nowe typy slotów (półdobowe) — tylko punkt rozszerzenia (`slot_type`), bez zmiany sprzedawalności/cennika.
- Zmiana nazw istniejących klas „doba".

## Zmiany dokumentacji
- [x] `docs/conventions/dostepnosc.md` — powód `Occupied`, kolejność sprawdzania, ścieżka zapisu
- [x] `docs/conventions/dziennik-zmian.md` — kanał `reservations`
- [x] `docs/conventions/cennik.md` — pozycje usług w ofercie
- [x] `docs/adr/ADR-015-warstwa-oferty-pobytu.md` — jeśli wdrożenie odbiega od aktualizacji z 08.10
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne
- Laravel 13, MySQL; schemat testowy `lowiska_test` (pięć warstw izolacji — `CLAUDE.md`).
- Czas w UTC w bazie; konwersja na strefę łowiska przez istniejący „jeden dom strefy czasowej" (024).
- Brak workera kolejki (`sync`, ADR-004) — serwis nie zakłada zadań w tle.

## Rozstrzygnięcia
- **Nazwy kolumn `fishery_anglers`:** `given_name`, `given_email`, `given_phone` (jak w ADR-024 pkt 1), nie `email`/`phone`
  z pierwszej wersji wymagań. Unikalność: `(fishery_id, user_id)` dla niepustych oraz `(fishery_id, znormalizowany given_email)`
  dla niepustych (kolumna wyliczana albo normalizacja w modelu — wybór implementacji).
- **`contact_snapshot`** (ADR-024 pkt 6) to kolumna JSON w `reservation_terms` (ADR-026 pkt 1); wypełniana przy zapisie
  efektywnymi danymi kontaktowymi wędkarza.
- **`reservations.manage_token_hash`** (nullable, ADR-024 pkt 8) — token „Zrezygnuj / to nie ja" działający bez konta;
  dochodzi do tabeli już tutaj, żeby zadanie 043 nie dokładało migracji. Wartość generuje i hashuje 043.
- **Usługi z limitem egzemplarzy:** piąta tabela `reservation_service_items` (`reservation_id`, `additional_service_id`,
  `quantity`, `starts_at`, `ends_at`) — nazwa i kształt wymagane przez ADR-025 pkt 7. Suma sprawdzana względem limitu
  pod blokadą wiersza usługi, w tej samej transakcji co zapis slotów. Pozycje usług są też w JSON `reservation_terms`
  (warunki zamrożone); tabela służy wyłącznie zajętości. Kryterium „4 tabele" zmienia się na 5.
- **Enumy:** `ReservationStatus` (`pending`, `confirmed`, `cancelled`, `expired`) i `PaymentStatus` (`unpaid`, `paid`,
  `on_site`, `refund`); `ReservationSource` (`operator`, `admin`); `ConfirmationSource` (`operator`, `link`).
  „Do potwierdzenia" = `pending` z `confirm_by`; rezerwacja bez wymogu potwierdzenia powstaje jako `confirmed`.
  „W toku" nie jest statusem, tylko wyliczeniem z slotów.
- **Autoryzacja:** polityka odpowiada zasobowi Filamenta (`autoryzacja.md` §5) — zasób `ReservationResource` powstaje
  w 041 (wzorzec `AvailabilityBlockResource`: zasób podrzędny, `scopeToOwnedFisheries`). Modele `FisheryAngler`,
  `ReservationSlot`, `ReservationTerms`, `ReservationServiceItem` nie mają polityk — autoryzują się przez rezerwację/łowisko.
- Czas: sloty w UTC; „doba wędkarska" liczona istniejącymi klasami `FishingDay*` (bez przemianowania).

## Powiązane ADR-y
- [ADR-024](../../adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md),
  [ADR-025](../../adr/ADR-025-zajetosc-stanowiska-jako-sloty-przedzialowe.md),
  [ADR-026](../../adr/ADR-026-zamrozenie-warunkow-rezerwacji.md),
  [ADR-027](../../adr/ADR-027-kolizje-konfiguracji-z-rezerwacjami.md), aktualizacja ADR-015 — wszystkie rozstrzygnięte.
