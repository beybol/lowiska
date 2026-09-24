# 024 — Jeden dom strefy czasowej łowiska

> **Pochodzenie:** uwaga nr 5 z przeglądu wg frameworka pakietu 017–021 (`/review-implementation`,
> 24.09.2026, zakres `origin/dev..HEAD`: 023, 020, 021).

## Opis problemu

Wszystkie wyliczenia sprzedaży biegną w **strefie czasowej łowiska** (`fisheries.timezone`,
`dostepnosc.md` §1). Strefa domyślna — `'Europe/Warsaw'` — jest jednak wpisana jako fallback
**w każdej klasie osobno**, zwykle w postaci `$fishery->timezone ?: 'Europe/Warsaw'`.

Stan na 24.09.2026 (`grep -rn "Europe/Warsaw" app`): fallback w 11 plikach, 13 wystąpień:

| Plik | Wystąpienia |
|---|---|
| `app/Services/FishingDayCalendar.php` | 1 (`timezone()`) |
| `app/Services/PositionAvailability.php` | 1 (`blocksIntersecting()`) |
| `app/Services/StaySellability.php` | 1 (`timezone()`) |
| `app/Services/SalePeriodFinder.php` | 1 (`timezone()`) |
| `app/Services/SaleCalendar.php` | 1 (`timezone()`) |
| `app/Services/PricingConfigurationAudit.php` | 2 |
| `app/Services/RefundPolicy.php` | 1 |
| `app/Services/FisheryDocuments.php` | 2 (`timezoneOf()` i gałąź bez łowiska w `isLocked()`) |
| `app/Rules/DocumentEffectiveDateIsAhead.php` | 1 |
| `app/Filament/Resources/FisheryResource/Pages/ManageCalendar.php` | 2 |

`CLAUDE.md` mówi wprost: **drugi literał tej samej stałej to defekt**. Ryzyko jest ciche: zmiana
strefy domyślnej (albo reguły fallbacku) wymaga poprawienia wszystkich miejsc, a pominięte liczy
doby, horyzont, okna blokad albo daty dokumentów w innej strefie niż reszta — bez żadnego sygnału.

Trzy dalsze wystąpienia są **wartością domyślną**, nie fallbackiem, i mają inny charakter:
`Fishery::$attributes` (lustro wartości domyślnej kolumny dla świeżo utworzonego modelu),
`ManageSaleSettings` (`->default()` pola formularza) i migracja `2026_09_20_100000_*`
(`->default('Europe/Warsaw')` kolumny).

Kolumna `timezone` jest **NOT NULL z wartością domyślną** — fallback łapie więc dziś wyłącznie pusty
łańcuch i model niezapisany, a nie brak wartości w bazie.

## Wymagania

- **Jedna metoda na modelu `Fishery` — `timezoneName()`** — zwraca strefę łowiska z fallbackiem
  (nie akcesor `timezone`, bo to nazwa kolumny i zmieniłby znaczenie odczytu atrybutu).
