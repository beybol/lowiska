# 012 — Poprawki panelu właściciela po upgrade do Laravel 13 (refaktor na natywny Filament)

## Opis problemu

Po upgrade do Laravel 13 i Filament 5 ([009](implemented/009-upgrade-do-laravel-13.md) — jeśli
zadanie ma inny numer po wdrożeniu, poprawić link) rozsypało się kilka formularzy i widoków
w panelu właściciela (`/owner`):

1. **Kreator tworzenia łowiska, krok 3** — podgląd mapy Google Maps nie działa. Przycisk
   „Pokaż mapę" nic nie robi.
   Pole: `ViewField::make('map_preview')` w
   [`FisheryResource.php:108-110`](../../app/Filament/Resources/FisheryResource.php),
   widok [`resources/views/filament/forms/map-preview.blade.php`](../../resources/views/filament/forms/map-preview.blade.php).
   Obsługa kliknięcia jest spięta przez `document.addEventListener('DOMContentLoaded', ...)`
   (linia ok. 160), które wiąże się z `#showMapButton` tylko raz, przy pierwszym twardym
   załadowaniu strony. Filament v5 nawiguje przez Livewire/`wire:navigate` i przerenderowuje
   formularz (w tym ten `ViewField`) bez nowego zdarzenia `DOMContentLoaded` — po dowolnej
   interakcji Livewire listener nigdy nie zostaje ponownie podpięty. `getFieldValue()`
   (linie ok. 189-210) opiera się też na kruchych selektorach (`.choices__item`,
   `select#data\.state_id`) powiązanych z biblioteką stylującą select, która może już nie
   odpowiadać znacznikom Filamentu v5.

2. **Kreator tworzenia łowiska, krok 2** — wybór firmy renderuje się źle wizualnie (goły,
   niestylowany element), a wybrana firma nie przenosi się do kroku 3.
   Widok kroku:
   [`resources/views/components/fishery-wizard-headers/company.blade.php`](../../resources/views/components/fishery-wizard-headers/company.blade.php)
   (linie 20-34) renderuje zwykły `<select wire:model="companyId">` zamiast komponentu
   Filament `Select` — stąd brak stylowania motywu Filament v5.
   Łańcuch przenoszenia wartości: `CreateCompany::selectCompany()`
   ([`CompanyResource/Pages/CreateCompany.php:94-108`](../../app/Filament/Resources/CompanyResource/Pages/CreateCompany.php))
   przekierowuje na `filament.owner.pages.verify-company?company=...` →
   [`verify-company.blade.php:10-13`](../../resources/views/filament/owner/pages/verify-company.blade.php)
   przekazuje `company` + `wizard=1` dalej do
   `filament.owner.resources.fisheries.create` → `CreateFishery::mount()`
   ([`FisheryResource/Pages/CreateFishery.php:32-37`](../../app/Filament/Resources/FisheryResource/Pages/CreateFishery.php))
   czyta `request()->query('company')` do `$this->companyId`, a
   `mutateFormDataBeforeCreate` (linie 19-30) wstrzykuje to jako `company_id`. Jednocześnie
   `FisheryResource.php:65-80` **ukrywa** pole `company_id` typu Select, gdy
   `Helper::isWizard($livewire)` zwraca `true` — więc każde pęknięcie w łańcuchu parametrów
   URL (albo utrata `$companyId` przy ponownym mountowaniu komponentu Livewire) cicho gubi
   firmę, bez widocznego pola, które by to wychwyciło. Do zweryfikowania:
   `App\Helpers\Helper::isWizard()` ([`Helper.php:150-165`](../../app/Helpers/Helper.php)).

3. **Widok „Zarządzaj łowiskiem"** (zakładki: Pozwolenia długoterminowe, Usługi dodatkowe,
   Stanowiska) — nawigacja pomiędzy zakładkami jest rozsypana, a przyciski dodawania/edycji
   renderują się jako gigantyczne, niestylowane ikony zamiast normalnych małych przycisków.
   Ten sam problem z gigantycznymi ikonami występuje też po wejściu w którąkolwiek z tych
   sekcji (widoki list).
   Strona: [`FisheryResource/Pages/ManageFishery.php`](../../app/Filament/Resources/FisheryResource/Pages/ManageFishery.php),
   widok [`resources/views/filament/resources/fisheries/pages/manage.blade.php`](../../resources/views/filament/resources/fisheries/pages/manage.blade.php)
   (Alpine `x-data="{activeTab:'permits'}"`, komponenty `x-tab-button`, `x-management-tab`,
   linie 9-56). Komponenty ikon
   [`resources/views/components/icons/plus.blade.php`](../../resources/views/components/icons/plus.blade.php),
   [`view.blade.php`](../../resources/views/components/icons/view.blade.php) używają gołych
   klas Tailwind (`h-4 w-4 mr-2`) — ten sam wzorzec w
   [`components/management-tab.blade.php:14-29`](../../resources/views/components/management-tab.blade.php)
   i [`components/tab-button.blade.php`](../../resources/views/components/tab-button.blade.php).

