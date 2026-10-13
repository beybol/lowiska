# 052 — Podpowiedź istniejącego wędkarza w formularzu rezerwacji

## Opis problemu

Operator, który zakłada rezerwację ręcznie i wpisuje dane wędkarza od nowa (zamiast wybrać go z listy), za każdym
razem tworzy nową relację w `fishery_anglers`, nawet gdy w łowisku jest już wędkarz o identycznych danych
(np. ta sama nazwa bez e-maila i telefonu). `ReservationBooking::angler()` łączy relację wyłącznie po znormalizowanym
e-mailu (ADR-024 pkt 4: tożsamością jest e-mail, telefon nie łączy automatycznie). Skutek: duplikaty na stronie
„Wędkarze", historia rezerwacji rozbita na kilka rekordów.

Pełny formularz (C) ma pole wyboru istniejącego wędkarza (`fishery_angler_id`, `ReservationFormSchema::anglerSection()`),
ale nic nie podpowiada, że wpisywane dane pasują do istniejącej relacji. **Krótki formularz kalendarza
(`ReservationFormSchema::quick()`) w ogóle nie ma wyboru wędkarza** — a to z niego najczęściej zakłada się rezerwacje
telefoniczne.

Automatyczne dowiązywanie po danych opisowych zostało odrzucone w rozmowie z autorem (13.10.2026): dwie różne osoby
o tym samym imieniu i nazwisku albo wspólny telefon rodziny scaliłyby historie rezerwacji i dane kontaktowe, a ADR-024
pkt 4 pozwala użyć telefonu wyłącznie jako podpowiedzi w obrębie relacji łowiska.

## Wymagania

- Gdy operator wpisuje dane **nowego** wędkarza (bez wybranego `fishery_angler_id`), a imię i nazwisko albo telefon
  pasują do istniejącej relacji `fishery_anglers` **tego łowiska** (reguła dopasowania — R1, R2), formularz pokazuje
  pod polami kontaktu ramkę ostrzegawczą „W tym łowisku jest już wędkarz o tych danych" z listą kandydatów (nazwa,
  telefon, e-mail z danych efektywnych, liczba rezerwacji) i przyciskiem „Użyj tego wędkarza" przy każdym (R3).
- „Użyj tego wędkarza" ustawia `fishery_angler_id` i wypełnia pola danymi efektywnymi relacji — tak samo jak dziś wybór
  z listy w pełnym formularzu; pola kontaktu stają się wyłączone. Decyzja należy do operatora; bez kliknięcia zapis
  działa jak dziś (nowa relacja).
