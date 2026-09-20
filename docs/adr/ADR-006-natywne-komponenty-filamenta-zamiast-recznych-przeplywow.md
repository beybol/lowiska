# ADR-006 — Natywne komponenty Filamenta zamiast ręcznych przepływów; hub podrzędnych zasobów zamiast RelationManagerów

- **Status:** accepted
- **Data:** 2026-08-16
- **Zadanie:** [012 — Poprawki panelu właściciela po upgrade do Laravel 13](../tasks/implemented/012-poprawki-panelu-wlasciciela-po-laravel-13.md)

## Kontekst

Panel właściciela ma dwa miejsca zbudowane **ręcznie**, zamiast na komponentach Filamenta:

1. **Kreator zakładania łowiska** — trzy osobne strony (`CreateCompany`, customowy widok
   `verify-company.blade.php`, `CreateFishery`) sklejone flagą `?wizard=1` i przekazywaniem
   stanu przez parametry URL. Krok wyboru firmy renderuje gołego `<select wire:model>`.
   Istnieje `Helper::isWizard()` wyłącznie po to, żeby `FisheryResource::form()` wiedział, że
   jest renderowany z tego przepływu (i ukrył pole `company_id`).
2. **Strona „Zarządzaj łowiskiem"** — `ViewRecord` z customowym widokiem, gdzie zakładki
   przełącza ręczny Alpine.js (`x-data="{activeTab:...}"`), a przyciski to surowe `<svg>`
   z ręcznie wpisanymi klasami Tailwind (`<x-icons.plus>`, `<x-icons.view>`).

Upgrade do Laravel 13 / Filament 5 (zadanie 009) rozsypał oba miejsca: niestylowany select,
gigantyczne ikony, rozsypana nawigacja zakładek, martwy listener `DOMContentLoaded` po
nawigacji Livewire. **Te same pliki wymagały ręcznej interwencji już przy poprzednim
upgradzie** — to nie jednorazowy pech, tylko powtarzalny koszt wynikający z omijania
frameworka.

Dodatkowe ustalenie z przeglądu zadania: **`viteTheme()` nie jest zarejestrowane w żadnym
z paneli**, więc surowe klasy Tailwind użyte wewnątrz stron Filamenta nie mają skąd wziąć
CSS-u — ręczne komponenty nie mają dziś nawet działającego zaplecza stylów.

Decyzja dotyczy **wzorca na przyszłość**, nie tylko naprawy tych dwóch ekranów: każdy kolejny
kreator wieloetapowy i każda kolejna strona zarządzania zasobami podrzędnymi w obu panelach
odziedziczy ten wybór albo będzie od niego świadomie odchodzić.

