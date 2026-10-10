# 042 — Kalendarz: warstwa „Rezerwacje" (klik w dobę, popover, modal, telefon)

## Opis problemu
Kalendarz z zadania 019 jest wyłącznie podglądem konfiguracji sprzedaży („co z tego wynika"). Od etapu 3
jest **głównym narzędziem pracy operatora** — z niego zakłada się i zmienia rezerwacje. Zamiast budować
drugi, podobny ekran, przerabiamy `ManageCalendar`: ta sama siatka i ten sam układ, dwie warstwy
(**Oferta** i **Rezerwacje**). Specyfikacja i interaktywna makieta: `docs/project/mockups/etap3/README.md`
§3 i §3a, `etap3.html` (moduły A, A·tel).

## Wymagania
- Przełącznik warstw na `ManageCalendar` (stan w adresie, `?layer=`): **Oferta** = dzisiejszy widok cen
  (019, bez zmian funkcji; zajęte doby pokazują „Zajęte" dzięki `Occupied` z 039), **Rezerwacje** =
  nowa warstwa operacyjna, domyślna dla operatora.
- Warstwa Rezerwacje: paski rezerwacji rozciągnięte na doby pobytu (rysowane z `starts_at/ends_at`,
  przycinane do okna, znacznik ◀ dla pobytu zaczętego wcześniej), blokady kreskowane, status kolorem
  (potwierdzona, opłacona, do potwierdzenia z kreskowaniem i terminem wygaśnięcia, w toku), znacznik ☎ „do kontaktu".
- Okna: tydzień i **2 tygodnie** (nowa wartość `CalendarWindow`; miesiąc tylko w Ofercie); nagłówek dni
  z oznaczeniem **weekendów**, „dziś" i szarą przeszłością; liczniki „Przyjazdy dziś / Wyjazdy dziś /
  Niepotwierdzone / Do kontaktu"; filtry grupy i statusu.
- **Mechanika kliknięć (README §3a):** klik w wolną dobę → zaznaczenie i **popover zakotwiczony w wierszu**
  (przyjazd→wyjazd, doby, cena z oferty), drugi klik wydłuża zakres (nie przeskoczy zajętej doby);
  „Zarezerwuj…" → modal krótkiego formularza z linkiem „Pełny formularz →" (ekran C z parametrami);
  „Zablokuj…" → modal blokady. Klik w pasek → popover z akcjami: Podgląd, Edytuj, Potwierdź, Oznacz jako
  opłaconą, Anuluj… (modale z zadania 041). Esc/✕/klik poza zamykają.
- **Telefon:** pasek dni + lista stanowisk dla dnia; wolne stanowisko pokazuje „wolne do dd.mm · maks. N dób"
  (do następnej rezerwacji lub blokady, nie więcej niż 30); arkusz od dołu ze stepperem dób → krótki formularz;
  zajęte stanowisko → arkusz z akcjami rezerwacji.
- Dostępność z klawiatury: komórki i paski fokusowalne, Enter = klik, Esc zamyka.
- Warstwa Oferta pozostaje **wyłącznie podglądem** — kliknięcie prowadzi co najwyżej do konfiguracji.
- Wydajność: zajętość czytana raz na stanowisko i zakres; liczba zapytań nie rośnie z liczbą dób/stanowisk (test).
- Amendment zasady G4 w `ZASADY-PROJEKTOWE.md`: kalendarz ma warstwę podglądową i operacyjną.

## Kryteria akceptacji
- [ ] Warstwa Oferta: zachowanie 019 bez zmian (testy 019 zielone), zajęta doba = „Zajęte".
- [ ] Warstwa Rezerwacje pokazuje rezerwacje i blokady poprawnie przycięte do okna (test jednostkowy układu).
- [ ] Zaznaczenie zakresu nie przeskakuje zajętej doby; przeszłość nieklikalna (test Livewire).
- [ ] Popover i modal pojawiają się w polu widzenia dla pierwszego i ostatniego wiersza (ręcznie/Playwright, zad. 035).
- [ ] „Zarezerwuj…" zapisuje przez serwis z 039; „Pełny formularz →" otwiera C z parametrami.
- [ ] Akcje na rezerwacji działają bez przejścia do szczegółów; „Anuluj…" trwającej pyta dodatkowo.
- [ ] Widok telefonu: poprawna liczba dób „do następnej rezerwacji"; stepper nie przekracza maksimum.
- [ ] Liczba zapytań stała względem liczby dób (test).
- [ ] G4 zaktualizowane; docblock i test `ManageCalendar` odzwierciedlają dwie warstwy.

## Zakres testów
- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="Calendar|SaleCalendar|Reservation"`
- **Uzasadnienie:** zmiana kontraktu `ManageCalendar`/`SaleCalendar`/`CalendarWindow` używanego w panelu i
  testach 019; brak migracji i polityk w tym zadaniu (uprawnienia z 041). Pełny pakiet odroczony na koniec sesji.

## Zakres wyłączeń
- Przeciąganie zaznaczenia myszą i zmiana terminu przez przeciągnięcie paska.
- Widok miesiąca w warstwie Rezerwacje; eksport grafiku (Q4).
- Publiczny kalendarz portalu (046); kolizje blokad (044).
- Testy przeglądarkowe (zadanie 035, etap 2).

## Zmiany dokumentacji
- [ ] `docs/project/ZASADY-PROJEKTOWE.md` — G4: dwie warstwy
- [ ] `docs/conventions/panel-wlasciciela.md` — wyjątek: modale dla krótkich akcji w kalendarzu
- [ ] `docs/conventions/dostepnosc.md` — kalendarz a `Occupied`
- [ ] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne
- Siatka jako widok Blade (tabela Filamenta nie zna colspanu); styl z motywu panelu (Tailwind 4); popover
  zakotwiczony w kontenerze kalendarza (nie `position: fixed`), z pozycją liczoną względem wiersza.
- Zależność: 039 (zajętość), 040 (mail anulacji), 041 (modale i formularz).

## Rozstrzygnięcia
- **Kryterium Playwright** („popover w polu widzenia") jest odroczone do zadania 035 (etap 2); w tej serii pokrywają je
  testy Livewire/układu, a sprawdzenie wizualne ręcznie po serii.
- **Ograniczenie kolejności:** modale rezerwacji i anulacji pochodzą z 041; kalendarz ich nie duplikuje (współdzielone
  komponenty/akcje Filamenta).
- **Autoryzacja:** bez nowych uprawnień — akcje wołają polityki `ReservationResource` z 041 i `FisheryPolicy`.
- **„Maks. N dób" na telefonie:** liczone przez ten sam serwis dostępności co zapis (jedno źródło prawdy), limit 30 jako stała w jednym miejscu.
- Domyślna warstwa „Rezerwacje" dla operatora; admin też domyślnie Rezerwacje. Parametr `?layer=` z niedozwoloną wartością → domyślna.

## Powiązane ADR-y
- [ADR-025](../adr/ADR-025-zajetosc-stanowiska-jako-sloty-przedzialowe.md),
  [ADR-027](../adr/ADR-027-kolizje-konfiguracji-z-rezerwacjami.md) — rozstrzygnięte.
