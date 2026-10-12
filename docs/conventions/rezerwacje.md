# Konwencje: rezerwacje — ekrany operatora i przepływy wędkarza

Obowiązuje przy zmianach w `app/Filament/Resources/ReservationResource/**`,
`app/Filament/Resources/ReservationResource.php`, `FisheryResource\Pages\ManageReservations`,
warstwie „Rezerwacje" kalendarza, `app/Services/Reservation*` (poza samą ścieżką zapisu)
i w mailach o rezerwacjach.

⚠️ **Rdzeń — model, sloty, jedna ścieżka zapisu `ReservationBooking`, zamrożone warunki — opisuje
[`dostepnosc.md`](dostepnosc.md) §6** (oś „czy wolno sprzedać") i [`cennik.md`](cennik.md) (warunki
i zwrot). Ten plik niesie reguły EKRANÓW i PRZEPŁYWÓW, które na tym rdzeniu stoją.

Zadania źródłowe: 041, 042, 043, 044, 045, 047, 048, 049. Decyzje: ADR-024…028.

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
  zapytania z `where('fishery_id', …)`).
- **Pola formularza rezerwacji buduje JEDNA klasa — [`ReservationFormSchema`](../../app/Filament/Resources/ReservationResource/ReservationFormSchema.php)**:
  `full()` dla C i pełnej edycji, `quick()` dla krótkiego formularza kalendarza i szybkiej edycji. Zapis z każdego
  z nich idzie przez `ReservationFormSubmission` (`book()` / `update()`).
- ⚠️ **Łowisko pól reaktywnych nie pochodzi z pola `Hidden`.** Formularz C rozwiązuje je przez
  `FisheryAccess::findFishery()` (zawężone), edycja i kalendarz — z rekordu po stronie serwera. Podmieniony
  `fishery_id` daje puste opcje stanowisk, usług i wędkarzy, a nie cudze dane (047, D1).
- **Podgląd oferty** ([`ReservationFormQuote`](../../app/Filament/Resources/ReservationResource/ReservationFormQuote.php))
  pyta wyłącznie warstwę oferty i pokazuje cenę z rozbiciem z `StayPriceExplanation` (`cennik.md` §5).
  **Pole powodu wyjątku od reguł pojawia się wyłącznie przy odmowie oferty, którą wolno przekroczyć**
  (reguła sprzedaży, blokada, wycofanie), razem z listą naruszonych reguł — wtedy jest wymagane. Zajętość to
  twarda odmowa bez pola; brak ceny zastępuje cena uzgodniona. Przy edycji podgląd pomija własne sloty rezerwacji.
- **Usługa niedostępna dla stanowiska albo terminu jest widoczna z ostrzeżeniem przed zapisem** (przyczyny
  z `StayServiceLine::warnings()`). Nieoferowana na stanowisku albo bez wymaganej (lub z zawieszoną) cechy —
  operator i admin zapisują ją jako wyjątek z powodem (pole pojawia się tylko wtedy). **Brak wolnych
  egzemplarzy nie ma wyjątku** — formularz ostrzega, zapis odmawia (`cennik.md` §7).
- **Edycja rezerwacji ma dwa wejścia** (lista, szczegóły, popover kalendarza): **„Szybka edycja"** — modal
  z krótkim formularzem jak „Zarezerwuj…", i **„Pełna edycja"** — strona `EditReservation` z formularzem jak C
  (usługi, cena uzgodniona i zapisane wyjątki widoczne). Trzeciego formularza edycji nie ma.
- ⚠️ **Edycja nie gubi zamrożonych warunków** (047, C2): zmiana samego kontaktu albo notatki idzie przez
  `ReservationBooking::updateContact()` i warunków nie dotyka; zmiana terminu, stanowiska, składu, usług albo
  ceny idzie przez `rebook()` z powodem zmiany (`ReservationFormSchema::priceDriversChanged()` rozstrzyga, która
  ścieżka). `rebook()` startuje od istniejących wyjątków: wyjątek od tej samej reguły nie wymaga nowego powodu,
  „zostaw" z `block_id` i zwroty zostają zawsze, polityka zwrotu, wersje dokumentów i kontakt z chwili
  rezerwacji są zamrożone, a cena przelicza się tylko przy zmianie tego, co ją kształtuje. Kontakt zmienia się
  w tej samej transakcji co termin; adres zajęty przez inną relację łowiska to komunikat (`EmailTaken`).
  Pusta notatka czyści notatkę.
