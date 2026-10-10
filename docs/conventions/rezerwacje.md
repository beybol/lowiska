# Konwencje: rezerwacje — ekrany operatora i przepływy wędkarza

Obowiązuje przy zmianach w `app/Filament/Resources/ReservationResource/**`,
`app/Filament/Resources/ReservationResource.php`, `FisheryResource\Pages\ManageReservations`,
warstwie „Rezerwacje" kalendarza, `app/Services/Reservation*` (poza samą ścieżką zapisu)
i w mailach o rezerwacjach.

⚠️ **Rdzeń — model, sloty, jedna ścieżka zapisu `ReservationBooking`, zamrożone warunki — opisuje
[`dostepnosc.md`](dostepnosc.md) §6** (oś „czy wolno sprzedać") i [`cennik.md`](cennik.md) (warunki
i zwrot). Ten plik niesie reguły EKRANÓW i PRZEPŁYWÓW, które na tym rdzeniu stoją.

Zadania źródłowe: 041, 042, 043, 044, 045. Decyzje: ADR-024…027.

---

## 1. Lista, formularz i szczegóły (zadanie 041)

- **„Rezerwacje" (`ManageReservations`) stoją w sub-nawigacji ZARAZ ZA „Kalendarzem"** — z kalendarza
  się je zakłada (042). Strona to `ManageRelatedRecords` po relacji `reservations`, tabela deleguje do
  `ReservationResource::table()`, zakładki: „Bieżące i nadchodzące" (domyślna), „Do kontaktu", „Wszystkie".
- **Plakietka** = niepotwierdzone, którym termin potwierdzenia mija w ciągu 24 h (`Reservation::expiringWithin()`),
  plus „Do kontaktu" (`Reservation::toContact()`); liczona w `badgeFor()` z rekordu sub-nawigacji.
- **`ReservationResource` jest zasobem podrzędnym łowiska bez pozycji w głównej nawigacji** — istnieje,
  bo polityka odpowiada zasobowi (`autoryzacja.md` §5). Strony: samodzielna lista (`?fishery=`, deep-link),
  **formularz C jako PEŁNA strona** (`CreateReservation`, `Grid` + `Section`, parametry `position`, `from`,
  `nights` z adresu) i **szczegóły D** (`ViewReservation`, infolista z zamrożonymi warunkami i historią
  z kanału `reservations`). Panel właściciela rejestruje zasób jawnie w `OwnerPanelProvider`.
- ⚠️ **Formularz C zapisuje przez `ReservationBooking`, nie `Model::create()`** — `handleRecordCreation()`
  woła serwis, a odmowa wraca powiadomieniem i `halt()` (formularz zostaje). Stanowisko, relacja wędkarza
  i łowisko przychodzą od klienta, więc przechodzą przez zawężenie do łowiska (`forceVerifiedFishery()`,
  zapytania z `where('fishery_id', …)`). Podgląd oferty w formularzu pyta wyłącznie `StayOffer`.
- ⚠️ **Świadome odstępstwo od „akcje prowadzą na pełne strony" (§2): krótkie akcje na rezerwacji są
  MODALAMI** — edycja, anulacja, potwierdzenie, status płatności, „Oznacz jako załatwione"
  ([`ReservationActions`](../../app/Filament/Resources/ReservationResource/ReservationActions.php)),
  współdzielone przez listę, szczegóły i kalendarz. Warunek odstępstwa: rekord z zawężonego zapytania,
  polityka pytana w `visible()` **i** w `action()`, zapis przez serwis, który sam pilnuje łowiska.
  Nowa akcja na rezerwacji spełnia te trzy warunki albo idzie na pełną stronę.
- **Anulacja pobytu w toku** wymaga zaznaczenia potwierdzenia w modalu (walidacja `accepted`); akcja
  zbiorcza takie pobyty pomija. Zwrot: z warunków albo wyjątek z powodem. Po anulacji mail do wędkarza
  (`ReservationMailer`); brak adresu albo błąd wysyłki → „Do kontaktu".
- **Wyszukiwanie wędkarza wyłącznie w relacjach TEGO łowiska** (ADR-024): tabela po zawężonym zapytaniu,
  podpowiedzi w formularzu z `fishery_anglers` łowiska — nigdy z `users`.

---

## 2. Kalendarz — warstwa „Rezerwacje" (zadanie 042)