⚠️ Osobny, ale nierozłączny wątek: strona „Zarządzaj łowiskiem" ma pozostać **hubem/submenu**
(trzy zakładki z licznikami i linkami do list/tworzenia), **bez** formularza edycji łowiska.
To jest odejście od domyślnego wzorca Filamenta („RelationManagery osadzone na stronie edycji
rekordu nadrzędnego"), które trzeba udokumentować — inaczej za rok ktoś „naprawi" to z powrotem
do domyślnego wzorca, nie wiedząc, że nietypowy kształt jest celowy.

## Alternatywy

### Opcja A — Natywne komponenty Filamenta (`Wizard`/`Step`, `Tabs`, `Action`), hub bez formularza edycji

**Zalety:**
- Stan kreatora trzyma `Wizard`, nie parametry URL — znika cała klasa błędów „pęknięcie
  w łańcuchu URL cicho gubi firmę" oraz potrzeba `Helper::isWizard()`.
- Rozmiar i styl przycisków/ikon pilnuje framework (`Action::make()->icon(...)`), nie ręcznie
  wpisane klasy Tailwind — odporne na kolejne upgrade'y Filamenta.
- Znika potrzeba budowania własnego motywu CSS dla tych ekranów; natywne komponenty korzystają
  z prebuildowanego CSS-u Filamenta, który i tak jest ładowany.
- Zachowuje świadomy kształt produktowy (hub bez formularza edycji) — zmienia wyłącznie warstwę
  prezentacji, nie zachowanie.

**Wady:**
- Jednorazowo duży refaktor: dwa strukturalne przepisania plus przepisanie ośmiu testów
  asertujących URL-owy przepływ.
- Hub bez formularza edycji nadal jest odejściem od domyślnego wzorca Filamenta — trzeba to
  udokumentować, bo samo użycie natywnych `Tabs` tego nie tłumaczy.
- `Wizard` w Filamencie trzyma stan w jednym formularzu; trzeba sprawdzić, czy pobranie danych
  z GUS (`Helper::fetchDataFromCSO()`) i walidacje per krok przenoszą się bez utraty zachowania.

### Opcja B — Zostawić ręczne przepływy, dołożyć własny motyw CSS (`viteTheme`)

**Zalety:**
- Znacznie mniejszy zakres: rejestracja motywu + poprawki klas Tailwind, bez ruszania logiki.
- Zero ryzyka regresji w logice biznesowej kreatora — testy zostają bez zmian.
- Szybkie domknięcie widocznych objawów (ikony, select, zakładki).

**Wady:**
- Nie usuwa przyczyny: stan kreatora dalej wisi na parametrach URL, `Helper::isWizard()`
  zostaje, `DOMContentLoaded` dalej wymaga ręcznego obchodzenia przy każdej zmianie Livewire.
- **Ten sam koszt wróci przy następnym upgradzie Filamenta** — dokładnie tak, jak wrócił teraz.
- Trzeba zbudować i utrzymywać własny motyw CSS panelu od zera (dziś nie istnieje w żadnym
  panelu), czyli nowa powierzchnia do pilnowania przy każdej zmianie wersji Tailwinda/Filamenta.

### Opcja C — Natywne komponenty + domyślny wzorzec Filamenta (RelationManagery na stronie edycji)

**Zalety:**
- Pełna zgodność z domyślnym wzorcem frameworka — najmniej niespodzianek dla kogoś, kto zna
  Filamenta, zero potrzeby dokumentowania odstępstwa.
- RelationManagery dają CRUD podrzędnych zasobów „za darmo", bez własnych linków do list.

**Wady:**
- **Zmienia zachowanie produktu**: strona zarządzania staje się formularzem edycji łowiska
  z zakładkami pod spodem, zamiast szybkiego huba/submenu. Autor zadania wyklucza to wprost.
- Podrzędne zasoby mają dziś własne strony list i tworzenia (z filtrem `?fishery=`) —
  przejście na RelationManagery albo je duplikuje, albo wymusza ich usunięcie (dużo szerszy
  refaktor niż zakres zadania 012).

## Rekomendacja

**Opcja A.**

Przesądza powtarzalność kosztu: te same pliki wymagały ręcznej naprawy przy dwóch kolejnych
upgrade'ach, a Opcja B ten cykl utrwala — dokłada jeszcze własny motyw CSS jako nową
powierzchnię do utrzymania, żeby ręczne komponenty w ogóle miały skąd wziąć style. Opcja A
usuwa potrzebę motywu dla tych ekranów, bo natywne komponenty korzystają z CSS-u, który panel
i tak ładuje.

Opcja C odpada nie z powodów technicznych, tylko produktowych — zmieniłaby zachowanie, które
ma zostać. Dlatego rekomendacja obejmuje **oba** elementy razem: natywne komponenty **i**
utrzymanie huba bez formularza edycji. To drugie jest świadomym odejściem od domyślnego wzorca
Filamenta i właśnie dlatego wymaga zapisu w ADR-ze — żeby przyszły czytelnik `ManageFishery`
nie „naprawił" go z powrotem do wzorca z dokumentacji frameworka.

⚠️ Rekomendacja nie przesądza, czy `viteTheme()` będzie potrzebne — jeśli po refaktorze
zostaną elementy poza natywnymi komponentami, motyw można dołożyć; zadanie 012 traktuje to
warunkowo.

## Decyzja
Decyzja: A

Uzasadnienie: musimy rozwiązać koszt custom development na przyszłość i być jak najbliżej frameworka. Własne rozwiązanie tupu hub pozwala na potrzebną customizację ale udokumentowane i wpięte w framework powinno przetrwać kolejne upgradey.

## Aktualizacja (zadanie 012, 2026-08-16)

**Zakładki huba budują RelationManagery, nie własne `Tabs` z linkami.** To częściowe
przejęcie odrzuconej Opcji C — ale wyłącznie w warstwie technicznej, nie produktowej.

Powód zmiany: w pierwszej implementacji zakładka pokazywała opis zasobu i dwa przyciski
(„Utwórz", „Lista"), więc lista była schowana o jedno kliknięcie dalej niż to, po co się na
tę stronę wchodzi. Wyświetlenie listy wprost w zakładce oznacza tabelę w zakładce, a tabelę
podrzędnego zasobu w Filamencie renderuje RelationManager — pisanie drugiej takiej tabeli
ręcznie byłoby dokładnie tym kosztem, który ten ADR miał usunąć.

Co **nie** uległo zmianie i nadal wiąże:

- **Hub nie jest formularzem edycji łowiska.** Pierwsza zakładka („Dane łowiska") to podgląd
  (`infolist`) z przyciskiem „Edytuj" prowadzącym do osobnego formularza. `ManageFishery`
  zostaje `ViewRecord`; zamiana na `EditRecord` odwróciłaby decyzję A.
- **Zasoby podrzędne zachowują własne strony list i tworzenia** (`?fishery=`). Tabele
  RelationManagerów delegują do tych samych zasobów, a przycisk dodawania linkuje na pełną
  stronę tworzenia zamiast otwierać modal — nic się nie duplikuje ani nie znika, co było
  główną wadą Opcji C.
- Kolejność zakładek stawia dane łowiska jako pierwsze, żeby wejście w zarządzanie nie
  wrzucało od razu w jedną z trzech list — mechanizmem
  `hasCombinedRelationManagerTabsWithContent()`, wbudowanym w Filamenta.

## Aktualizacja (zadanie 015, 2026-09-17)

**Zakładka konfiguracyjna huba nie jest RelationManagerem — jest stroną ustawień.** Reguła
z aktualizacji zadania 012 („zakładki huba budują RelationManagery") dotyczy zakładek pokazujących
**listę rekordów podrzędnych** i w tym zakresie obowiązuje bez zmian. Pakiet 014–021 dokłada drugi
rodzaj zakładki, którego wtedy nie było.

Powód rozszerzenia: „Sprzedaż i sezony" trzyma **konfigurację jednego łowiska**, nie listę jego
rekordów podrzędnych — tryb sprzedaży, godziny doby i okresy sprzedaży w jednym formularzu z jednym
zapisem. Wtłoczone w RelationManager rozpadłoby się na dwa miejsca: pola doby wylądowałyby
w formularzu edycji łowiska, a okresy w osobnej zakładce — mimo że operator nie potrafi ustawić
jednego bez drugiego. Makieta zapowiada **pięć kolejnych** zakładek tego rodzaju (Cennik, Reguły
sprzedaży, Usługi dodatkowe w części konfiguracyjnej, Zwroty, Regulamin), więc rozstrzygnięcie zapada
raz, tutaj, a nie pięć razy przy okazji.

Co wiąże przyszłe zakładki konfiguracyjne:

- **Strona ustawień jest stroną zasobu `FisheryResource`** (`getPages()`), nie stroną panelu.
  Zasób jest już zarejestrowany w obu panelach, więc strona nie wymaga dotykania
  `AdminPanelProvider` ani `OwnerPanelProvider` — a to są pozycje z listy wyzwalaczy T3.
- **Rekordy podrzędne bez własnego życia renderuje `Repeater`, nie osobny zasób CRUD.** Okres
  sprzedaży ma dwie daty i nazwę, nie ma własnego ekranu, na który ktokolwiek wchodzi — trzy strony
  CRUD dla takiego bytu są kosztem bez pokrycia. Zasób osobny należy się rekordowi, do którego
  prowadzi deep-link albo który ma własne akcje.
- **Okruszki i powrót po zapisie idą przez `Helper::fisheryBreadcrumbs()` i `Helper::fisheryHubUrl()`**,
  tak samo jak strony list i tworzenia zasobów podrzędnych. Strona ustawień nie buduje tych tablic
  ręcznie.
- **Autoryzacja jest jawna.** Strona nie dziedziczy sprawdzeń z RelationManagera, więc pyta politykę
  wprost (`Gate::allows('update', $fishery)`) i zawęża widoczność przez
  `Helper::scopeToOwnedFisheries()`.

⚠️ Granica między dwoma rodzajami zakładki: **lista rekordów, które mają własne strony → RelationManager;
konfiguracja łowiska zapisywana jednym „Zapisz" → strona ustawień.** Stanowiska, usługi dodatkowe
i pozwolenia zostają po pierwszej stronie tej granicy i nic się dla nich nie zmienia.

## Aktualizacja (zadanie 016, 2026-09-20) — sub-nawigacja rekordu zamiast huba z zakładkami

**Hub z zakładkami RelationManagerów zostaje zastąpiony natywną SUB-NAWIGACJĄ REKORDU.**
Ta aktualizacja **unieważnia** regułę „zakładki huba budują RelationManagery" z aktualizacji
zadania 012 oraz podział „lista → RelationManager, konfiguracja → strona ustawień" z aktualizacji
zadania 015. Obie były poprawne wobec kształtu, który wtedy istniał; poniższa je zastępuje.

Powód zmiany jest empiryczny i ujawnił się dopiero przy trzecim ekranie. Pasek zakładek huba buduje
`HasRelationManagers::getRelationManagersContentComponent()`, a zawartość każdej zakładki powstaje
jako `Livewire::make($relationManagerClass, …)` — **osadzony komponent, nie trasa**. Ekran
konfiguracyjny („Sprzedaż i sezony") jest stroną z własnym adresem, okruszkami i przyciskiem
„Zapisz", więc do tego paska wstawić się nie da. Dostał więc akcję nagłówka obok „Edytuj" — i tu
wyszedł problem: makieta zapowiada **pięć kolejnych** ekranów konfiguracyjnych (Cennik, Reguły
sprzedaży, Zwroty, Regulamin, konfiguracyjna część Usług). Sześć przycisków w nagłówku nie jest
stanem docelowym, a makieta od początku pokazuje wszystkie ekrany w **jednej** nawigacji, bez
rozróżnienia na listy i ustawienia.

Rozróżnienie, które wprowadziła aktualizacja 015, było więc artefaktem ograniczenia frameworka,
a nie właściwością produktu — i to jest dokładnie ten rodzaj kompromisu, któremu ten ADR ma
zapobiegać.

**Co obowiązuje od teraz:**

- **Ekrany jednego łowiska są STRONAMI zasobu `FisheryResource`**, wypisanymi w
  `getRecordSubNavigation()`, z pozycją z `getSubNavigationPosition()`. Nawigacja jest jedna
  i obejmuje **zarówno listy, jak i ustawienia** — bez rozróżnienia widocznego dla operatora.
- **Listę rekordów podrzędnych renderuje `ManageRelatedRecords`** (strona rekordu z `$relationship`),
  nie `RelationManager`. Tabela i formularz nadal **DELEGUJĄ** do właściwego zasobu, więc definicja
  kolumn i pól zostaje w jednym miejscu.
- **Liczniki przetrwały**: pozycje sub-nawigacji to `NavigationItem` budowane w
  `Page::getNavigationItems()` z `->badge(static::getNavigationBadge(), …)`, więc plakietka jest
  metodą strony, nie ręcznym znacznikiem.
- **Adresy składa się po KLASIE STRONY**, przez `Page::getRouteName()`. ⚠️ Znika parametr
  `?relation=N` i razem z nim cała pułapka „przestawienie kolejności zakładek przekierowuje na
  cudzą listę i nic nie pęka". To jest samodzielna korzyść tej zmiany.
- **`ManageFishery` zostaje `ViewRecord` z samym podglądem danych łowiska** — pierwsza pozycja
  sub-nawigacji. Nadal **NIE jest formularzem edycji**; ta część decyzji A jest nietknięta.

**Co przestaje obowiązywać:** `FisheryResource::getRelations()` jest puste, RelationManagery
łowiska nie istnieją, a akcje w listach nie muszą już omijać `RelationManager::isReadOnly()`.

⚠️ **Akcje wiersza i nagłówka NADAL prowadzą na pełne strony tworzenia i edycji**, zwykłą `Action`
z `url()`, choć na `ManageRelatedRecords` działałyby też akcje CRUD-owe z modalami. Powód zmienił
się z technicznego na świadomy: pełne strony niosą bramkę `Helper::assertFisheryAccessOrAbort()`,
`Helper::forceVerifiedFishery()` przy zapisie i własne przekierowania — modal omijałby te trzy
warstwy (`docs/conventions/autoryzacja.md` §4).
