# 047 — Poprawki modułu rezerwacji po przeglądzie serii 039–046

## Opis problemu
Seria 039–046 dostarczyła rdzeń rezerwacji (zapis, zajętość, warunki, tokeny, kolizje, wygasanie) z dobrym
pokryciem testami. Warstwa ekranów jest jednak **niezgodna ze specyfikacją**. Przy 042 implementujący nie
otworzył makiety `docs/project/mockups/etap3/etap3.html` ani §3/§3a README makiet, zawęził zakres bez
zgłoszenia i nie obejrzał ekranów w przeglądarce. Brakowało też przebudowanego motywu panelu. Pierwsze
testy autora na przebudowanym motywie znalazły 11 uwag do kalendarza i formularzy. Przegląd kodu
(`docs/reviews/2026-10-11-unpushed.md`) zgłosił 17 uwag, w tym 2 krytyczne luki: wyciek danych wędkarzy
z cudzego łowiska i dowiązanie konta przez stary token. Do tego dochodzi zgubiony walor kalendarza z etapu 1,
czyli podgląd oferty na każdą dobę.

To zadanie zbiera wszystkie poprawki w jednym miejscu. Po jego realizacji autor sprawdza moduł dalej.

## Wymagania

⚠️ **Przed pierwszą zmianą kodu ekranów implementujący czyta §3 i §3a `docs/project/mockups/etap3/README.md`
i otwiera interaktywną makietę `etap3.html`** — to jest specyfikacja wyglądu i mechaniki. Wymagania niżej
wskazują rozjazdy, ale nie zastępują makiety. Po zmianach widoków w `resources/views/filament/**` motyw
trzeba przebudować (`docker compose exec app npm run build`), inaczej nowe klasy nie trafią do arkusza.

### A. Kalendarz — warstwa „Rezerwacje" zgodna z §3/§3a (zadanie 042)
- **A1 (uwaga autora 1).** Popover po kliknięciu wolnej doby albo paska to **warstwa nad siatką, zakotwiczona
  przy zaznaczeniu** (po prawej, a przy braku miejsca po lewej). Nie rozsuwa wierszy i nie jest dodatkowym wierszem
  tabeli. Mieści się w polu widzenia także przy pierwszym i ostatnim stanowisku.
- **A2 (uwaga autora 2).** Ponowne kliknięcie zaznaczonej doby zamyka popover i czyści zaznaczenie. Esc, ✕
  i klik poza kalendarzem też zamykają popover.
- **A3 (uwaga autora 4).** Warstwa „Rezerwacje" **pokazuje ofertę**:
  - w pustych dobach drobną szarą czcionką: cenę doby (jak w warstwie „Oferta", z tego samego `SaleCalendar`
    i warstwy oferty) albo „zacznij <dzień>" dla doby w środku pakietu;
  - na pasku rezerwacji: kwotę z zamrożonych warunków (`reservation_terms.total_cents`, z usługami i ceną
    uzgodnioną).

  To odwraca decyzję §3 „brak ceny w komórce" — zapisać jako regułę ekranu w `rezerwacje.md`.
- **A4 (§3).** Domyślne okno warstwy „Rezerwacje": **tydzień** (2 tygodnie do wyboru, miesiąc tylko w „Ofercie").
- **A5 (§3).** Pasek rezerwacji: nazwisko, **liczba osób**, znacznik statusu, kwota (A3). Niepotwierdzona — pasek
  kreskowany, **ikona zegara**, „wygasa dd.mm gg:mm".
- **A6 (§3a).** Blokada w siatce to **szary kreskowany pasek z powodem**. Klik otwiera popover: powód, okres,
  „Edytuj blokadę", „Usuń blokadę…".
- **A7 (§3).** Wolna doba pokazuje „+" po najechaniu.
- **A8 (§3).** Nad siatką:
  - liczniki „Przyjazdy dziś / Wyjazdy dziś / Niepotwierdzone (≤ 24 h) / Do kontaktu" **filtrują po kliknięciu**;
  - przycisk „Nowa rezerwacja";
  - filtr „wymagają reakcji".
- **A9 (§3a).** „Zablokuj…" z popovera: modal blokady, a przy kolizji z rezerwacją **przejście do ekranu decyzji E**
  (`ResolveCollisions`), zamiast odmowy. Reguła kolizji wyłącznie z `ConfigurationCollisionService::affected()`
  (przegląd pkt 6 — usunąć `AvailabilityBlockWriter::collidingReservations()`).