- ⚠️ **Świadome odstępstwo od „akcje prowadzą na pełne strony" (§2): krótkie akcje na rezerwacji są
  MODALAMI** — szybka edycja, anulacja, potwierdzenie, status płatności, „Oznacz jako załatwione"
  ([`ReservationActions`](../../app/Filament/Resources/ReservationResource/ReservationActions.php)),
  współdzielone przez listę, szczegóły i kalendarz. Warunek odstępstwa: rekord z zawężonego zapytania,
  polityka pytana w `visible()` **i** w `action()`, zapis przez serwis, który sam pilnuje łowiska.
  Nowa akcja na rezerwacji spełnia te trzy warunki albo idzie na pełną stronę.
- **Opłacona rezerwacja jest zawsze potwierdzona:** `ReservationBooking::setPaymentStatus()` przy „opłacona"
  potwierdza oczekującą rezerwację (źródło `ConfirmationSource::Payment`, bez terminu wygaśnięcia). Każda droga
  do płatności — „Oznacz jako opłaconą", status płatności, akcja zbiorcza, przyszła płatność wędkarza online —
  idzie przez tę metodę, nie obok. Odmowa serwisu (np. rezerwacja
  anulowana w międzyczasie) daje komunikat odmowy, nigdy „Zapisano".
- **Anulacja pobytu w toku** wymaga zaznaczenia potwierdzenia w modalu (walidacja `accepted`); akcja
  zbiorcza takie pobyty pomija. Zwrot: z warunków albo wyjątek z powodem. Po anulacji mail do wędkarza
  (`ReservationMailer`); brak adresu albo błąd wysyłki → „Do kontaktu".
- **Wyszukiwanie wędkarza wyłącznie w relacjach TEGO łowiska** (ADR-024): tabela po zawężonym zapytaniu,
  podpowiedzi w formularzu z `fishery_anglers` łowiska — nigdy z `users`.

---

## 2. Kalendarz — warstwa „Rezerwacje" (zadania 042, 047)

Specyfikacja wyjściowa: §3/§3a `docs/project/mockups/etap3/README.md` i makieta `etap3.html`. ⚠️ Tam, gdzie
makieta się różni (model połówek, zaznaczanie przyjazd → wyjazd, domyślne okno 2 tygodnie i przesuw o tydzień,
popover nad albo pod wierszem, oferta w pustych dobach), obowiązuje ten plik.

- **Jeden ekran, dwie warstwy** (G4): `ManageCalendar` z `?layer=reservations` (domyślna, także dla
  admina) i `?layer=offer` (podgląd 019, bez zmian funkcji). Wartość spoza `CalendarLayer` → domyślna.
  Okna: **2 tygodnie (domyślne)** i tydzień (od poniedziałku); ‹ › przesuwają OBA okna o tydzień
  (`CalendarWindow::next()`/`previous()` — drugi tydzień zostaje jako kontekst); **miesiąc wyłącznie w „Ofercie"
  (`CalendarWindow::optionsFor()`, `unit()` zamienia miesiąc na dwa tygodnie w „Rezerwacjach").
  Warstwa „Rezerwacje" nie jest przypięta do sezonu — nawigacja okna jest wolna („Dziś", ‹, ›).
  **Ostatnie miejsce (warstwa, okno, początek okna, sezon) żyje w sesji** pod `calendar.{id łowiska}`, osobno
  dla każdej warstwy; wejście bez `?layer=` je odtwarza. Stan z sesji waliduje się jak dane od klienta
  (`ManageCalendar::restoreWindow()`).