- **Jeden ekran, dwie warstwy** (G4): `ManageCalendar` z `?layer=reservations` (domyślna, także dla
  admina) i `?layer=offer` (podgląd 019, bez zmian funkcji). Wartość spoza `CalendarLayer` → domyślna.
  Okna: tydzień i **2 tygodnie** (od poniedziałku); **miesiąc wyłącznie w „Ofercie"**
  (`CalendarWindow::optionsFor()`, `unit()` zamienia miesiąc na dwa tygodnie w „Rezerwacjach").
  Warstwa „Rezerwacje" nie jest przypięta do sezonu — nawigacja okna jest wolna.
- **Siatkę składa [`ReservationCalendar`](../../app/Services/ReservationCalendar.php), widok niczego nie
  liczy.** Zajętość i blokady komórek — `PositionAvailability::isOccupied()` / `isBlocked()` (to samo
  źródło co ścieżka zapisu), paski — z `first_night_on` + `nights`, przycięte do okna (`startsBefore` → ◀).
  ⚠️ **Liczba zapytań nie rośnie z dobami ani stanowiskami:** stanowiska wczytują z góry
  `reservationSlots` i `availabilityBlocks.attribute`, a `PositionAvailability` korzysta z wczytanych
  relacji, zamiast pytać per stanowisko. Pilnuje tego `ReservationCalendarTest`. Ta sama zasada objęła
  `SaleCalendar` (sloty z góry).
- **Klik w wolną dobę zaznacza, drugi klik w tym wierszu wydłuża zakres, ale zatrzymuje się przed dobą
  zajętą.** Przeszłość i doby zajęte są nieklikalne (przyciski `disabled`). Doba zablokowana jest
  klikalna — operator może zarezerwować z wyjątkiem.
- **Popover to WIERSZ tabeli pod klikniętym stanowiskiem** (zakotwiczony w kontenerze, bez
  `position: fixed`) — mieści się w polu widzenia dla pierwszego i ostatniego wiersza bez liczenia
  pozycji skryptem. Esc i ✕ czyszczą zaznaczenie.
- ⚠️ **Akcje kalendarza dostają stanowisko, dzień i liczbę dób w ARGUMENTACH**, nie z zaznaczenia —
  zaznaczenie bywa wyczyszczone, gdy modal jest otwarty. Id z argumentów to dane od klienta: stanowisko
  i rezerwacja wyłącznie z łowiska strony (`scopedReservation()`, zapytania z `positions()` łowiska).
- **Krótki formularz rezerwacji i pełna strona C zapisują przez to samo `ReservationFormSubmission`** —
  dwa formularze, jedna interpretacja pól. Długość w krótkim formularzu nie przekracza wolnych dób
  (`ReservationCalendar::freeNightsFrom()`, maks. `MAX_FREE_NIGHTS` = 30 — stała w jednym miejscu).
- **Skrót „Zablokuj…"** zapisuje blokadę sprzedaży jednego stanowiska (`AvailabilityBlockWriter`), ale
  **nie podejmuje decyzji o kolizji** — gdy okno blokady (całe dni) przecina aktywną rezerwację, odmawia
  i odsyła do ekranu blokad (G8, ADR-027).
- **Telefon:** pasek dni i lista stanowisk dla dnia; wolne stanowisko pokazuje „wolne do dd.mm · maks.
  N dób" z tym samym wyliczeniem co krótki formularz.

---

## 3. Wędkarz: powiązanie z kontem i potwierdzenie (zadanie 043)

Logika: [`AnglerLinking`](../../app/Services/AnglerLinking.php); strony: [`strona-publiczna.md`](strona-publiczna.md) §8.

- **Po zapisie rezerwacji z adresem e-mail idzie prośba o potwierdzenie** (`ReservationConfirmation::afterBooking()`
  z formularza C i z kalendarza; zapis bezpośrednio przez `ReservationBooking` maila nie wysyła). Bez adresu —
  nic: relacja zostaje niepowiązana, bez maili.
- ⚠️ **Operator widzi ZAWSZE ten sam komunikat** (`CreateReservation::requestNotice()`) — panel nie zdradza, czy
  adres ma konto w portalu (ADR-024 pkt 3). Konto wyszukuje się dopiero na stronie po kliknięciu linku.
- **Dwa tokeny, oba jednorazowe, w bazie tylko SHA-256**: `fishery_anglers.link_token_hash` (ważność
  `link_expires_at`, `AnglerLinking::VALID_DAYS`) — „Potwierdź i połącz": konto powstaje (adres zweryfikowany
  kliknięciem, bez hasła, `has_password = false`, bez roli) albo dowiązuje się istniejące o tym adresie
  (porównanie dokładne po `mb_strtolower`), relacja → `linked`, oczekujące rezerwacje tego wędkarza w łowisku →
  potwierdzone (`confirmation_source = link`, W3); `reservations.manage_token_hash` — „Zrezygnuj" (anulacja
  przez wędkarza, zwrot z warunków) albo „To nie ja" (token unieważniony, rezerwacja **nie anulowana**, tylko
  „Do kontaktu" do weryfikacji przez operatora).
- **Konflikt telefonu** (konto ma inny numer niż podany łowisku): wybór „z profilu" / „nowy i zapisz w profilu" /
  „nowy tylko dla tego łowiska" (`phone_override`). Profil bez telefonu dostaje numer podany łowisku.
- **Limity** (ADR-024 pkt 8): `MAX_PENDING_PER_EMAIL` oczekujących próśb na adres w całym portalu i
  `MAX_SENDS_PER_FISHERY_PER_DAY` wysyłek z łowiska na dobę. Przekroczenie: rezerwacja powstaje, mail nie
  wychodzi, rezerwacja → „Do kontaktu".
