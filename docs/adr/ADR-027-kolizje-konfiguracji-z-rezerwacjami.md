# ADR-027 — Kolizje zmian konfiguracji z istniejącymi rezerwacjami (G8)

- **Status:** accepted
- **Data:** 2026-10-08
- **Zadanie:** [044 — Kolizje konfiguracji z rezerwacjami](../tasks/implemented/044-kolizje-konfiguracji-z-rezerwacjami.md)

## Kontekst

G8 ([ZASADY-PROJEKTOWE](../project/ZASADY-PROJEKTOWE.md)): blokada nie narusza sprzedanej rezerwacji;
kolizja wymaga jawnej decyzji i pełnego zwrotu. Dziś `AvailabilityBlock` nie zna rezerwacji. Po etapie 3
istnieją rezerwacje ([ADR-025](ADR-025-zajetosc-stanowiska-jako-sloty-przedzialowe.md),
[ADR-026](ADR-026-zamrozenie-warunkow-rezerwacji.md)), więc blokada, wycofanie stanowiska, zmiana
pojemności, zmiana godzin doby i skrócenie okresu sprzedaży mogą kolidować z rezerwacjami, które już
istnieją. Bez wspólnego mechanizmu każda z tych zmian dostałaby własną wersję tej logiki.

Wiążące założenia produktowe (08.10.2026): w etapie 3 nie ma płatności online — wędkarz płaci na łowisku,
więc anulowana rezerwacja nie ma czego zwracać; pobyty trwające nie podlegają żadnej zmianie.

Dlaczego ADR: **zasięg** — panele admina i właściciela, każda zmiana konfiguracji dotykająca
rezerwacji, etap 4; **koszt odwrócenia** — rozproszona logika kolizji; **uzasadnienie** — dlaczego
„przenieś" nie jest decyzją, a rezerwacja na zablokowanym stanowisku jest dozwolona, nie wynika z kodu.

## Alternatywy

### Opcja A — osobna logika kolizji w każdym miejscu zmiany konfiguracji
**Zalety:** brak abstrakcji z góry.
**Wady:** pięć wersji tej samej reguły, rozjazd nie zapala się nigdzie (to, przed czym ostrzegają
ADR-012 i `CLAUDE.md`).

### Opcja B — jedna usługa kolizji konfiguracji i jeden ekran decyzji *(wybrana)*
**Zalety:** jedno miejsce wyliczające dotknięte rezerwacje, jedna reguła „nie ruszamy trwających",
jeden dziennik decyzji.
**Wady:** zestaw decyzji zależy od rodzaju zmiany, więc usługa musi znać rodzaj zmiany.

### Opcja C — blokada anuluje rezerwacje automatycznie
**Zalety:** najprostsze.
**Wady:** łamie G8 (brak jawnej decyzji) i nie pozwala zostawić rezerwacji, np. zawodników.

## Rekomendacja

Opcja B.

## Decyzja

Decyzja: B — uzgodniona z autorem 08.10.2026. Ustalenia wiążące:

1. **Jedna usługa kolizji konfiguracji** (nazwa i kształt w zadaniu). Dla opisu zmiany (rodzaj +
   parametry) zwraca dotknięte rezerwacje; wołają ją wyłącznie serwisy zmian konfiguracji, nigdy zasób
   Filamenta. Zakres w etapie 3: **blokada sprzedaży** i **wycofanie stanowiska**. Zmiana pojemności,
   godzin doby i skrócenie okresu sprzedaży używają tego samego mechanizmu (zestaw decyzji ustala
   zadanie; dla godzin doby zgodnie z ADR-025 pkt 10 jest to „zaktualizuj przedziały" albo „zostaw").
2. **Dotknięta rezerwacja** to rezerwacja, której pobyt jeszcze się nie rozpoczął i nakłada się na zmianę
   (semantyka `overlaps`; okno blokady w dobach — ADR-010, Aktualizacja 11.10.2026). **Pobytów trwających nie dotykamy w ogóle** — nie trafiają na listę kolizji,
   a żadna zmiana konfiguracji ich nie zmienia.
