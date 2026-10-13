# ADR-029 — Pamięć odczytów dostępności na jedno żądanie

- **Status:** accepted
- **Data:** 2026-10-13
- **Zadanie:** [050 — Powtarzane zapytania w rdzeniu dostępności](../tasks/implemented/050-powtarzane-zapytania-w-rdzeniu-dostepnosci.md)

## Kontekst

Usługi dostępności (`FishingDayCalendar`, `SalePeriodFinder`, `StaySellability`, `PositionAvailability`)
pamiętają odczyty **w obrębie własnej instancji** ([`dostepnosc.md`](../conventions/dostepnosc.md) §2).
Siatki kalendarzy (`SaleCalendar`, `ReservationCalendar`, `PortalCalendar`) tworzą jednak osobne instancje
na stanowisko i na pytanie, więc ten sam odczyt powtarza się wielokrotnie w jednym żądaniu. Na stagingu
(12.10.2026, Flare) jedno kliknięcie w dzień kalendarza rezerwacji to 126 zapytań i 462 ms w bazie,
w tym `sale_periods` 39×, blokady 20× (osobno na stanowisko, N+1) i `whole_term_periods` 13×. Koszt rośnie
liniowo ze stanowiskami. Usługi tworzy 28 miejsc w `app/`, a ten sam rdzeń obsługuje warstwę „Oferta",
kalendarz portalu, koszyk i zapis rezerwacji.

Ograniczenia, które rozwiązanie musi uszanować:

- **Zapis rezerwacji czyta świeżo.** `ReservationBooking` po blokadzie wiersza zadaje nowe pytanie
  o dostępność (`dostepnosc.md` §6); stan sprzed blokady nie może trafić do tej kontroli.
- **Nie ma trwałego bufora werdyktu między żądaniami** (`dostepnosc.md` §2). Ewentualne zapamiętywanie
  między żądaniami wymaga osobnej decyzji z uzasadnieniem pomiarowym. Autor zadania zakłada, że część
  odczytów kiedyś będzie zapamiętywana, więc rozwiązanie ma mieć na to jedno miejsce.
- **Optymalizacja nie może zależeć od dyscypliny wołającego.** Nowy kalendarz albo nowa ścieżka ma
  dostać ją bez pamiętania o przekazaniu czegokolwiek.

Dlaczego ADR: **zasięg** — wiąże każde miejsce, które pyta o dostępność, także przyszłe;
**koszt odwrócenia** — zmienia regułę §2 i sposób odczytu w rdzeniu wszystkich ścieżek sprzedaży;
**uzasadnienie** — dlaczego pamięć żyje w kontenerze na żądanie, a nie w konstruktorach, i dlaczego zapis
rezerwacji ją świadomie zeruje, nie wynika z samego kodu.

## Alternatywy

