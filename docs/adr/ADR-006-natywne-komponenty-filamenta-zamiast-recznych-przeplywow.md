# ADR-006 — Natywne komponenty Filamenta zamiast ręcznych przepływów; hub podrzędnych zasobów zamiast RelationManagerów

- **Status:** accepted
- **Data:** 2026-08-16
- **Zadanie:** [012 — Poprawki panelu właściciela po upgrade do Laravel 13](../tasks/012-poprawki-panelu-wlasciciela-po-laravel-13.md)

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