- **Strefa domyślna ma jeden dom** — stała (np. `Fishery::DEFAULT_TIMEZONE`), z której korzystają
  także miejsca „wartości domyślnej": `Fishery::$attributes` i `ManageSaleSettings`. Migracji
  historycznej **nie ruszamy** (patrz „Zakres wyłączeń").
- Wszystkie 13 fallbacków z tabeli wyżej przechodzą na tę metodę. **Metody `timezone()` w usługach
  znikają** — wołający sięgają po `$fishery->timezoneName()` wprost:
  - prywatne `timezone()` w `FishingDayCalendar`, `StaySellability`, `SaleCalendar`,
    `PricingConfigurationAudit` oraz `FisheryDocuments::timezoneOf()` — usunięte;
  - publiczne `SalePeriodFinder::timezone()` — usunięte razem z jedynym wołającym spoza klasy
    (`StayPricing.php`, dziś `$this->periods()->timezone()`), który przechodzi na łowisko.
- `FisheryDocuments::isLocked()` — gałąź „wersja bez łowiska" też korzysta ze stałej zamiast literału.
- Zachowanie **bez zmian**: łowisko ze strefą ustawioną liczy się tak jak dziś, łowisko z pustą
  strefą — tak jak dziś w `'Europe/Warsaw'`.

## Kryteria akceptacji

- [ ] `grep -rn "Europe/Warsaw" app` zwraca wyłącznie jeden dom stałej (i ewentualnie docblock).
- [ ] Test metody na modelu: strefa ustawiona → ta strefa; pusty łańcuch → strefa domyślna; model
      niezapisany bez strefy → strefa domyślna.
- [ ] Testy klas korzystających ze strefy łowiska zielone bez zmian w asercjach (zakres niżej).
- [ ] Pint i PHPStan czyste; baseline bez nowych wpisów.
- [ ] Zielony zakres T2 zadeklarowany niżej. **Pełny pakiet (T3) odroczony** na koniec sesji
      (`/review-implementation`, Krok 1).

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="FishingDayTest|PositionAvailabilityTest|StaySellabilityTest|WeekendBundleTest|WholeTermPeriodTest|SalePeriodTest|StayPricingTest|StayOfferTest|PricingConfigurationAuditTest|PricingPageTest|SaleCalendarTest|SaleCalendarPageTest|SaleSettingsPageTest|FisheryDocumentsTest|RefundPolicyTest"` + nowy test metody strefy na modelu (nazwę ustala implementacja; dopisać do filtra)
- **Uzasadnienie:** zmiana rusza kontrakt używany na ścieżce prawie każdej reguły sprzedaży (doby,
  dostępność, pobyt, okresy, cennik, oferta, kalendarz, dokumenty, zwroty), więc T1 nie wystarcza.
  Żaden wyzwalacz T3 nie jest dotknięty: bez migracji, bez `User`, polityk, providerów paneli
  i konfiguracji testów. Tier zatwierdzony przez autora przy `/create-task` (24.09.2026).

## Zakres wyłączeń

- **Migracja `2026_09_20_100000_add_sale_settings_to_fisheries_table.php`** — historyczna, jej
  `->default('Europe/Warsaw')` zostaje literałem (migracja nie powinna zależeć od kodu aplikacji,
  którego znaczenie może się zmienić).
- **Zmiana samej strefy domyślnej** albo reguły wyboru strefy — zadanie niczego nie zmienia
  w zachowaniu.
- **`config('app.timezone')`** — strefa aplikacji świadomie nie bierze udziału w wyliczeniach
  sprzedaży (`dostepnosc.md` §1); zadanie jej nie dotyka.
- **Obejście nienaruszalności wersji dokumentu przez zmianę strefy łowiska** — ryzyko świadomie
  zaakceptowane przez autora przy przeglądzie pakietu 017–021 (24.09.2026); to zadanie go nie
  zamyka.

## Zmiany dokumentacji

- [ ] `docs/conventions/dostepnosc.md` §1 — przy regule „wyliczenia biegną w strefie ŁOWISKA":
      strefę czyta się wyłącznie przez metodę modelu `Fishery`; drugi fallback w klasie to defekt.
- [ ] `CHANGELOG.md` — wpis w sekcji **Zmienione** przez skill `changelog` (decyzja autora mimo
      braku widocznego skutku — patrz „Rozstrzygnięcia").

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4.
- Nazwa metody nie może kolidować z kolumną `timezone` (akcesor o tej samej nazwie zmieniłby
  odczyt `$fishery->timezone` w całym kodzie i w formularzu „Sprzedaż i sezony").
- Powierzchnie do przeczytania przed edycją: `dostepnosc.md`, `cennik.md`, `panel-wlasciciela.md`.

## Rozstrzygnięcia

Ustalone przy `/review-task`, 24.09.2026:

- **Nazwa metody: `Fishery::timezoneName()`.** Nie koliduje z kolumną `timezone`, a czyta się jak
  nazwa strefy przekazywana Carbonowi.
- **Metody `timezone()` w usługach usunięte, wołający idą do modelu.** Jedna ścieżka zamiast warstwy
  pośredniej; publiczne `SalePeriodFinder::timezone()` znika razem z wywołaniem w `StayPricing`.
- **Wpis w `CHANGELOG.md` w sekcji „Zmienione"** (decyzja autora), mimo że zmiana nie ma skutku
  widocznego dla użytkownika.
- **Bez ADR-a.** Decyzja jest lokalna i odwracalna jedną podmianą wywołań — nie spełnia warunku
  kosztu odwrócenia.

## Powiązane ADR-y

- brak