**Podejrzewana wspólna przyczyna punktów 2 i 3:**
[`app/Providers/Filament/OwnerPanelProvider.php`](../../app/Providers/Filament/OwnerPanelProvider.php)
nie rejestruje `->viteTheme(...)` — panel właściciela ładuje wyłącznie domyślne, prebuildowane
CSS Filamentu, nigdy nie dołącza projektowego builda Tailwind (`tailwind.config.js` obejmuje
`resources/views/**/*.blade.php`, ale ten bundle nie jest ładowany na stronach Filamentu). Stąd
surowe komponenty Tailwind użyte wewnątrz widoków Filamentu (ikony, tab-button,
management-tab, goły `<select>`) renderują się bez pasującego CSS — gigantyczne surowe SVG,
niestylowany `<select>`, rozsypana nawigacja zakładek. Do zweryfikowania w trakcie
implementacji: czy przed upgrade'em istniał dedykowany plik motywu CSS panelu i został
zgubiony przy migracji do Filament v5 (zmiana API rejestracji motywu paneli).

**Głębsza przyczyna (potwierdzona dodatkowym śledztwem):** punkty 2 i 3 to nie tylko brak
motywu CSS — to symptom tego, że kreator i strona zarządzania łowiskiem są w całości
ręcznie pisane (custom), zamiast korzystać z natywnych komponentów Filamenta:

- **Kreator** nie używa komponentu `Wizard`/`Step` Filamenta. To trzy osobne strony
  (`CreateCompany`, ręczny widok `verify-company.blade.php` jako „krok 2", `CreateFishery`)
  sklejone przez flagę `?wizard=1` w query stringu i przekazywanie stanu (`company`, `wizard`)
  przez parametry URL między przekierowaniami. Krok „wyboru firmy" renderuje gołego
  `<select wire:model="companyId">` zamiast pola Filament `Select` — stąd brak stylowania i
  gubienie wartości przy każdym pęknięciu w łańcuchu URL. `Helper::isWizard()` istnieje
  wyłącznie po to, by `FisheryResource::form()` wiedział, że jest renderowany z tego
  ręcznego przepływu (i ukrył pole `company_id`).
- **Strona „Zarządzaj łowiskiem"** to zwykły `ViewRecord` z customowym widokiem
  (`manage.blade.php`), gdzie przełączanie zakładek jest zrobione ręcznie w Alpine.js
  (`x-data="{activeTab: 'permits'}"`, `x-show`) przez własne komponenty
  `<x-tab-button>`/`<x-management-tab>`, a nie natywnymi `Tabs`/RelationManagerami Filamenta —
  w zasobie `FisheryResource` w ogóle nie ma katalogu `RelationManagers`. Przyciski
  dodawania/podglądu to surowe `<svg>` z ręcznie wpisanymi klasami Tailwind
  (`<x-icons.plus>`, `<x-icons.view>`) w zwykłych `<a>`, zamiast `Action::make()->icon(...)`,
  które samo pilnuje rozmiaru i stylu.

Historia gita potwierdza, że te same pliki trzeba było ruszać przy commicie „Upgrade do
Laravel 13" — bez zmiany podejścia ten wzorzec będzie się psuł przy każdym kolejnym większym
upgrade Filamenta.

⚠️ **Ważne zastrzeżenie od autora zadania:** obecne zachowanie strony „Zarządzaj łowiskiem"
to celowo **nie** jest wzorzec „RelationManager osadzony na stronie edycji rekordu
nadrzędnego" (gdzie Filament domyślnie renderuje pełny formularz edycji łowiska nad
zakładkami RelationManagerów). To ma pozostać **szybkim hubem/submenu** z trzema zakładkami
prowadzącymi do zarządzania pozycjami podrzędnymi (pozwolenia długoterminowe, usługi
dodatkowe, stanowiska) — bez formularza edycji samego łowiska na tej stronie. Refaktor ma
zachować tę logikę, zmieniając tylko warstwę prezentacji (customowy Alpine → natywne
komponenty Filamenta), nie dodawać nowego zachowania.

## Wymagania

### Mapa (krok 3 kreatora)

- Przywrócić działanie przycisku „Pokaż mapę" (podgląd Google Maps) — obsługa kliknięcia musi
  działać po nawigacji Livewire (`wire:navigate`), nie tylko po twardym przeładowaniu strony.