- Podpowiedź działa w **pełnym formularzu (C)** i w **krótkim formularzu kalendarza** („Zarezerwuj…"). Krótki formularz
  dostaje w tym celu ukryte pole `fishery_angler_id`, obsłużone przez `ReservationFormSubmission::book()` tak samo jak
  w pełnym, oraz informację „Istniejący wędkarz łowiska" z linkiem „Zmień", który czyści `fishery_angler_id`
  i odblokowuje pola (R4). Pełny formularz cofa wybór wyczyszczeniem Selecta, jak dziś.
- Kandydaci wyłącznie z relacji tego łowiska, bez relacji z cofniętym dostępem (`revoked`), na danych efektywnych
  (`FisheryAngler::effectiveSql()`) — ta sama reguła danych co wyszukiwarka strony „Wędkarze" (`rezerwacje.md` §6).
  Najwyżej 5 kandydatów (R1).
- Edycja rezerwacji (szybka i pełna) bez zmian — podpowiedź dotyczy wyłącznie zakładania rezerwacji.
- Dane od klienta: id kandydata wskazanego przyciskiem przechodzi przez to samo zawężenie do łowiska co dziś
  (`ReservationFormSubmission::findAngler()`); podmienione id z innego łowiska nie ustawia niczego.

## Kryteria akceptacji

- [x] Pełny formularz: wpisanie nazwy albo telefonu istniejącego wędkarza łowiska pokazuje go jako kandydata;
      „Użyj tego wędkarza" ustawia `fishery_angler_id`, a zapis nie tworzy nowej relacji (test).
- [x] Krótki formularz kalendarza: to samo, zapis z kalendarza dowiązuje rezerwację do wskazanej relacji (test).
- [x] Bez kliknięcia w podpowiedź zapis tworzy nową relację jak dziś — brak automatycznego dowiązywania (test).
- [x] Kandydaci nie obejmują relacji innego łowiska ani z cofniętym dostępem; podmienione id kandydata z innego łowiska
      nie dowiązuje rezerwacji (test).
- [x] Reguła dopasowania wg R1/R2: fraza nazwy krótsza niż 3 znaki i numer krótszy niż 9 cyfr nie dają podpowiedzi;
      „+48 600 100 200" trafia na „600-100-200"; limit 5 kandydatów (test).
- [x] Testy z zakresu T2 zielone; **pełny pakiet odroczony** na koniec sesji (`/review-implementation`, Krok 1).

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="AnglerSuggestionTest|ReservationPanelTest|ReservationCalendarTest|ReservationReviewFixesTest|ReservationBookingTest|FisheryAnglersPageTest"`
- **Uzasadnienie:** zmiana dotyka `ReservationFormSchema` (formularz C, krótki formularz kalendarza) i
  `ReservationFormSubmission` — zapisu rezerwacji z obu paneli; nie rusza rdzenia dostępności, polityk ani niczego
  z listy wyzwalaczy T3. Pełny pakiet — odroczony na koniec sesji.

## Zakres wyłączeń

- Automatyczne dowiązywanie rezerwacji po imieniu, nazwisku albo telefonie (odrzucone — ADR-024 pkt 4).
- Scalanie istniejących duplikatów (np. „Scal wędkarzy" na stronie „Wędkarze") — przepięcie rezerwacji, historii
  i zgód; osobne zadanie, jeśli duplikaty okażą się problemem w praktyce.
- Zmiana reguły łączenia po e-mailu w `ReservationBooking::angler()`.
- Podpowiedź w portalu wędkarza i w edycji rezerwacji.

## Zmiany dokumentacji

- [x] `docs/conventions/rezerwacje.md` — §1: podpowiedź istniejącego wędkarza (reguła dopasowania, brak automatycznego
      dowiązywania, krótki formularz z `fishery_angler_id`)
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Filament 5 / Livewire 4: podpowiedź reaguje na pola z `live(debounce)`; krótki formularz kalendarza jest modalem
  akcji `quickBook` (`ManageCalendar`) — podpowiedź renderuje się w schemacie modala.
- ADR-024 pkt 2 i 4: wyszukiwanie wyłącznie w relacjach łowiska, nigdy w `users`; telefon tylko jako podpowiedź.
- MySQL 8 (`REGEXP_REPLACE`) — schemat roboczy i testowy na tym samym silniku (ADR-001).

## Rozstrzygnięcia

- **R1 — imię i nazwisko: dopasowanie częściowe** (decyzja autora 13.10.2026). Efektywna nazwa kandydata *zawiera*
  wpisaną frazę (bez wielkości liter, po przycięciu i zwinięciu spacji; `like` z ucieczką `%`/`_` jak
  `matchingContact`). Ponieważ częściowe dopasowanie daje szum przy krótkich frazach, **podpowiedź po nazwie rusza od
  3 znaków** frazy. Kolejność: najpierw kandydaci o nazwie równej frazie i trafieni po telefonie, potem pozostali
  alfabetycznie; **limit 5**. Próg i limit to wartości do zmiany jedną linijką.
- **R2 — telefon: ostatnie 9 cyfr** (decyzja autora). Porównanie samych cyfr, po 9 ostatnich, w SQL
  (`right(regexp_replace(<effectiveSql('phone')>, '[^0-9]', ''), 9)`); podpowiedź po telefonie rusza dopiero, gdy
  wpisany numer ma co najmniej 9 cyfr. Bez nowej kolumny ani migracji. E-mail nie jest kryterium podpowiedzi —
  `ReservationBooking::angler()` i tak łączy po znormalizowanym e-mailu.
- **R3 — miejsce: ramka pod polami kontaktu** (decyzja autora), ten sam komponent w pełnym formularzu i w modalu
  krótkiego; jeden wiersz na kandydata z przyciskiem „Use this angler". Pola nazwy i telefonu reagują
  `live(onBlur: true)` — zapytanie po opuszczeniu pola, nie po każdym znaku. Ramka znika, gdy `fishery_angler_id`
  jest ustawione.
- **R4 — cofnięcie w krótkim formularzu: blokada pól + „Change"** (decyzja autora). Po wyborze pola kontaktu są
  wyłączone, pod nimi „Existing angler of this fishery" i link „Change", który czyści `fishery_angler_id`
  i odblokowuje pola (z zachowaniem wpisanych wartości).
- **R5 — logika wyszukiwania kandydatów ma jeden dom**: scope/metoda na `FisheryAngler` (obok `matchingContact`),
  wołana z obu formularzy; schemat Filamenta tylko renderuje wynik.

## Powiązane ADR-y

- [ADR-024](../../adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md) — wędkarz jako tożsamość globalna i relacja z łowiskiem (pkt 2 i 4); bez nowego ADR-a.
