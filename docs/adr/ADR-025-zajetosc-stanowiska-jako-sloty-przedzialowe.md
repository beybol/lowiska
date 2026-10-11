# ADR-025 — Zajętość stanowiska jako sloty czasowe (przedziały)

- **Status:** accepted
- **Data:** 2026-10-08
- **Zadanie:** [039 — Rdzeń rezerwacji](../tasks/implemented/039-rdzen-rezerwacji.md)

## Kontekst

Etap 3 wprowadza rezerwacje, więc `PositionAvailability` ([ADR-012](ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md))
musi odpowiadać także na pytanie „czy stanowisko jest w tym czasie zajęte". Etap 4 doda drugi kanał
(zamówienie wędkarza z blokadą czasową), więc zapis zajętości musi mieć jedną ścieżkę z blokadą zasobu
(G10, Z3).

Sprzedawalną jednostką jest dziś **doba wędkarska** ([ADR-010](ADR-010-doba-wedkarska-jako-przedzial-czasu.md)):
przedział dwóch momentów, nie data. Autor zakłada, że w przyszłości pojawią się inne jednostki —
np. „dzień" 6:00–20:00 albo dzień dzielony na dwa sloty (przed i po południu) — i że koszt późniejszej
refaktoryzacji zajętości byłby duży. Zajętość musi więc być **neutralna wobec długości i rodzaju
sprzedawanej jednostki**, mimo że dziś istnieje jedna.

Wiążące założenia produktowe (08.10.2026): jeden slot = jedna możliwa rezerwacja; jedna rezerwacja =
jedno stanowisko; dane rezerwacji nie zmieniają się niejawnie przy zmianie konfiguracji.

Dlaczego ADR: **zasięg** — każde miejsce pytające o dostępność, kalendarz operacyjny, publiczna
dostępność, zakup (etap 4); **koszt odwrócenia** — zmiana modelu zajętości po starcie produkcyjnym to
migracja wszystkich rezerwacji; **uzasadnienie** — „dlaczego nie klucz `(stanowisko, data)`" nie wynika
z kodu.

## Alternatywy

### Opcja A — zmaterializowane doby: `unique(position_id, night_date)`
**Zalety:**
- Gwarancja unikalności w bazie, prosty zapis.

**Wady:**
- Zakłada siatkę dobową o kluczu daty. Dzień 6–20 i doba 15–15 tego samego dnia nie kolidują
  w kluczu, choć kolidują w czasie; półdniowych slotów nie da się wyrazić.
- Dodanie innej jednostki wymaga zmiany schematu i migracji danych.

### Opcja B — sloty jako przedziały `[starts_at, ends_at)` z typem *(wybrana)*
**Zalety:**
- Neutralna wobec jednostki: nowy rodzaj slotu to nowa wartość `slot_type`, bez zmiany schematu.
- Jedna reguła kolizji (nakładanie przedziałów, ta sama co dla blokad), niezależna od typów.

**Wady:**
- MySQL nie ma ograniczenia wykluczającego, więc brak nakładania zapewnia blokada wiersza
  stanowiska i ponowne sprawdzenie w transakcji, a nie baza. Gwarancja leży w kodzie i musi być
  pilnowana testem.

### Opcja C — ziarno atomowe (najmniejsza jednostka, każda sprzedaż rozwijana na atomy)
**Zalety:**
- Unikalność `(position_id, atom)` w bazie.

**Wady:**
- Atomy muszą pasować do wszystkich granic używanych przez łowisko; zmiana schematu slotów
  wymaga przeliczenia istniejących rezerwacji. Przenosi złożoność z zapisu do migracji.

## Rekomendacja

Opcja B. Cena (gwarancja w kodzie, nie w bazie) jest mniejsza niż koszt zmiany schematu przy
pierwszej nowej jednostce sprzedaży.

## Decyzja

Decyzja: B — uzgodniona z autorem 08.10.2026, z akceptacją braku wsparcia przedziałów po stronie bazy.
Ustalenia wiążące:

1. **Pojęcia.** *Slot* to okno czasu zajęte na stanowisku przez rezerwację, `[starts_at, ends_at)`
   w UTC. *Doba wędkarska* jest typem slotu (`slot_type = fishing_day`, dziś jedyna wartość enuma).
   *Pobyt* to ciąg 1..n slotów jednej rezerwacji ([ADR-013](ADR-013-warstwa-regul-pobytu-i-spoiwo-dob.md)).
2. **Tabela `reservation_slots`:** `reservation_id`, `position_id`, `slot_type`, `starts_at`, `ends_at`.
   Przedział slotu jest zamrożony w rezerwacji i nie zmienia się po zmianie konfiguracji.
3. **Kolizja:** dwa sloty tego samego stanowiska nie mogą się nakładać niezależnie od typu; stosujemy
   tę samą semantykę nakładania co dla blokad (`overlaps`, `dostepnosc.md` §1), nie zawierania.
   Dodatkowa siatka bezpieczeństwa: `unique(position_id, starts_at)`.
4. **Jedna ścieżka zapisu (G10).** Wszystkie zapisy do `reservation_slots` — rezerwacja operatora,
   admina, a w etapie 4 zamówienie wędkarza — idą jedną klasą, w transakcji, z blokadą wiersza
   stanowiska (`lockForUpdate`) i ponownym zapytaniem do `PositionAvailability`. Zapis poza tą klasą
   jest defektem; pilnuje tego test. Konflikt jest odmową z powodem `SaleUnavailabilityReason::Occupied`,
   nie błędem.
5. **Miejsce w łańcuchu powodów** (ADR-012): stan stanowiska → okres sprzedaży → blokada → **zajętość** →
   zawieszenia cech (niebędące odmową). Zajętość jest przyczyną najbardziej przemijającą.
6. **Zwolnienie zasobu** (anulowanie, wygaśnięcie `confirm_by`, w etapie 4 także koniec blokady czasowej)
   **usuwa** wiersze slotów; historia zostaje w `reservations`.
7. **Usługi dodatkowe z limitem egzemplarzy** nie mają gwarancji unikalności, tylko sprawdzenie sumy
   względem limitu w tej samej transakcji, pod blokadą wiersza usługi; osobna tabela zajętości usług
   (nazwa i kształt w zadaniu).
8. **Pojemność stanowiska** (maks. wędkujących i osób łącznie) jest walidacją składu rezerwacji w tej
   samej ścieżce zapisu, nie zajętością.
9. **Punkt rozszerzenia, poza zakresem tego ADR:** sprzedawalność i cennik nadal mówią „doba"
   (`FishingDayCalendar`, okresy sprzedaży, reguły pobytu, `StayPricing`). Sprzedaż dnia lub półdnia
   wymaga osobnej decyzji o „schemacie slotów łowiska" uogólniającym te klasy. Istniejących klas doby
   **nie przemianowujemy** — nazwa jest w obecnej sprzedaży poprawna; neutralne nazwy dotyczą nowej
   warstwy zajętości.
10. **Zmiana konfiguracji a istniejące rezerwacje** (np. godzin doby) nigdy nie zmienia ich niejawnie:
    wyliczamy dotknięte przyszłe rezerwacje i pytamy operatora, czy je zaktualizować, z podglądem
    kolizji nowych przedziałów z blokadami i innymi rezerwacjami. Szczegóły w zadaniu.
11. **Zakres rezerwacji etapu 3:** jedna rezerwacja = jedno stanowisko; jeden slot na stanowisku =
    jedna rezerwacja (stanowisko nie jest współdzielone przez niezależne rezerwacje).