- Zdarzenia (`link_requested`, `link_request_limited`, `linked`, `not_me`) idą do kanału `reservations` na relacji.
- Poza zakresem etapu 3 (W9): propozycje zmian kontaktu przez operatora i cofanie dostępu (`revoked`).

---

## 4. Kolizje zmian konfiguracji z rezerwacjami (zadanie 044)

Decyzja: [ADR-027](../adr/ADR-027-kolizje-konfiguracji-z-rezerwacjami.md); zasada G8.

- **Jedna usługa — [`ConfigurationCollisionService`](../../app/Services/ConfigurationCollisionService.php)** — i jeden
  ekran decyzji (`FisheryResource\Pages\ResolveCollisions`, `/{record}/collisions/{token}`). Opis zmiany to
  `ConfigurationChange` (rodzaj + stanowiska + okno + to, co zapisać); zbiór decyzji zależy od rodzaju
  (`ConfigurationChangeKind::decisions()`): blokada sprzedaży i wycofanie stanowiska — „anuluj z pełnym
  zwrotem" albo „zostaw"; zawieszenie cechy — wyłącznie informacja. Kolejna zmiana konfiguracji dokłada rodzaj,
  nie drugą logikę kolizji.
- **Dotknięta = aktywna, jeszcze nierozpoczęta, nakładająca się na zmianę.** Pobytów w toku nie ma na liście
  nigdy. Rezerwacja zostawiona przy danej blokadzie (wyjątek z `block_id`) nie wraca przy edycji tej blokady.
- ⚠️ **Zmiana NIE zapisuje się przed decyzjami.** Strony zapisu pytają `CollisionGate` przed zapisem: przy
  kolizji szkic idzie do SESJI (`CollisionDrafts`) i operator trafia na ekran decyzji, gdzie zmiana i decyzje
  zapisują się w jednej transakcji, pod blokadą wierszy stanowisk. Lista liczy się od nowa przy zapisie —
  nowa rezerwacja w międzyczasie wycofuje zapis (`StaleCollisionDecision`) i wraca do decyzji.
  Wycofanie stanowiska w formularzu stanowiska: pozostałe zmiany zapisują się od razu, status zostaje,
  a wycofanie — na ekranie decyzji.
- **„Anuluj"** — `ReservationBooking::cancel()` z rodzajem `collision` (pełny zwrot jako status), komunikat
  wspólny albo indywidualny trafia do maila. **„Zostaw"** — wyjątek w warunkach (`recordException()`):
  `sale_blocked` z `block_id` przy blokadzie, `sale_rule` przy wycofaniu. Rezerwacja zostaje widoczna
  w kalendarzu na zablokowanej dobie.
- ⚠️ **Maile bez limitu, po kolei, PO zatwierdzeniu transakcji** (Q6, decyzja autora 10.10.2026). Błąd wysyłki
  nie przerywa pętli — rezerwacja trafia do „Do kontaktu", a powiadomienie po zapisie podaje, do ilu wyszedł
  mail. Świadome ryzyko: bardzo duża liczba anulacji wydłuża żądanie (limit czasu Cloud Run); gdyby się
  zmaterializowało, odpowiedzią jest kolejka/partia — osobne zadanie.
- Skrót „Zablokuj…" z kalendarza (042) nie przechodzi przez ekran decyzji — przy kolizji odmawia i odsyła do
  ekranu blokad.

---

## 5. Ustawienia rezerwacji i wygasanie (zadanie 045)

- **Sekcja „Rezerwacje" na „Sprzedaż i sezony"** (`ManageSaleSettings`): `manual_reservation_confirmation_required`
  (domyślnie wyłączone), `manual_reservation_hold_hours` (domyślnie `Fishery::DEFAULT_HOLD_HOURS` = 48, zakres
  1–`MAX_HOLD_HOURS` = 720, wymagane tylko przy włączonym potwierdzaniu) i `public_availability_enabled`
  (logika w portalu — zadanie 046).
- **`confirm_by` liczy wyłącznie `ReservationConfirmation::deadlineFor()`** (teraz + okno, albo `null`), wołane
  przez `ReservationFormSubmission` — formularz C i krótki formularz kalendarza. Zmiana ustawień nie przepisuje
  terminów rezerwacji już założonych.
- **Wygasza wyłącznie `reservations:expire`** przez `ReservationBooking::expire()` (blokada wiersza, ponowne
  sprawdzenie — idempotentne, bezpieczne równolegle), co 5 minut z harmonogramu. Na Cloud Run wyzwala ją Cloud
  Scheduler → Cloud Run Job, bez endpointu HTTP ([`obraz-produkcyjny.md`](../operations/obraz-produkcyjny.md) §13).
  Po wygaszeniu mail do wędkarza z adresem (decyzja autora); rezerwacja bez adresu nie trafia do „Do kontaktu".
- Potwierdzenie (operator albo wędkarz linkiem) czyści `confirm_by`. Plakietka „≤ 24 h" liczy się z `confirm_by`
  (`Reservation::expiringWithin()`, §1).