### Kreator tworzenia łowiska — refaktor na natywny `Wizard`

- Zastąpić obecny ręczny przepływ (dwie osobne strony `CreateRecord` + pośredni customowy
  widok `verify-company.blade.php`, sklejone przez `?wizard=1` i parametry URL) natywnym
  komponentem Filament `Wizard`/`Step` w ramach jednego formularza tworzenia łowiska.
- Zachować obecną logikę biznesową bez zmian: wybór istniejącej firmy z listy firm
  użytkownika **albo** utworzenie nowej firmy (w tym pobranie danych z GUS/CSO —
  `Helper::fetchDataFromCSO()`), przypisanie wybranej/utworzonej firmy jako `company_id`
  tworzonego łowiska, walidacje pól poszczególnych kroków.
- Krok wyboru firmy ma używać natywnego pola Filament `Select` (nie gołego `<select>`) —
  eliminuje to zarówno problem stylowania, jak i gubienie wybranej wartości (stan kroku
  trzyma wtedy Wizard, nie parametry URL).
- Usunąć zbędną po refaktorze plumbing: `Helper::isWizard()` (o ile po zmianie nic już z niej
  nie korzysta), customowe widoki `fishery-wizard-headers/*.blade.php`,
  `verify-company.blade.php` i logikę przekierowań w `CreateCompany::selectCompany()` —
  **tylko jeśli faktycznie stają się martwym kodem** po przejściu na `Wizard`.

### Strona „Zarządzaj łowiskiem" — refaktor na natywne komponenty Filament

- Zastąpić ręczny Alpine.js (`x-data="{activeTab:...}"`, `<x-tab-button>`,
  `<x-management-tab>`) natywnym mechanizmem zakładek Filamenta (np.
  `Filament\Schemas\Components\Tabs` / odpowiednik dostępny w Filament 5 do budowy stron z
  zakładkami) — **bez** osadzania pełnego formularza edycji łowiska na tej stronie (patrz
  zastrzeżenie w „Opis problemu"). Zakładki mają dalej pełnić rolę huba z licznikiem i
  linkami do „Utwórz"/„Lista" dla każdej z trzech kategorii (pozwolenia długoterminowe,
  usługi dodatkowe, stanowiska) — zachować dokładnie ten zakres funkcji, jaki mają dziś.
- Zastąpić customowe komponenty ikon (`<x-icons.plus>`, `<x-icons.view>`) w linkach
  dodawania/podglądu natywnymi `Filament\Actions\Action` z `->icon('heroicon-...')`, żeby
  rozmiar i stylowanie były pilnowane przez framework, a nie ręcznie wpisane klasy Tailwind.
- Usunąć komponenty Blade, które po refaktorze staną się martwym kodem
  (`components/tab-button.blade.php`, `components/management-tab.blade.php`,
  `components/icons/plus.blade.php`, `components/icons/view.blade.php` — **tylko jeśli**
  nic innego w projekcie ich nie używa; zweryfikować przed usunięciem).

### CSS panelu właściciela

- Jeśli po refaktorze nadal brakuje spójnego stylowania (np. dla elementów, które nie
  przechodzą przez natywne komponenty Filamenta) — zarejestrować `->viteTheme(...)` (lub
  odpowiednik zgodny z Filament v5) w `OwnerPanelProvider.php`, analogicznie do
  `AdminPanelProvider.php` (do sprawdzenia, czy panel administratora ma ten sam problem).

## Kryteria akceptacji

- [ ] Kliknięcie „Pokaż mapę" po wypełnieniu pól adresowych pokazuje podgląd mapy — zarówno
      przy wejściu w kreator bezpośrednio, jak i po przejściu przez wcześniejsze kroki
      (nawigacja Livewire).
- [ ] Kreator tworzenia łowiska działa jako jeden formularz Filament `Wizard` z krokami
      (nie trzy osobne strony sklejone parametrami URL). Wybór/utworzenie firmy (w tym
      pobranie danych z GUS) i finalne utworzenie łowiska z poprawnym `company_id` działają
      tak samo jak dziś, funkcjonalnie bez regresji.
- [ ] Pole wyboru firmy w kreatorze to natywny komponent Filament `Select`, spójny wizualnie
      z resztą panelu.
- [ ] Strona „Zarządzaj łowiskiem" nadal jest hubem z trzema zakładkami (Pozwolenia
      długoterminowe/Usługi dodatkowe/Stanowiska), licznikami i linkami „Utwórz"/„Lista" —
      **bez** formularza edycji łowiska osadzonego na tej stronie — ale zakładki i przyciski
      są zbudowane na natywnych komponentach Filament (Tabs, Action z ikoną), nie na ręcznym
      Alpine.js i surowych `<svg>`.