- **Kolumna siatki = dzień kalendarzowy z dwiema połowami (model połówek).** Pierwsza połowa należy do doby
  zaczętej dzień wcześniej (`ReservationCalendarCell::$morningOccupied`), druga — do doby od tego dnia
  (`$occupied`). Pasek rezerwacji biegnie **od połowy dnia przyjazdu do połowy dnia wyjazdu**, z ukośnymi
  końcami „/" od lewego dolnego rogu komórki do połowy jej górnej krawędzi (przyjazd — prawa część komórki,
  wyjazd — lewy górny trójkąt); krawędzie są równoległe, więc dzień wyjazdu jednej i przyjazdu drugiej
  rezerwacji to jedna komórka z dwoma paskami stykającymi się na tej samej linii. Paski i zaznaczenie są
  warstwą nad komórkami (`clip-path` obcina też klikanie, więc wolna część trafia w komórkę). Kształt i wcięcia
  tekstu (osobno dla linii górnej i dolnej, żeby ikony nie wchodziły pod ukos) mają jeden dom —
  [`CalendarBarShape`](../../app/Support/CalendarBarShape.php), wspólny z portalem.
  ⚠️ Nie wracaj do kolumny = doby: operator czyta kolumny jak dni i wskazywał dzień wyjazdu jako ostatnią dobę.
- **Siatkę składa [`ReservationCalendar`](../../app/Services/ReservationCalendar.php), widok niczego nie
  liczy.** Zajętość i blokady — `PositionAvailability` (to samo źródło co ścieżka zapisu); paski rezerwacji
  z `first_night_on` + `nights` (`span` obejmuje dzień wyjazdu, `coversNight()` — doby pobytu), przycięte do
  okna (◀, bez ukosu po stronie przycięcia); **paski blokad** — ten sam model połówek i ten sam kształt co
  rezerwacja — szary, kreskowany, z powodem (pełny w `title`) i liczbą dób (doby blokady = doby od „od" do „do",
  `dostepnosc.md` §3), z pominięciem dób pobytu rezerwacji
  zostawionej przy blokadzie.
  ⚠️ **Liczba zapytań nie rośnie z dobami:** stanowiska wczytują z góry `reservationSlots` (od północy
  pierwszego dnia okna — jego pierwsza połowa to doba sprzed okna) i `availabilityBlocks.attribute`.
  Pilnuje tego `ReservationCalendarTest`.
- **Warstwa „Rezerwacje" pokazuje ofertę** (odwrócenie decyzji §3 makiety etapu 3 „brak ceny w komórce", 047 A3): w pustej
  dobie drobnym szarym drukiem cena pobytu od tej doby albo „zacznij <dzień>" — z tej samej siatki
  `SaleCalendar` co warstwa „Oferta", przypiętej do komórki (`ReservationCalendarCell::$offer`). Pasek
  rezerwacji niesie nazwisko, liczbę osób, status i **kwotę z zamrożonych warunków** (`total_cents`, z usługami
  i ceną uzgodnioną); niepotwierdzona — kreskowany pasek, zegar, „wygasa dd.mm gg:mm".
- **Popover jest WARSTWĄ nad siatką** (`position: absolute` w kontenerze kalendarza), zakotwiczoną przy
  klikniętym elemencie (`data-popover-anchor`): **nad wierszem elementu, gdy mieści się na ekranie, inaczej
  pod nim** (z przewinięciem do widoku), wyśrodkowany w poziomie w granicach kontenera. ⚠️ Nigdy obok
  zaznaczenia w tym samym wierszu — zasłaniał doby, którymi wydłuża się pobyt. Nie rozsuwa wierszy. Stan zaznaczenia w Livewire, pozycja w Alpine.
  **Klik w dzień przyjazdu zaznaczenia albo ponownie w ten sam pasek zamyka popover**; zamykają go też Esc, ✕ i klik poza kalendarzem.
  Strzałki przesuwają fokus po dniach, Enter = klik; paski są osiągalne Tabem.
- **Pierwszy klik wskazuje dzień PRZYJAZDU** (doba od tego dnia wolna), **klik w późniejszy dzień tego wiersza —
  dzień WYJAZDU**; zakres zatrzymuje się przed pierwszą zajętą dobą. Klik we wcześniejszy dzień przesuwa
  przyjazd. Klik w dzień przyjazdu zamyka zaznaczenie. **Klik w bieżący dzień wyjazdu przedłuża pobyt o dobę**
  (działa jak klik w dzień następny); sam pierwszy klik to już jedna doba, więc dwa kliki w sąsiednie dni =
  dwie doby, a każdy kolejny klik w dzień wyjazdu dokłada dobę. Dzień z zajętą drugą połową może być
  tylko dniem wyjazdu; dzień z obiema połowami zajętymi i przeszłość nie reagują. `selStart`/`selEnd` to
  pierwsza i ostatnia DOBA (formularze dostają przyjazd + liczbę dób).