### Opcja A — gotowe dane od wołającego
Rozszerzenie wzorca z [`cennik.md`](../conventions/cennik.md) („cennik może przyjść GOTOWY od wołającego"):
usługi przyjmują opcjonalne, wczytane wcześniej kolekcje; siatki wczytują raz i podają dalej.

**Zalety:**
- brak nowego pojęcia, najbliżej istniejącego kodu,
- dane jawne w wywołaniu; łatwo podać tablice w teście bez bazy.

**Wady:**
- parametry przechodzą przez cały łańcuch (`SaleCalendar` → `StayOffer` → `StaySellability` →
  `PositionAvailability`/`SalePeriodFinder`/`FishingDayCalendar`); każda nowa kolekcja zmienia wszystkie
  konstruktory po drodze,
- kształt zapytania (filtr skutku, usunięte, sortowanie, podział blokad na stanowiska) powtórzony
  u każdego wołającego — ryzyko cichego rozjazdu werdyktów między kalendarzami,
- wołający, który zapomni podać kolekcję, po cichu przywraca dziesiątki zapytań,
- brak jednego miejsca na przyszłe zapamiętywanie.

### Opcja B — obiekt kontekstu przekazywany do usług
Nowa klasa z wczytanymi danymi łowiska na jedno pytanie, przekazywana do usług w miejsce kolekcji.

**Zalety:**
- jedno miejsce na kształt zapytań i na przyszłe zapamiętywanie,
- osobno testowalna klasa.

**Wady:**
- nadal zależy od wołającego — z kontekstu można nie skorzystać i optymalizacja znika tak samo jak w A,
- duża zmiana: nowy parametr w konstruktorach łańcucha i w 28 miejscach tworzenia,
- obiekt łatwo przekazać do ścieżki zapisu albo zatrzymać dłużej niż jedno pytanie.

### Opcja C — magazyn odczytów na żądanie, używany wewnątrz usług
Klasa (roboczo `FisheryReadCache`) rejestrowana w kontenerze jako `scoped` (Laravel czyści ją po każdym
żądaniu i po każdym zadaniu kolejki). Usługi czytają przez nią w swoich prywatnych metodach odczytu;
wołający niczego nie przekazują.

**Zalety:**
- nie da się jej ominąć z zewnątrz — każda instancja usługi, niezależnie od twórcy, czyta przez magazyn;
  nowe ścieżki dostają optymalizację bez dodatkowej pracy,
- najmniejsza zmiana: jedna klasa, cztery prywatne metody odczytu, konstruktory i 28 miejsc tworzenia bez zmian,
- jedno miejsce na kształt zapytań i na ewentualne przyszłe zapamiętywanie,
- osobno testowalna (liczba zapytań, grupowanie blokad, unieważnianie).

**Wady:**
- ukryty stan wspólny — z konstruktora usługi nie widać, że korzysta z pamięci,
- dane mogą się zestarzeć po zapisie w tym samym żądaniu; zdarzenia modeli tego nie łapią przy masowych
  zapisach (`query()->update()`, `DB::table()`) ani przy zmianach tabeli łączącej blokady ze stanowiskami
  (`sync`/`attach`/`detach`),
- długie procesy (komendy, zadania przetwarzające wiele łowisk) trzymają magazyn przez całą operację.

### Opcja D — relacje wczytane raz na modelu (`setRelation`)
Odrzucona przy przeglądzie zadania: model `Fishery` żyje przez całe żądanie, więc zapis rezerwacji w tym
samym żądaniu mógłby zobaczyć nieaktualne blokady — sprzeczne z wymaganiem świeżego odczytu w transakcji.

## Rekomendacja

**Opcja C.** Daje to, czego oczekiwano od B (osobna klasa, jedno miejsce na kształt zapytań i przyszłe
zapamiętywanie, testowalność), bez zależności od dyscypliny wołającego (A, B) i bez przebudowy
konstruktorów. Jej ryzyko — starzenie się danych — zamyka się unieważnianiem w kilku jawnych miejscach
i testem.

## Decyzja

**Opcja C — magazyn odczytów na żądanie, używany wewnątrz usług** (autor, 13.10.2026). Obowiązują
następujące reguły:

1. **Zakres danych.** Magazyn trzyma odczyty konfiguracji łowiska: okresy sprzedaży, okresy całego terminu
   i blokady (wszystkie stanowiska łowiska naraz, pogrupowane po stanowisku i skutku). **Zajętość (sloty
   rezerwacji) nie trafia do magazynu** — ochrona przed podwójną rezerwacją nie zależy od pamięci.
   Rozszerzenie zakresu (np. cennik) to zmiana tej listy w tym ADR-ze, nie samowolne dopisanie metody.
2. **Leniwość.** Rejestracja `scoped`; instancja powstaje przy pierwszym użyciu. Konstruktor niczego nie
   wczytuje; każda kolekcja wczytuje się osobno, przy pierwszym pytaniu, wyłącznie dla pytanego łowiska.
   Nic nie rozgrzewa magazynu z góry (middleware, `boot()`, render-hooki). Strona, która nie pyta
   o dostępność, nie wykonuje żadnego zapytania.
3. **Jedyna droga odczytu.** Usługi dostępności czytają te dane wyłącznie przez magazyn — także poza
   siatkami. Zapytanie o te tabele napisane bezpośrednio w usłudze dostępności jest defektem.
4. **Unieważnianie.** Zapis i usunięcie `SalePeriod`, `WholeTermPeriod` i `AvailabilityBlock` (zdarzenia
   modeli) czyszczą wpisy danego łowiska. Tabela łącząca blokady ze stanowiskami ma model
   (`AvailabilityBlockPosition`, `using()` po obu stronach relacji), więc `sync`/`attach`/`detach` z każdego
   miejsca czyszczą magazyn przez zdarzenia — także formularz Filamenta, który zapisuje stanowiska po zapisie
   modelu. Masowe zapisy tych tabel (`query()->update()`, `DB::table()`) czyszczą magazyn **jawnie**
   w miejscu zapisu. *(Doprecyzowanie przy implementacji 050: pierwotnie jawne czyszczenie także przy tabeli
   łączącej — model tabeli łączącej pokrywa te miejsca bez wyjątków.)*
5. **Zapis rezerwacji czyta świeżo.** `ReservationBooking` czyści wpisy łowiska tuż po blokadzie wiersza,
   przed nowym pytaniem o dostępność. Pilnuje tego test: zmiana dokonana po zbudowaniu siatki, a przed
   zapisem, jest widziana przez kontrolę w transakcji.
6. **Czas życia: jedna operacja.** Nigdy jako `singleton`, nigdy w polu komponentu Livewire ani w polu
   statycznym. Komenda albo zadanie przetwarzające wiele łowisk czyści magazyn między łowiskami.
7. **Zapamiętywanie między żądaniami** (cache) nadal wymaga osobnej decyzji z uzasadnieniem pomiarowym
   (`dostepnosc.md` §2). Ten magazyn jest jej docelowym miejscem, nie zgodą na nią.

Reguła „pamięć w obrębie instancji" z `dostepnosc.md` §2 zostaje przepisana na „pamięć w obrębie żądania
przez magazyn, z unieważnianiem" w ramach zadania 050.