- [ ] Przyciski dodawania/podglądu mają normalny rozmiar i stylowanie — zarówno na stronie
      zarządzania, jak i w listach trzech sekcji podrzędnych.
- [ ] Panel administratora (`/admin`) nie doznał regresji wizualnej w wyniku zmian w
      `OwnerPanelProvider.php` (manualna weryfikacja, jeśli zmiana dotyczy współdzielonego
      assetu/configu).
- [ ] Pełny pakiet testów zielony: `docker compose exec app php artisan test`.

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** Prawdopodobna przyczyna (punkty 2 i 3) wymaga zmiany w
  `OwnerPanelProvider.php`, który jest na liście wyzwalaczy T3 w CLAUDE.md (providery paneli).
  Nawet gdyby finalna naprawa punktu 1 (JS/Alpine w widoku mapy) tego nie dotknęła, zmiana
  w providerze panelu obejmuje całe zadanie tierem T3.

## Zakres wyłączeń

- **Nie** dodawać na stronę „Zarządzaj łowiskiem" pełnego formularza edycji łowiska ani nie
  przechodzić na standardowy wzorzec „RelationManagery na stronie edycji rekordu
  nadrzędnego" — to jest jawnie wykluczone (patrz zastrzeżenie w „Opis problemu").
- Migracja/wymiana biblioteki stylującej select (np. Choices.js) w innych miejscach niż krok
  wyboru firmy w kreatorze — poza zakresem, chyba że okaże się konieczna do refaktoru.
- Ogólny audyt wszystkich widoków Blade w panelu właściciela pod kątem zgodności z Filament v5
  — tylko elementy objęte tym zadaniem (kreator i strona zarządzania).
- Zmiany w panelu administratora, poza weryfikacją braku regresji.
- Refaktor list/formularzy samych zasobów `LongTermPermitResource`, `AdditionalServiceResource`,
  `PositionResource` (do których hub prowadzi) — poza zakresem, chyba że okaże się, że mają
  ten sam problem z gigantycznymi ikonami (wtedy naprawić punktowo, bez szerszego refaktoru
  tych zasobów).

## Zmiany dokumentacji

- [ ] `docs/conventions/panel-wlasciciela.md` — jeśli zadanie ustali nowy niezmiennik (np. „panel
      właściciela musi rejestrować własny motyw CSS przez `viteTheme`") i plik konwencji
      zostanie założony przy tej okazji.
- [ ] `README.md` — nie dotyczy.
- [ ] `CLAUDE.md` — nie dotyczy.
- [ ] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Stack: Laravel 13, Filament 5.7.6, PHP 8.4 (patrz `composer.json`/`composer.lock`).
- Naprawa punktu 1 (mapa) musi działać poprawnie w kontekście nawigacji Livewire
  (`wire:navigate`), nie tylko przy twardym przeładowaniu strony — bez polegania wyłącznie na
  `DOMContentLoaded`.
- Zmiana w `OwnerPanelProvider.php` (jeśli dotyczy motywu CSS) nie może wyłączyć ani zepsuć
  ładowania styli panelu administratora.

## Rozstrzygnięcia

- Zamiast punktowej łatki wizualnej wybrano refaktor kreatora i strony zarządzania łowiskiem
  na natywne komponenty Filamenta (`Wizard`, `Tabs`, `Action` z ikoną) — uzasadnienie: te same
  pliki wymagały ręcznej poprawki przy poprzednim upgradzie (Laravel 13), a ręcznie pisane
  zamienniki natywnych komponentów Filamenta są głównym źródłem obu regresji z tego zadania.
  Potwierdzone przez autora zadania w rozmowie poprzedzającej utworzenie tego pliku.
- Strona „Zarządzaj łowiskiem" ma pozostać szybkim hubem/submenu do zarządzania pozycjami
  podrzędnymi, **nie** stroną edycji łowiska z osadzonymi RelationManagerami — to świadome
  odejście od domyślnego wzorca Filamenta, zachowane celowo z obecnej implementacji.

## Powiązane ADR-y
-
<!-- Kandydat do oceny przez /review-task: czy „refaktor kreatorów wieloetapowych i stron
     zarządzania podrzędnymi zasobami na natywne komponenty Filamenta, z hubem-bez-formularza
     zamiast domyślnych RelationManagerów" powinien zostać udokumentowany jako ADR — decyzja
     ma zasięg poza to zadanie (wzorzec dla przyszłych kreatorów/stron zarządzania w obu
     panelach) i koszt odwrócenia jest wysoki (migracja z powrotem na custom flow). -->