- ⚠️ **Akcje kalendarza dostają stanowisko, dzień, liczbę dób (albo id rezerwacji / blokady) w ARGUMENTACH**,
  nie z zaznaczenia — zaznaczenie bywa wyczyszczone, gdy modal jest otwarty. Id z argumentów to dane od
  klienta: wyłącznie z łowiska strony (`scopedReservation()`, `scopedBlock()`, `positions()` łowiska).
- **Liczniki nad siatką filtrują po kliknięciu** (przyjazdy i wyjazdy dziś, niepotwierdzone ≤ 24 h,
  niepotwierdzone, do kontaktu), obok filtr statusu i „wymagają reakcji". **Filtr przygasza niepasujące paski,
  nie usuwa ich** — zajęta doba bez paska wyglądałaby na wolną (`ReservationCalendar::FILTERS`).
- **Krótki formularz rezerwacji** ma nagłówek „Rezerwacja stanowiska: X" (sama nazwa pod nagłówkiem bywa
  gołym numerem) i maksymalną długość = wolne doby (`ReservationCalendar::freeNightsFrom()`,
  maks. `MAX_FREE_NIGHTS` = 30 — stała w jednym miejscu). Obok liczby dób (tu i w pełnym formularzu) stoi
  przyjazd i wyjazd z godzinami z `FishingDayCalendar`. **„Zapisz i otwórz pełny formularz →" zapisuje
  rezerwację tą samą ścieżką co „Zarezerwuj" i przekierowuje do `EditReservation`** — nie przenosimy stanu
  niezapisanego formularza między stronami.
- **Skrót „Zablokuj…"** pyta `CollisionGate` (czyli `ConfigurationCollisionService::affected()` — skrót nie ma
  własnej reguły kolizji): bez kolizji zapisuje blokadę jednego stanowiska (`AvailabilityBlockWriter`), przy
  kolizji przechodzi na ekran decyzji E (§4). Klik w pasek blokady: powód, okres, „Edytuj blokadę",
  „Usuń blokadę…" (polityka blokady).