3. **Decyzje przy blokadzie i wycofaniu stanowiska — dwie:** *anuluj z pełnym zwrotem* albo *zostaw*.
   Nie ma „przenieś na inne stanowisko". Nie ma anulacji bez zwrotu (G8). Zapis zmiany i wszystkie
   decyzje to jedna transakcja; operator widzi podgląd (wędkarz, termin, stanowisko, status płatności),
   decyduje dla każdej rezerwacji z osobna albo zbiorczo („zastosuj do wszystkich").
4. **Anulacja z komunikatem.** Przy decyzji „anuluj" operator może wpisać komunikat tłumaczący powód.
   Opcja jest dostępna przy decyzji indywidualnej i zbiorczej; komunikat trafia do maila o anulacji
   wysyłanego do wędkarza. Rezerwacja bez adresu e-mail (sam telefon, ADR-024) nie dostaje maila i trafia
   na listę „do kontaktu" operatora. Komunikat i fakt anulacji zapisują się w rezerwacji (powód, kto,
   kiedy) oraz w dzienniku zmian (kanał `reservations`, ADR-026).
5. **Zwrot w etapie 3 to status.** Płatności online nie ma, wędkarz płaci na łowisku, więc anulacja przez
   łowisko ustawia status płatności „zwrot" bez rejestru „do zwrotu" i bez wykonania zwrotu w systemie.
   Faktyczne zwroty (płatność online, D7) to etap 4.
6. **Anulacja zwalnia sloty** (usuwa wiersze `reservation_slots`, ADR-025 pkt 6); rezerwacja pozostaje
   w historii.
7. **Rezerwacja na zablokowanym stanowisku jest dozwolona w kanale operatora/admina**, z jawnym
   ostrzeżeniem w interfejsie (np. przydział stanowisk zawodnikom w dniu zamknięcia łowiska). Wyjątek
   zapisuje się w `reservation_terms` jak inne przekroczone reguły (ADR-026 pkt 8). Dotyczy wyłącznie
   blokady sprzedaży; **kolizja z zajętym slotem pozostaje niemożliwa** (ADR-025), a kanał wędkarza
   (etap 4) nigdy nie omija blokady. Punkt ten domyka zastrzeżenie z ADR-026 pkt 8.
8. **Zawieszenie cechy** (`attribute_suspended`) nie odbiera stanowiska ze sprzedaży i nie wymusza
   decyzji; usługa pokazuje wyłącznie informację o rezerwacjach, których dotyczy.
9. **Zamówienia z blokadą czasową etapu 4** (utrzymanie zasobu na czas płatności) traktujemy jak
   rezerwacje: nowa blokada je zwalnia; szczegóły w specyfikacji etapu 4.
10. **Do prawnika (TODO-3 pkt 4):** klauzula „zamknięcie łowiska bez rekompensaty" jest sprzeczna z
    mechanizmem (pełny zwrot albo rezerwacja zostaje); system nie ma ścieżki „bez zwrotu".

## Aktualizacja (2026-10-10)

Zakres pkt 2 („pobytów trwających nie dotykamy") dotyczy **automatycznych kolizji konfiguracji** (blokada,
wycofanie stanowiska) — te nigdy nie obejmują pobytów w toku. **Ręczna anulacja trwającej rezerwacji przez
operatora jest możliwa**, ale poprzedzona dodatkowym pytaniem: „Ten pobyt już trwa. Czy na pewno chcesz
anulować trwającą obecnie rezerwację?". W etapie 3 anulacja zmienia status rezerwacji i zwalnia sloty
(ADR-025); zasady zwrotu za niewykorzystane doby ustalamy przy płatnościach (etap 4) — tam wracamy do tego
punktu głębiej.
