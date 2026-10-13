# 050 — Powtarzane zapytania w rdzeniu dostępności (siatka kalendarza)

## Opis problemu

Klik w dzień w kalendarzu rezerwacji (warstwa „Rezerwacje", `ManageCalendar::selectCell()`) trwa na stagingu
~1,07 s (mediana z 16 kliknięć, Flare, projekt 112055, 12.10.2026, już po zapamiętaniu siatki na żądanie).
Rozkład jednego kliknięcia (ślad `58718c487ee44e364dcede3f8a8019a7`, 1066 ms):

| Etap | Czas | Zapytania |
|---|---|---|
| start aplikacji i middleware | ~55 ms | 1 |
| odtworzenie stanu komponentu | 47 ms | 8 (26 ms) |
| `selectCell()` — budowa siatki | 659 ms | 84 (304 ms) |
| render widoku | 272 ms | 30 (100 ms) |
| **razem** | | **126 zapytań, 462 ms w bazie** |

Średni koszt zapytania na stagingu to ~3,7 ms (Cloud SQL przez sieć), lokalnie ~0,4 ms. Ponad połowa
zapytań to **powtórzenia tego samego odczytu w obrębie jednego żądania**:

- `sale_periods` łowiska — **39×** (`SalePeriodFinder::periods()`, `FishingDayCalendar::salePeriods()`),
- blokady stanowiska — **20×**, osobne zapytanie **na każde stanowisko** (`PositionAvailability`, `where position_id = ?`)
  — klasyczne N+1,
- `whole_term_periods` — **13×** (`StaySellability::wholeTermPeriods()`).

Każda z tych usług pamięta odczyt **w obrębie własnej instancji** (`dostepnosc.md` §2), ale siatka tworzy
osobne instancje na stanowisko i na pytanie, więc pamięć instancji nie działa między nimi. W `app/` jest
28 miejsc tworzenia tych usług. Liczba zapytań rośnie ze stanowiskami: przy 30 stanowiskach samo N+1 blokad
to ponad 100 zapytań na kliknięcie. Ten sam rdzeń obsługuje warstwę „Oferta", kalendarz portalu, koszyk
i zapis rezerwacji.

## Wymagania

- Magazyn odczytów na żądanie wg [ADR-029](../../adr/ADR-029-pamiec-odczytow-dostepnosci-na-zadanie.md)
  (roboczo `FisheryReadCache`, rejestracja `scoped`): okresy sprzedaży, okresy całego terminu i blokady
  łowiska są wczytywane **raz na żądanie i łowisko**, a nie raz na instancję usługi — niezależnie od liczby
  stanowisk i dób w oknie. Leniwie, każda kolekcja osobno (ADR-029, reguła 2).
- Usługi czytają przez magazyn w swoich prywatnych metodach odczytu: `SalePeriodFinder::periods()`,
  `FishingDayCalendar::salePeriods()`, `StaySellability::wholeTermPeriods()` i odczyt blokad
  w `PositionAvailability`. Konstruktory i miejsca tworzenia usług bez zmian.
- Blokady wczytują się zbiorczo dla wszystkich stanowisk łowiska (bez N+1), pogrupowane po stanowisku
  i skutku (`effect`) — z tym samym kształtem zapytania co dziś (usunięte, skutek, tabela łącząca).
- Unieważnianie wg ADR-029, reguła 4: zdarzenia modeli `SalePeriod`, `WholeTermPeriod`, `AvailabilityBlock`
  i modelu tabeli łączącej `AvailabilityBlockPosition` (`sync`/`attach`/`detach`); jawne czyszczenie przy
  masowych zapisach tych tabel (przegląd kodu w ramach zadania).
- Werdykty się nie zmieniają: te same powody odmowy, w tej samej kolejności warunków (`dostepnosc.md` §2,
  „Nie przestawiaj jej dla wydajności"), te same ceny.
- Ścieżka zapisu rezerwacji (`ReservationBooking`, blokada wiersza i ponowne sprawdzenie w transakcji,
  `dostepnosc.md` §6) **nadal czyta stan świeży** — czyści wpisy łowiska w magazynie tuż po blokadzie
  wiersza (ADR-029, reguła 5). Zajętość (sloty) nie trafia do magazynu.
- Wyjaśnić ślad stagingu `45c5ac424033e0e06e4354034b63baf2` (200 zapytań: siatka zbudowana w wywołaniu
  i drugi raz w renderze, `sale_periods` 51×) — czy to akcja zerująca siatkę celowo
  (`forgetReservationLayer()`), czy podwójna budowa do usunięcia.
- Test liczby zapytań dla siatki rośnie co najwyżej o stałą przy dokładaniu stanowisk (np. 2 vs 6 stanowisk)
  — wzorem istniejącej kontroli „liczba zapytań nie rośnie z dobami" w `ReservationCalendarTest`.
- Pomiar przed i po w zadaniu (zapytania i czas `selectCell()` w kontenerze; po wdrożeniu — Flare staging).
- Pomiar kosztu PHP po zmianach: czas budowy siatki z podziałem na zapytania i obliczenia PHP oraz czas renderu,
  lokalnie i we Flare (staging). Wynik trafia do treści zadania jako stan wyjściowy dla
  [051](../051-koszt-obliczen-siatki-kalendarza.md).

## Kryteria akceptacji

- [ ] Jedno kliknięcie w dzień (łowisko z 4 stanowiskami, kontener) wykonuje co najwyżej 40 zapytań
      (dziś 57–69), a `sale_periods`, `whole_term_periods` i blokady stanowisk występują po jednym razie.
- [ ] Test liczby zapytań: siatka dla 6 stanowisk zadaje tyle samo zapytań co dla 2 (tolerancja: stała).
- [ ] Testy werdyktów dostępności, ceny i zapisu rezerwacji przechodzą bez zmian asercji.
- [ ] Ścieżka zapisu ma test, że zmiana (blokada albo rezerwacja) dokonana po zbudowaniu siatki, a przed
      zapisem, jest widziana przez kontrolę w transakcji.
- [ ] Test magazynu: utworzenie instancji nie wykonuje zapytań; odczyt jednej kolekcji — dokładnie jedno;
      zapis blokady (model i tabela łącząca) unieważnia wpisy łowiska.
- [ ] Pomiar kosztu PHP i renderu po zmianach zapisany w zadaniu (stan wyjściowy 051).
- [ ] Zielony pełny pakiet testów (T3).

## Zakres testów

- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zmiana dotyka `PositionAvailability`, `FishingDayCalendar`, `SalePeriodFinder`
  i `StaySellability` — rdzenia każdej ścieżki sprzedaży (zapis rezerwacji, koszyk, portal, oba kalendarze;
  28 miejsc tworzenia w `app/`). Nie trafia w listę wyzwalaczy T3, ale leży na każdej ścieżce (decyzja
  autora przy `/create-task`).

## Zakres wyłączeń

- Trwały bufor werdyktu między żądaniami (cache) — zakazany przez `dostepnosc.md` §2 bez osobnej decyzji
  pomiarowej; nie jest celem tego zadania.
- Ograniczenie renderu po kliknięciu do popovera i zaznaczenia (Livewire `@island` itp.) — osobna decyzja
  po pomiarze tego zadania.
- Koszt obliczeń PHP warstwy oferty poza zapytaniami (~350 ms na stagingu) i render — zadanie
  [051](../051-koszt-obliczen-siatki-kalendarza.md), po pomiarze z tego zadania.
- Zmiana kolejności warunków dostępności i komunikatów odmowy.

## Zmiany dokumentacji

- [ ] `docs/conventions/dostepnosc.md` — §2: reguła „pamięć w obrębie instancji" przepisana na magazyn
      na żądanie (ADR-029: jedyna droga odczytu, leniwość, unieważnianie, czas życia); §6: zapis rezerwacji
      czyści wpisy łowiska po blokadzie wiersza
- [ ] `docs/conventions/cennik.md` — jeśli zmieni się sposób budowania zależności warstwy oferty (§ o jednym
      wczytaniu na żądanie)
- [ ] `docs/conventions/rezerwacje.md` — §4: liczba zapytań siatki nie rośnie ze stanowiskami
- [ ] `CHANGELOG.md` — wpis w changelogu (szybsze kalendarze przy wielu stanowiskach)

## Ograniczenia techniczne

- Laravel 13, Filament 5, Livewire 4, MySQL (staging: Cloud SQL — koszt zapytania ~3,7 ms).
- Mechanizm i jego reguły (zakres danych, leniwość, unieważnianie, czas życia, zapis rezerwacji):
  [ADR-029](../../adr/ADR-029-pamiec-odczytow-dostepnosci-na-zadanie.md). Reguła „pamięć w obrębie instancji"
  z `dostepnosc.md` §2 zostaje przepisana.

## Pomiar po implementacji (stan wyjściowy dla 051)

Kontener lokalny, łowisko 2 (4 stanowiska), okno dwóch tygodni, 13.10.2026:

| Pomiar | Przed 050 | Po 050 |
|---|---|---|
| klik w dzień (`selectCell()` + render) — zapytania | 57–69 | **35–39** |
| klik w dzień — czas | 125–175 ms | 100–120 ms |
| `sale_periods` / `whole_term_periods` / blokady na klik | 15× / 5× / 5× | 1× / 1× / 1× |
| budowa siatki (oferta + rezerwacje), ciepło | ~92 ms, 35 zapytań | ~52 ms, 17 zapytań (~6 ms baza, **~47 ms PHP**) |
| render i reszta kliknięcia (różnica) | — | ~50–65 ms |

Lokalnie zapytanie kosztuje ~0,4 ms, na stagingu ~3,7 ms — zysk po stronie bazy jest tam ~10× większy
(oczekiwanie: ~85 zapytań mniej na klik przy łowisku ze stagingu, ok. 300 ms). **Do uzupełnienia po wdrożeniu:**
ślad Flare (projekt 112055) kliknięcia w dzień — podział na wywołanie, render, zapytania i PHP.

Ślad `45c5ac42` (200 zapytań) to `callMountedAction` — zatwierdzenie akcji (np. „Zarezerwuj…"); siatka liczy się
po zapisie od nowa celowo (`forgetReservationLayer()`), więc to nie podwójna budowa do usunięcia.

## Rozstrzygnięcia

- **Mechanizm współdzielenia odczytu: magazyn na żądanie używany wewnątrz usług (ADR-029, opcja C)** —
  optymalizacja nie zależy od wołającego, a konstruktory zostają bez zmian; relacje na modelu odrzucone
  (zapis rezerwacji mógłby zobaczyć nieaktualne blokady).
- **Próg zapytań: ≤ 40 na klik w dzień** (łowisko z 4 stanowiskami, kontener) — z dzisiejszego rozkładu po
  usunięciu powtórzeń wychodzi ok. 37; twardym kryterium jest test skalowania (6 stanowisk = 2).
- **Koszt PHP i render — osobne zadanie 051**; 050 kończy się pomiarem, który jest jego stanem wyjściowym —
  część kosztu PHP to budowa modeli z powtórzonych zapytań, więc profilowanie przed 050 mierzyłoby to samo dwa razy.

## Powiązane ADR-y

- [ADR-029 — Pamięć odczytów dostępności na jedno żądanie](../../adr/ADR-029-pamiec-odczytow-dostepnosci-na-zadanie.md)
- [ADR-015](../../adr/ADR-015-warstwa-oferty-pobytu.md) — warstwa oferty (granica: warstwa oferty nie liczy niczego własnego)