- **Telefon:** pasek dni i lista dnia (przyjazdy, wyjazdy, aktualnie na łowisku, wolne stanowiska z „wolne do
  dd.mm · maks. N dób"). Wolne stanowisko → arkusz od dołu: krok 1 — liczba dób, przyjazd i wyjazd, cena
  (`ManageCalendar::quote()`, bez przerysowania strony); „Dalej" → krok 2 — krótki formularz „Zarezerwuj…"
  (dane wędkarza i zapis). Zajęte stanowisko → arkusz z akcjami rezerwacji.

---

## 3. Wędkarz: potwierdzenie rezerwacji i połączenie z kontem (zadania 043, 049)

Logika: [`AnglerLinking`](../../app/Services/AnglerLinking.php); strony: [`strona-publiczna.md`](strona-publiczna.md) §8.
Decyzje: ADR-024 (z aktualizacją 2026-10-11), ADR-028 (zgody).

- **Po zapisie rezerwacji z adresem e-mail idzie prośba o potwierdzenie** (`ReservationConfirmation::afterBooking()`
  z formularza C i z kalendarza; zapis bezpośrednio przez `ReservationBooking` maila nie wysyła). Bez adresu —
  nic: relacja zostaje niepowiązana, bez maili.
- ⚠️ **Operator widzi ZAWSZE ten sam komunikat** (`CreateReservation::requestNotice()`) — panel nie zdradza, czy
  adres ma konto w portalu (ADR-024 pkt 3). Konto wyszukuje się dopiero na stronie po kliknięciu linku.
- **Dwa tokeny, w bazie tylko SHA-256**: `fishery_anglers.link_token_hash` (ważność `link_expires_at`,
  `AnglerLinking::VALID_DAYS`; związany z adresem z chwili wysyłki — `autoryzacja.md` §6) i
  `reservations.manage_token_hash` — „Zrezygnuj" (anulacja przez wędkarza, zwrot z warunków) albo „To nie ja"
  (token unieważniony, rezerwacja **nie anulowana**, tylko „Do kontaktu" do weryfikacji przez operatora).
  **Token rezygnacji jest ważny do końca aktywności rezerwacji** (rozstrzygnięcie 047, H1) — także po upływie okna
  powiązania; przestaje działać z anulacją albo wygaśnięciem. Strona po rezygnacji mówi tylko to, co prawdziwe:
  anulację łowisko widzi na liście rezerwacji.
- ⚠️ **„Potwierdź rezerwację" NIE wymaga konta** (`confirmReservations()`): oczekujące rezerwacje wędkarza w łowisku
  → potwierdzone (`confirmation_source = link`), bez zapisu do `users` i **bez zużycia tokenu**. Relacja zostaje
  `pending` z ważnym linkiem do `link_expires_at` (R1) — wędkarz może połączyć konto później tym samym linkiem.
- **Połączenie z kontem to osobny, dobrowolny krok** po potwierdzeniu (zachęta „Zaloguj się, żeby widzieć historię
  rezerwacji" / „Załóż konto, żeby mieć historię rezerwacji", „Nie teraz"). „Połącz konto" zapisuje znacznik w
  sesji (`AnglerLinkSession`) i prowadzi na stronę łączenia: konto o adresie z prośby istnieje → logowanie (hasło
  albo Google, z 2FA) i powrót; nie istnieje → założenie konta hasłem (adres z prośby, zgody Fisherya i łowiska)
  albo przez Google. Zalogowany na konto o innym adresie dostaje odmowę z wylogowaniem. Połączenie (`link()`) →
  relacja `linked`, akceptacja wymaganych dokumentów łowiska (`required_at_registration` = „akceptowany przy
  połączeniu konta", `ConsentRegistry`), token zużyty, oczekujące rezerwacje potwierdzone.
- **Panel pokazuje stan wędkarza osobno od statusu rezerwacji** („Dane wędkarza": „Dane od łowiska" albo
  „Potwierdzone konto", `AnglerLinkStatus::accountLabel()`) — na liście, w szczegółach i w popoverze kalendarza.
  „Potwierdzona" nie znaczy „powiązana z kontem".
- ⚠️ **Zmiana adresu relacji `Pending` unieważnia wysłaną prośbę** (token i ważność zerowane, status `Unlinked`)
  — inaczej link z maila wysłanego na pomyłkowy adres dowiązałby rezerwację do konta właściciela poprawionego
  adresu. „Potwierdź" sprawdza token ponownie na zablokowanym wierszu relacji (047, D2/D3).
- **Konflikt telefonu** (konto ma inny numer niż podany łowisku): wybór „z profilu" / „nowy i zapisz w profilu" /
  „nowy tylko dla tego łowiska" (`phone_override`) — wyłącznie w kroku połączenia, po zalogowaniu. Profil bez
  telefonu dostaje numer podany łowisku.
- **Limity** (ADR-024 pkt 8): `MAX_PENDING_PER_EMAIL` oczekujących próśb na adres w całym portalu i
  `MAX_SENDS_PER_FISHERY_PER_DAY` wysyłek z łowiska na dobę. Przekroczenie: rezerwacja powstaje, mail nie
  wychodzi, rezerwacja → „Do kontaktu".
- Zdarzenia (`link_requested`, `link_request_limited`, `reservations_confirmed`, `linked`, `not_me`) idą do kanału
  `reservations` na relacji.
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
- Skrót „Zablokuj…" z kalendarza przechodzi przez `CollisionGate` jak strona blokady (§2).
- **Usunięcie stanowiska z aktywnymi przyszłymi rezerwacjami jest blokowane z komunikatem** (akcja pojedyncza
  i zbiorcza, `CollisionGate::blockingDeletion()`): usunięcie nie ma decyzji „zostaw", więc operator najpierw
  wycofuje stanowisko (ekran decyzji) albo anuluje rezerwacje.
- `apply()` liczy jako anulowane wyłącznie rezerwacje, które `cancel()` faktycznie anulował — anulowana
  w międzyczasie nie dostaje drugiego maila. Kolejność blokad jak w ścieżce zapisu: stanowiska rosnąco po id,
  potem wiersze rezerwacji.

---

## 5. Ustawienia rezerwacji i wygasanie (zadanie 045)

- **Sekcja „Rezerwacje" na „Sprzedaż i sezony"** (`ManageSaleSettings`): `manual_reservation_confirmation_required`
  (domyślnie wyłączone), `manual_reservation_hold_hours` (domyślnie `Fishery::DEFAULT_HOLD_HOURS` = 48, zakres
  1–`MAX_HOLD_HOURS` = 720, wymagane tylko przy włączonym potwierdzaniu) i `public_availability_enabled`
  (logika w portalu — zadanie 046).
- **`confirm_by` liczy wyłącznie `ReservationConfirmation::deadlineFor()`: `min(teraz + okno, początek pobytu)`**
  albo `null` (ADR-024, aktualizacja 11.10.2026), wołane przez `ReservationFormSubmission` — formularz C
  i krótki formularz kalendarza. Niepotwierdzona rezerwacja wygasa najpóźniej w chwili rozpoczęcia pobytu;
  `expire()` pomija pobyty rozpoczęte z terminem sprzed tej reguły, więc pobyt w toku nie wygasa. Zmiana
  ustawień nie przepisuje terminów rezerwacji już założonych.
- **Wygasza wyłącznie `reservations:expire`** przez `ReservationBooking::expire()` (blokada wiersza, ponowne
  sprawdzenie — idempotentne, bezpieczne równolegle), co 5 minut z harmonogramu. Na Cloud Run wyzwala ją Cloud
  Scheduler → Cloud Run Job, bez endpointu HTTP ([`obraz-produkcyjny.md`](../operations/obraz-produkcyjny.md) §13).
  Po wygaszeniu mail do wędkarza z adresem (decyzja autora); rezerwacja bez adresu nie trafia do „Do kontaktu".
- Potwierdzenie (operator albo wędkarz linkiem) czyści `confirm_by`. Plakietka „≤ 24 h" liczy się z `confirm_by`
  (`Reservation::expiringWithin()`, §1).

---

## 6. Wędkarze łowiska — podgląd tylko do odczytu (zadanie 048)

Strona `FisheryResource\Pages\ManageAnglers` — pozycja sub-nawigacji **zaraz za „Rezerwacjami"**, w obu panelach.

- ⚠️ **Źródłem są wyłącznie relacje łowiska (`fishery_anglers`), nigdy `users`** (ADR-024 pkt 2). Strona **niczego nie
  zapisuje**; `FisheryAngler` nie ma zasobu ani polityki, dostęp wynika z dostępu do łowiska jak przy „Rezerwacjach"
  (`autoryzacja.md` §5). Cudze łowisko to 404.
- **Dane efektywne ma jedna reguła — `FisheryAngler::effectiveContact()`**; jej odpowiednikiem w SQL jest
  `FisheryAngler::effectiveSql()` (wyszukiwanie i sortowanie). ⚠️ Zmiana jednej wymaga zmiany drugiej — pilnuje tego
  test porównujący obie dla każdego stanu relacji. Wyszukiwarka (`matchingContact()`) szuka po danych efektywnych:
  `linked` po danych konta (z `phone_override`), `unlinked`/`pending` po `given_*`, `revoked` nigdy; nie szuka po notatkach.
- **Stan danych na liście i w filtrze to trzy stany z `AnglerLinkStatus::accountLabel()`** (dane od łowiska, potwierdzone
  konto, dostęp cofnięty) — to samo słownictwo co kolumna „Dane wędkarza" na liście rezerwacji (§3). Czterostanowy status
  (`label()`) i ważność linku są dopiero w podglądzie wędkarza.
- **Liczba rezerwacji w kolumnie to rezerwacje aktywne, a podgląd pokazuje wszystkie statusy z TEGO łowiska, od najnowszej**
  (`FisheryAngler::reservationHistory()`); numer prowadzi do szczegółów tylko, gdy `ReservationPolicy` pozwala (`view`).
  Liczba i najbliższy pobyt to agregaty w zapytaniu (`withCount`, `withMin`), nie zapytania na wiersz.
- Podgląd to `ViewAction` ze schematem: ⚠️ `ViewAction` wypełnia stan z `attributesToArray()`, więc lista rezerwacji
  ma jawny `state()` z relacji. Treść modala nie trafia do `html()` komponentu w testach Livewire — testy renderują
  schemat zamontowanej akcji (`previewHtml()` w `FisheryAnglersPageTest`).