- **A10 (§3a, telefon).** Pasek dni i lista dnia (przyjazdy, wyjazdy, aktualnie na łowisku, wolne stanowiska
  z „wolne do dd.mm · maks. N dób"). Stuknięcie wolnego stanowiska otwiera **arkusz od dołu w dwóch krokach**:
  stepper dób, przyjazd i wyjazd, cena → dane wędkarza i zapis. Zajęte stanowisko — arkusz z akcjami rezerwacji.
- **A11 (§3a).** Strzałki przesuwają fokus po komórkach i paskach, Enter działa jak klik.

### B. Formularze rezerwacji (krótki w kalendarzu i pełny C)
- **B1 (uwaga autora 3).** Pole „Powód rezerwacji wbrew regułom sprzedaży" pojawia się **tylko przy faktycznej
  odmowie oferty**, razem z listą naruszonych reguł (powody z warstwy oferty), i dopiero wtedy jest wymagane.
  Dotyczy krótkiego formularza, pełnego formularza i edycji.
- **B2 (uwaga autora 5).** **Cena z rozbiciem w obu formularzach**:
  - stawka × doby × osoby;
  - osoba towarzysząca, jeśli płatna;
  - dopłaty pod nazwami z cennika;
  - obniżka przedsprzedażowa;
  - usługi według jednostki × liczba;
  - suma.

  Rozbicie składa **jedno miejsce**, wspólne z dymkiem kalendarza portalu (`PortalCalendar::breakdown()` do
  wydzielenia). Widoki tylko formatują.
- **B3 (uwaga autora 9).** Dodanie, zmiana i usunięcie usługi w repeaterze pełnego formularza **przelicza cenę**
  (pola usługi i liczby reaktywne).
- **B4 (uwaga autora 8).** W sekcji „Pobyt" pełnego formularza „Przyjazd (pierwsza doba)" i „Liczba dób" idą
  jedno pod drugim, nie obok siebie.
- **B5 (uwaga autora 10).** Etykieta „E-mail (opcjonalnie)" → „E-mail" we wszystkich formularzach rezerwacji.
- **B6 (uwaga autora 11).** Usługa niedostępna dla stanowiska lub terminu jest widoczna, ale **ostrzeżenie
  z przyczyną pojawia się przed zapisem** (z `PositionServices` i `ServiceStock`). Przykłady przyczyn:
  - „nie jest oferowana na tym stanowisku";
  - „brak wymaganej cechy: …";
  - „cecha zawieszona dd.mm–dd.mm: <powód>";
  - „w tych dobach zostało N z M".

  **Decyzja autora (11.10.2026):**
  - operator i admin mogą zapisać rezerwację z usługą nieoferowaną na stanowisku albo przy braku lub
    zawieszeniu cechy — jako **wyjątek z powodem** w warunkach rezerwacji (rodzaj, usługa, przyczyna,
    powód, kto, kiedy);
  - **wyczerpany limit egzemplarzy nie ma wyjątku** — to podwójna sprzedaż tego samego przedmiotu, jak
    zajęty slot (ADR-025 pkt 7);
  - kanał wędkarza nie zapisuje takich wyjątków nigdy.

### C. Edycja rezerwacji i zamrożone warunki
- **C1 (uwaga autora 6).** Zamiast trzeciego formularza (modal edycji) dwa wejścia, w popoverze kalendarza,
  na liście B i w szczegółach D:
  - **„Szybka edycja"**: ten sam krótki formularz co „Zarezerwuj…", wypełniony danymi rezerwacji;
  - **„Pełna edycja"**: **nowa pełna strona edycji** (`EditReservation`) z formularzem jak C, wypełniona
    danymi rezerwacji. Usługi, cena uzgodniona i wyjątki są widoczne.

  Obecny modal `editReservation` znika.
- **C2 (uwaga autora 7, przegląd pkt 3).** Edycja **nie gubi zamrożonych warunków**:
  - istniejące wyjątki (powód odstępstwa od reguł, „zostaw" z `block_id` z kolizji, cena uzgodniona) są
    widoczne w formularzu i przenoszone przy zapisie, a nowe się dopisują;
  - polityka zwrotu i wersje dokumentów zostają zamrożone;
  - cena przelicza się wyłącznie przy zmianie tego, co ją kształtuje (termin, stanowisko, skład, usługi);
  - zmiana samych danych kontaktowych albo notatki nie przelicza warunków.
- **C3 (przegląd pkt 9).** Zmiana danych wędkarza przy edycji idzie przez serwis, w tej samej transakcji co
  zmiana terminu. Adres zajęty przez inną relację w łowisku daje komunikat, nie błąd 500.
- **C4 (przegląd pkt 10).** Notatkę wewnętrzną da się wyczyścić.
- **C5 (przegląd pkt 11).** Potwierdzenie, status płatności i „Oznacz jako opłaconą" pokazują komunikat odmowy,
  gdy serwis odmówi (np. rezerwacja anulowana w międzyczasie), a nie „Zapisano".
- **C6 (przegląd pkt 16).** Własny zwrot przy anulacji bez `reservation_terms` nie gubi po cichu wyjątku z powodem.

### D. Bezpieczeństwo (krytyczne z przeglądu)
- **D1 (przegląd pkt 1).** Pola reaktywne pełnego formularza C (wybór wędkarza, opcje stanowisk i usług, podgląd
  ceny) rozwiązują łowisko przez `FisheryAccess::findFishery($get('fishery_id'))` (domyślnie zawężone), nigdy
  wprost z `Hidden`. To samo dotyczy nowej strony edycji i krótkiego formularza. Test podmiany `fishery_id`
  dla każdego pola.
- **D2 (przegląd pkt 2).** Zmiana `given_email` relacji `Pending` **unieważnia token powiązania**
  (`link_token_hash` = null, `link_expires_at` = null) i cofa status do `Unlinked`. Token jest dodatkowo
  związany z adresem z chwili wysyłki i sprawdzany w `AnglerLinking::confirm()`. Test scenariusza z literówką.
- **D3 (przegląd pkt 13).** `AnglerLinking::confirm()` sprawdza ważność tokenu ponownie na zablokowanym wierszu.

### E. Wygasanie (przegląd pkt 4)
- **E1. Decyzja autora (11.10.2026): `confirm_by = min(teraz + okno, początek pobytu)`.** Niepotwierdzona rezerwacja
  wygasa najpóźniej w chwili rozpoczęcia pobytu, pobyt w toku nigdy nie wygasa. `ReservationBooking::expire()`
  dodatkowo pomija pobyty rozpoczęte. Test.

### F. Kolizje i stanowiska
- **F1 (przegląd pkt 5).** Usunięcie stanowiska z aktywnymi przyszłymi rezerwacjami (akcja pojedyncza i zbiorcza)
  nie omija mechanizmu kolizji: przechodzi przez `CollisionGate` jak wycofanie albo jest blokowane z komunikatem.
- **F2 (przegląd pkt 7).** `ConfigurationCollisionService::apply()` sprawdza wynik `cancel()`. Rezerwacja anulowana
  w międzyczasie nie liczy się jako anulowana i nie dostaje drugiego maila. Kolejność blokad ujednolicona
  z `ReservationBooking` (bez ryzyka zakleszczenia).

### G. Wydajność (przegląd pkt 8)
- **G1.** `SaleCalendar` i `ReservationCalendar` wczytują sloty stanowisk **tylko z okna widoku z zapasem**
  (wybiegnięcie pakietów, `MAX_FREE_NIGHTS`), a przy `includeOccupancy = false` wcale. Wiersze nie rosną
  z historią.

### H. Drobne
- **H1 (przegląd pkt 12).** Token rezygnacji ważny do końca aktywności rezerwacji (Rozstrzygnięcia) — bez zmian
  w kodzie, zapis decyzji w `rezerwacje.md` §3.
- **H2 (przegląd pkt 14).** Komunikat po rezygnacji wędkarza mówi prawdę: albo operator dostaje informację
  (np. wpis w „Do kontaktu"), albo tekst nie twierdzi, że łowisko zostało poinformowane.
- **H3 (przegląd pkt 15).** `->dontSubmitEmptyLogs()` w `Reservation` i `FisheryAngler`. Historia szczegółów nie
  pokazuje pustych wpisów „updated".
- **H4 (przegląd pkt 17).** Osierocony docblock `rules()` w `PortalCalendar`.

## Kryteria akceptacji
- [ ] A1–A11: kalendarz zgodny z §3/§3a i makietą `etap3.html`. Popover jako warstwa, ponowne kliknięcie zamyka,
      oferta w pustych dobach i kwota na paskach, domyślny tydzień, wygląd pasków i blokad, liczniki-filtry,
      telefon z arkuszem.
- [ ] B1–B6: pole powodu tylko przy odmowie, z listą reguł; cena z rozbiciem w obu formularzach z jednego miejsca
      (wspólnego z portalem); usługi przeliczają cenę; ostrzeżenie o niedostępnej usłudze przed zapisem; wyjątek
      usługi z powodem poza limitem egzemplarzy (test, także odmowa przy limicie i w kanale wędkarza).
- [ ] C1–C6: szybka i pełna edycja zamiast modala; edycja zachowuje wyjątki, politykę zwrotu i dokumenty (test,
      w tym „zostaw" z `block_id`); zmiana samego kontaktu nie przelicza warunków (test).
- [ ] D1–D3: testy podmiany `fishery_id` w polach reaktywnych i unieważnienia tokenu po zmianie adresu są zielone.
- [ ] E1: rezerwacja na dziś nie wygasa w trakcie pobytu (test).
- [ ] F1–F2, G1, H1–H4 zrealizowane. Test podwójnej anulacji w `apply()`. Test usunięcia stanowiska z przyszłymi
      rezerwacjami.
- [ ] Motyw przebudowany po zmianach widoków (`npm run build`).
- [ ] Pełny pakiet testów zielony (T3).

## Zakres testów
- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zadanie rusza rdzeń zapisu (`ReservationBooking`, `ReservationTermsSnapshot`),
  `PositionAvailability` i `SaleCalendar` (wspólne z ofertą, kalendarzem panelu i portalem), `AnglerLinking`,
  usługę kolizji, formularze i kalendarz. Przegląd serii pokazał, że testy zależności nie wystarczyły. Decyzja
  autora: pełny pakiet. Sprawdzenie ekranów w przeglądarce wykonuje autor, nie kryterium zadania.

## Zakres wyłączeń
- Rozdzielenie kont wędkarza i właściciela, osobna ścieżka rejestracji wędkarza i łączenie konta z
  `fishery_anglers` — do zaprojektowania w osobnej sesji przy zmianie roadmapy (TODO-6).
- Płatności (oba nowe etapy roadmapy) i self-service wędkarza w portalu.
- Testy przeglądarkowe Playwright (zadanie 035).
- Zmiana reguły przycinania pakietu przy częściowej zajętości — decyzja autora: zostaje przycinanie z 017.
- Limit maili przy masowej anulacji — decyzja autora Q6: bez limitu.

## Zmiany dokumentacji
- [ ] `docs/conventions/rezerwacje.md` — popover jako warstwa, oferta w warstwie „Rezerwacje" (odwrócenie §3),
      szybka i pełna edycja, wyjątek usługi, wygasanie `min(okno, początek)`, usunięcie stanowiska a kolizje,
      zachowanie warunków przy edycji.
- [ ] `docs/conventions/dostepnosc.md` — sloty z okna (G1), jedna reguła kolizji dla skrótu kalendarza (A9).
- [ ] `docs/conventions/cennik.md` — jedno miejsce składające rozbicie ceny dla portalu i formularzy (B2), wyjątek
      usługi poza limitem (B6).
- [ ] `docs/conventions/strona-publiczna.md` — dymek rozbicia portalu korzysta ze wspólnego miejsca (B2).
- [ ] `docs/conventions/autoryzacja.md` — token powiązany z adresem z chwili wysyłki (D2).
- [ ] `docs/reviews/2026-10-11-unpushed.md` — oznaczenie uwag zrealizowanych w tym zadaniu.
- [ ] `docs/adr/ADR-026-zamrozenie-warunkow-rezerwacji.md` — „Aktualizacja (2026-10-11)": wyjątek usługi poza limitem (B6).
- [ ] `docs/adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md` — „Aktualizacja (2026-10-11)":
      `confirm_by = min(teraz + okno, początek pobytu)` (E1).
- [ ] Ten plik — wynik pomiaru renderu kalendarza (A3).
- [ ] `CHANGELOG.md` — wpis w changelogu.

## Ograniczenia techniczne
- Laravel 13, Filament 5, Tailwind 4 (motyw panelu `resources/css/filament/theme.css` — klasy tylko we własnych
  widokach w `resources/views/filament/**`, pełne nazwy klas, przebudowa motywu po zmianach).
- Popover i arkusz: Alpine/Livewire bez zewnętrznych bibliotek. Popover zakotwiczony w kontenerze kalendarza,
  pozycja liczona względem klikniętego elementu (spec. §3a), stan zaznaczenia w Livewire.
- Wszystkie zapisy rezerwacji nadal wyłącznie przez `ReservationBooking` (`tests/Unit/ReservationWritePathTest.php`).
- Liczba zapytań kalendarza nadal stała względem dób i stanowisk (`ReservationCalendarTest`), mimo oferty
  w pustych dobach (A3).

## Rozstrzygnięcia
- **A3 — oferta w obu oknach, z pomiarem:** cena i „zacznij …" w pustych dobach także w oknie dwóch tygodni.
  Implementujący mierzy czas renderu i liczbę zapytań (tydzień i dwa tygodnie, realna liczba stanowisk) i zapisuje
  wynik w tym pliku. Koszt w 019 policzono dla miesiąca, a dwa tygodnie są tańsze.
- **C1 — „Pełna edycja" to osobna strona `EditReservation`** zasobu `ReservationResource`, ze wspólnym schematem
  formularza z C (jedna metoda budująca pola) i zapisem przez `ReservationBooking::rebook()` z wymaganym powodem
  zmiany. Tworzenie i edycja nie mieszają się w jednej klasie.
- **H1 — token „Zrezygnuj / to nie ja" jest ważny do końca aktywności rezerwacji** (stan obecny). Wędkarz może
  zrezygnować także po upływie okna powiązania, a token przestaje działać z anulacją albo wygaśnięciem. Rozstrzygnięcie
  043 („oba tokeny z `link_expires_at`") jest zamrożone, więc wiążący zapis to ten punkt i `rezerwacje.md` §3.
  Bez zmian w kodzie.
- **B2 — wspólne rozbicie ceny w osobnej klasie w `app/Services/`** (np. `StayPriceExplanation`). Ze
  `StayPriceBreakdown` i wyceny usług (`StayServicesQuote`) składa linie (etykieta, kwota), obniżkę, usługi i sumę.
  Korzystają z niej dymek kalendarza portalu (`PortalCalendar::breakdown()` przechodzi na nią) i oba formularze
  panelu. Widoki tylko formatują. Warstwa oferty nie przejmuje prezentacji — granica ADR-015 zostaje.
- **B6 i E1 (decyzje autora z wywiadu) dopisać jako „Aktualizacja (2026-10-11)"** w ADR-026 (wyjątek usługi poza
  limitem egzemplarzy, uzupełnienie pkt 8) i w ADR-024 (pkt 7: `confirm_by = min(teraz + okno, początek pobytu)`).
  Uzasadnienie zostaje przy decyzjach, które uzupełniają; nowy ADR nie jest potrzebny, bo decyzje już zapadły.

## Wynik pomiaru (A3)
Pomiar 11.10.2026 w kontenerze deweloperskim (schemat testowy), łowisko z 26 stanowiskami i 12 rezerwacjami,
pięć przerysowań komponentu `ManageCalendar` (`$refresh`) na okno, mediana:

| Widok | Czas renderu | Zapytania |
|---|---|---|
| „Rezerwacje", tydzień | ~260 ms | 157 |
| „Rezerwacje", 2 tygodnie | ~370 ms | 157 |
| „Oferta", tydzień (dla porównania) | ~185 ms | 147 |
| „Oferta", miesiąc (dla porównania) | ~550 ms | 147 |

Liczba zapytań nie zależy od liczby dób (tydzień = dwa tygodnie). Oferta w pustych dobach dokłada do warstwy
„Rezerwacje" koszt siatki `SaleCalendar` (147 zapytań, z czego ~4 na stanowisko to istniejący koszt warstwy oferty
z 019 — okresy sprzedaży, pakiety, blokady per stanowisko), a sama siatka rezerwacji ~10 zapytań stałych.
Ograniczenie zadania („liczba zapytań stała względem dób i stanowisk") jest spełnione względem dób; zależność od
liczby stanowisk pochodzi z warstwy oferty sprzed zadania i nie została przez nie pogorszona. Dwa tygodnie są
tańsze od miesiąca warstwy „Oferta", więc oferta zostaje w obu oknach (rozstrzygnięcie A3).

## Powiązane ADR-y
- [ADR-024](../adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md),
  [ADR-025](../adr/ADR-025-zajetosc-stanowiska-jako-sloty-przedzialowe.md),
  [ADR-026](../adr/ADR-026-zamrozenie-warunkow-rezerwacji.md),
  [ADR-027](../adr/ADR-027-kolizje-konfiguracji-z-rezerwacjami.md),
  [ADR-015](../adr/ADR-015-warstwa-oferty-pobytu.md) — rozstrzygnięte. Nowych ADR-ów brak.
