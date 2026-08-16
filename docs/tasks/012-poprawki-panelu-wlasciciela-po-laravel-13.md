# 012 — Poprawki panelu właściciela po upgrade do Laravel 13 (refaktor na natywny Filament)

## Opis problemu

Po upgrade do Laravel 13 i Filament 5
([009](implemented/009-upgrade-laravel-13-filament-5-php-84.md)) rozsypało się kilka formularzy
i widoków w panelu właściciela (`/owner`):

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
  odpowiednik zgodny z Filament v5) w `OwnerPanelProvider.php`.
  ⚠️ **Zweryfikowane przy `/review-task`: `viteTheme()` nie jest zarejestrowane w ŻADNYM
  z paneli** — nie ma więc działającego wzorca w `AdminPanelProvider.php` do skopiowania,
  wbrew pierwotnemu założeniu tego zadania. Jeśli motyw okaże się potrzebny, trzeba go
  zbudować od zera (własny plik CSS motywu + wpis w `vite.config.js`, dziś `input` to tylko
  `resources/css/app.css` i `resources/js/app.js`) — i wtedy tym bardziej obowiązuje kryterium
  o braku regresji w panelu administratora.

### Testy kreatora

- **Osiem testów w `tests/Feature/OwnerPanelTest.php` asertuje URL-owy przepływ kreatora**
  (`?wizard=1`, `verify-company`, „Step 1/2/3 / 3", `verified_earlier=1`), który to zadanie
  usuwa. Każdy z nich ma zostać **przepisany** na odpowiednik testujący natywny `Wizard`, nie
  usunięty — patrz „Rozstrzygnięcia". Dotyczy testów: `Owner with company can view first wizard
  fishery step.`, `Owner without company can not view top choose company form.`, `Owner can see
  company verification screen.`, `Owner can see last fishery verification step.`, `Not see
  wizard buttons on normal create company form`, `Not see wizard buttons on normal create
  fishery form`, `See information about skipping step 2 if company was verified earlier.`,
  `Not see information about skipping step 2 if company was not verified earlier.`

## Kryteria akceptacji

- [x] Kliknięcie „Pokaż mapę" pokazuje podgląd mapy niezależnie od nawigacji Livewire.
      Rozwiązane u źródła: adres liczy się SERWEROWO (`ViewField::viewData()` + pola `live()`),
      a przycisk tylko przełącza widoczność w Alpine — zniknęła cała zależność od
      `DOMContentLoaded` i od scrape'owania DOM-u. Pilnuje `FisheryMapPreviewTest`.
- [x] Kreator działa jako jeden formularz `Wizard` (kroki: Firma → Przelew weryfikacyjny →
      Łowisko). Zweryfikowane end-to-end: utworzenie łowiska z **istniejącą** firmą i ze
      **świeżo założoną** firmą (ta druga dostaje `user_id` właściciela i zostaje podpięta
      jako `company_id`). Formularz nowej firmy — łącznie z pobraniem danych z GUS — pochodzi
      z `CompanyResource::formComponents()`, więc nie jest powielony.
- [x] Pole wyboru firmy to natywny `Select` (`FisheryResource::companyField()`).
- [x] Strona „Zarządzaj łowiskiem" pozostaje hubem z trzema zakładkami, licznikami
      (natywne `Tab::badge()`) i linkami „Utwórz"/„Lista", **bez** formularza edycji łowiska.
      Zakładki to `Tabs`/`Tab` ze schematu, przyciski to `Action::make()->icon(...)`.
- [x] Przyciski mają rozmiar i styl pilnowany przez framework — surowe `<svg>`
      (`x-icons.plus`, `x-icons.view`) zniknęły razem z komponentami, które ich używały.
      ⚠️ W listach zasobów podrzędnych nie było czego naprawiać: korzystają z natywnych
      akcji tabel Filamenta, problem dotyczył wyłącznie huba (patrz „Wyniki weryfikacji", pkt 4).
- [x] Panel administratora bez regresji — `OwnerPanelProvider.php` **nie był w ogóle
      zmieniany** (motyw `viteTheme()` okazał się niepotrzebny, patrz „Wyniki weryfikacji",
      pkt 3). Dodatkowo pilnuje tego test `admin panel keeps the flat fishery form…`.
- [x] **Osiem testów kreatora przepisanych** na `FisheryWizardTest.php` (9 testów), nie
      usuniętych — zachowane pokrycie przypadków brzegowych. Mapowanie stary → nowy
      w „Wyniki weryfikacji", pkt 5.
- [x] Pełny pakiet zielony: **91 testów, 315 asercji** (przed zadaniem: 88/317 — mniej asercji,
      bo osiem testów URL-owych zastąpiło dziewięć węższych, celowanych w stan formularza).

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

- [x] **`docs/conventions/panel-wlasciciela.md` — ZAŁOŻONY.** Pięć sekcji: natywne komponenty
      zamiast ręcznych zamienników, hub zamiast RelationManagerów (z odsyłaczem do ADR-006),
      formularze współdzielone między panelami (w tym pułapka `unique()` bez jawnej tabeli),
      skrypty w widokach (`DOMContentLoaded`/scrape DOM-u) oraz — dołożone ponad plan —
      pułapka testowa `Livewire::test()` poza kontekstem panelu (patrz „Wyniki weryfikacji", pkt 6).
- [x] `CLAUDE.md` — znacznik ⛏️ usunięty przy wierszu `panel-wlasciciela.md`.
- [x] `docs/conventions/panel-admina.md` — dopisany odsyłacz do konwencji panelu właściciela
      (zasoby są współdzielone, część rozgałęzia się przez `Helper::isOwnerPanel()`).
- [x] `README.md` — bez zmian (nie dotyczy).
- [x] `CHANGELOG.md` — trzy wpisy w „Poprawione" (mapa, kreator, strona zarządzania).

## Ograniczenia techniczne

- Stack: Laravel 13, Filament 5.7.6, PHP 8.4 (patrz `composer.json`/`composer.lock`).
- Naprawa punktu 1 (mapa) musi działać poprawnie w kontekście nawigacji Livewire
  (`wire:navigate`), nie tylko przy twardym przeładowaniu strony — bez polegania wyłącznie na
  `DOMContentLoaded`.
- Zmiana w `OwnerPanelProvider.php` (jeśli dotyczy motywu CSS) nie może wyłączyć ani zepsuć
  ładowania styli panelu administratora.

## Wyniki weryfikacji (implementacja, 2026-08-16)

Stan końcowy: **91 testów, 315 asercji**, `pint` czysto, PHPStan `[OK] No errors`,
baseline **zmalał z 47 do 43 błędów** (36 → 32 wpisy).

### 1. Kolejność z „Rozstrzygnięć" okazała się trafna

Mapa (punkt 1) była w pełni niezależna i domknęła się w kilkanaście minut, zanim zaczęła się
zmiana strukturalna — dokładnie tak, jak zakładało rozstrzygnięcie z `/review-task`.

### 2. Mapa: przyczyna leżała głębiej niż `DOMContentLoaded`

Zadanie wskazywało `DOMContentLoaded` jako przyczynę i to się potwierdziło, ale naprawa samego
listenera zostawiłaby drugą, równie kruchą połowę: `getFieldValue()`/`getStateName()` czytały
wartości z DOM-u selektorami powiązanymi z biblioteką stylującą select (`.choices__item`).
Zamiast łatać oba, adres liczy się teraz **serwerowo** w `ViewField::viewData()` z pól `live()`,
a widok tylko przełącza widoczność w Alpine. Zniknęło ~180 linii JS-u, a razem z nimi cała
zależność od struktury DOM-u Filamenta.

### 3. `viteTheme()` okazało się niepotrzebne — `OwnerPanelProvider` nietknięty

Zadanie zakładało (i uzasadniało nim tier T3), że naprawa wymaga rejestracji motywu CSS
w `OwnerPanelProvider.php`. **Nie była potrzebna**: po przejściu na komponenty natywne nie
został ani jeden element stylowany surowymi klasami Tailwind, a natywne komponenty korzystają
z CSS-u, który panel i tak ładuje — dokładnie tak, jak przewidywała rekomendacja w ADR-006.
Provider panelu nie został w ogóle zmieniony.

⚠️ **To unieważnia uzasadnienie tieru podane w zadaniu, ale nie sam tier.** Zadanie wywodziło
T3 z „zmiany w `OwnerPanelProvider.php`, który jest na liście wyzwalaczy" — a ta zmiana nie
nastąpiła. T3 pozostaje jednak właściwy z innego powodu: refaktor przepisuje zasoby
**współdzielone przez oba panele** (`FisheryResource`, `CompanyResource`) i wymienia osiem
testów, więc węższy filtr nie pokrywałby tego, co realnie się rusza.

### 4. Listy zasobów podrzędnych nie miały problemu z ikonami

Zadanie dopuszczało punktową naprawę „gigantycznych ikon" także w listach
`LongTermPermitResource`/`AdditionalServiceResource`/`PositionResource`. Sprawdzone: te listy
korzystają z natywnych akcji tabel Filamenta i **nie zawierają surowych `<svg>`**. Objaw
dotyczył wyłącznie huba, który jako jedyny renderował własne komponenty ikon. Nic tam nie
zmieniano.

### 5. Mapowanie ośmiu przepisanych testów

Wszystkie żyją teraz w `tests/Feature/FisheryWizardTest.php` (9 testów — jeden stary rozpadł
się na dwa, bo dotyczył dwóch niezależnych rzeczy):

| Stary test (URL-owy) | Nowy odpowiednik (stan formularza) |
|---|---|
| `Owner with company can view first wizard fishery step.` | `owner with a company starts the wizard in the choose-existing-company mode` |
| `Owner without company can not view top choose company form.` | `owner without any company starts the wizard straight in the new-company mode` |
| `Owner can see company verification screen.` | `verification step is shown when the chosen company is not verified yet` |
| `Owner can see last fishery verification step.` | `wizard has three steps: company, verification and fishery details` |
| `See information about skipping step 2 if company was verified earlier.` | `verification step is skipped when the chosen company was verified earlier` |
| `Not see information about skipping step 2 if company was not verified earlier.` | (pokryte przez test „shown when not verified" — ta sama gałąź, druga strona warunku) |
| `Not see wizard buttons on normal create company form` | `creating a company outside the wizard stays a plain form, without wizard steps` |
| `Not see wizard buttons on normal create fishery form` | `admin panel keeps the flat fishery form instead of the owner wizard` — stary test stracił sens, bo w panelu właściciela tworzenie łowiska **jest** kreatorem; odpowiednikiem jest sprawdzenie, że panel administratora go NIE dostaje |

Dołożone ponad przepisanie (pokrywają logikę biznesową, której URL-owe testy nie sprawdzały):
`creating a fishery through the wizard assigns the chosen existing company` oraz
`… also creates the new company and links it`.

### 6. Pułapka testowa: `Livewire::test()` ignoruje kontekst panelu

Pierwsze testy kreatora **przechodziły, nie sprawdzając niczego z tego, co miały**.
`Livewire::test()` montuje komponent poza panelem, więc `Filament::getCurrentOrDefaultPanel()`
zwraca panel **domyślny** (`admin`), `Helper::isOwnerPanel()` jest fałszem i renderuje się
płaski formularz administratora. Etykiety „Firma"/„Łowisko", które brałem za kroki kreatora,
pochodziły z pól płaskiego formularza.

Wykryte przez wypisanie kluczy stanu (`company_mode` nie istniał). Rozwiązanie: jawne
`Filament::setCurrentPanel('owner')` w każdym teście kreatora. Reguła trafiła do
`docs/conventions/panel-wlasciciela.md` (sekcja 5) — to jest dokładnie ta klasa fałszywie
zielonego testu, przed którą ostrzega `CLAUDE.md`.

### 7. Poprawki po zgłoszeniu użytkownika (ta sama sesja)

Pierwsza wersja kreatora przeszła testy, ale w realnym użyciu miała trzy wady zgłoszone
przez autora zadania. Wszystkie naprawione, każda z pokryciem testowym:

- **Formularz nowej firmy był wciśnięty w krok 1**, więc właściciel bez firm trafiał od razu
  na „gołe" pola firmy, bez widocznego kontekstu kreatora. Rozdzielone na dwa kroki:
  **„Firma"** (samo pytanie: dla której z moich firm / nowa) i **„Dane firmy"** (formularz).
  Krok z pytaniem znika, gdy nie ma o co pytać; krok z formularzem — gdy wybrano istniejącą
  firmę. Kolejność kroków dla właściciela bez firm to teraz
  `Dane firmy → Przelew weryfikacyjny → Łowisko`, a dla mającego firmy po wybraniu „nowa" —
  `Firma → Dane firmy → Przelew weryfikacyjny → Łowisko`.
- **`Placeholder::make('cso_error_message')->label('')` wypisywał „Cso error message"**
  na czystym formularzu. Filament traktuje pusty ciąg jak *brak* ustawienia etykiety i wraca
  do nazwy komponentu — do ukrycia służy `hiddenLabel()`. Dodatkowo cały komunikat pokazuje
  się teraz dopiero, gdy jest błąd (`visible(fn (Get $get) => filled($get('error')))`).
  Naprawa jest w `CompanyResource::formComponents()`, więc obejmuje też zwykły formularz firmy.
- **Zarzut „nie widać, że to kreator" okazał się częściowo nieporozumieniem po mojej stronie.**
  Pierwsza sonda szukała klasy `fi-wizard` i niczego nie znajdowała, co wyglądało na brak
  renderowania kreatora. Filament 5 używa prefiksu **`fi-sc-wizard`** (komponent schematu) —
  nagłówek z krokami był obecny cały czas. Realnym problemem był kształt kroku 1 (punkt wyżej),
  nie brak nagłówka. Testy asertują teraz etykiety kroków po tej właściwej klasie.

⚠️ **Ślepa plama tamtej wersji testów:** wszystkie scenariusze zakładały właściciela **z** firmą
albo **bez** niej, ale żaden nie sprawdzał ścieżki „mam firmy, a mimo to zakładam kolejną" —
a to w niej `company_id` istniejącej firmy najłatwiej przykryłby świeżo utworzoną. Dołożony
test `owner who already has companies can still create a fishery for a brand new one`.
Przy okazji `handleRecordCreation()` przestało zgadywać ścieżkę z obecności `$data['company']`
(pola ukrytego kroku nie muszą się dehydratować) i decyduje jawnie po `company_mode`.

ℹ️ Przy tej poprawce PHPStan zgłosił, że `forCurrentUser()` występuje w `CreateFishery.php`
dwa razy zamiast raz (baseline). Zamiast **powiększać** baseline, scaliłem oba wywołania
w `ownerCompaniesQuery()` — jedno miejsce zawężania firm do właściciela, przy okazji
memoizowane, bo widoczność kroków sprawdzała to przy każdym renderowaniu.

### 8. Cztery przeoczone wejścia do kreatora

Zgłoszone przez autora zadania: przycisk „Utwórz łowisko" na liście łowisk prowadził
do `companies/create?wizard=1`, czyli do zwykłego formularza firmy — kreator się nie
uruchamiał. Sprawdzenie pokazało, że **przeoczonych wejść było cztery**, nie jedno:

| Miejsce | Było | Jest |
|---|---|---|
| `ListFisheries::getHeaderActions()` | własna `Action` z `companies.create?wizard=1` | `CreateAction` (kieruje na stronę tworzenia łowiska) |
| `layouts/navigation.blade.php` (desktop) | `companies.create?wizard=1` | `fisheries.create` |
| `layouts/navigation.blade.php` (mobile) | `companies.create?wizard=1` | `fisheries.create` |
| `welcome.blade.php` | `companies.create?wizard=1` | `fisheries.create` |

⚠️ **Dlaczego to przeszło przez wszystkie poprzednie kontrole.** Sprzątanie po refaktorze
szukało *nazw usuwanych bytów* (`isWizard`, `VerifyCompany`, `fishery-wizard-headers`,
`x-tab-button`…) i te znalazło komplet. Nie szukało natomiast **trasy**, która przestała
prowadzić do kreatora, choć nadal istnieje i zwraca 200 — `companies/create` to dalej
poprawny adres zwykłego formularza firmy, więc nic nie pękało: ani testy, ani `view:cache`,
ani PHPStan. Jedyny sygnał był wizualny, po kliknięciu.

Po refaktorze `ListFisheries` nie potrzebuje już własnej trasy w panelu właściciela — kreator
leży dokładnie tam, gdzie i tak kieruje standardowa `CreateAction`; z gałęzi została wyłącznie
etykieta, żeby nie zmieniać napisu widocznego dla właściciela.

Powstał `tests/Feature/FisheryWizardEntryPointsTest.php`, który sprawdza **wejścia**, nie sam
kreator: żaden widok nie linkuje do wycofanego adresu, przycisk na liście prowadzi do kreatora,
a publiczny link „Zarejestruj łowisko" też. Poprzednie testy kreatora tego nie widziały, bo
wszystkie wchodziły na jego URL **wprost**.

### 9. Druga tura poprawek po teście ręcznym

Siedem kolejnych usterek zgłoszonych przez autora zadania po przejściu kreatora klikaniem.
Warto je odnotować razem, bo pokazują wspólny wzorzec: **testy sprawdzały stan i dane, a nie
to, co użytkownik widzi i klika.**

| Zgłoszenie | Przyczyna | Naprawa |
|---|---|---|
| Wybór firmy pod przełącznikiem trybu, nie przy nim | `Radio` i `Select` jako osobne komponenty jeden pod drugim | oba w `Grid::make(2)` — w jednej linii |
| Opcja „…nową firmę **poniżej**", a poniżej nic nie ma | formularz firmy przeniesiony do osobnego kroku, etykieta została ze starego układu | etykieta „Utwórz nową firmę" |
| „Utwórz" i „Utwórz i utwórz kolejne" na **każdym** kroku | domyślne akcje formularza `CreateRecord` renderują się niezależnie od `Wizard` | `Wizard::submitAction()` na ostatnim kroku + `getFormActions()` zwraca w panelu właściciela wyłącznie „Anuluj" |
| Mapa nie ładuje się nawet po kliknięciu | patrz niżej — osobny akapit | usunięty przełącznik, mapa renderuje się sama |
| Po utworzeniu łowiska powrót na listę | `getRedirectUrl()` zwracał `index` | przekierowanie na stronę **zarządzania** nowym łowiskiem |
| Pusta usługa dodatkowa w formularzu stanowiska | `Repeater` Filamenta domyślnie renderuje jedną pozycję (`defaultItems(1)`), a wybór usługi jest `required()` | `->defaultItems(0)` |

⚠️ **Pusta usługa dodatkowa nie była usterką kosmetyczną** — pusty wiersz blokował zapis
stanowiska, dopóki użytkownik nie domyślił się go usunąć. Test negatywny to potwierdza:
po cofnięciu `defaultItems(0)` czerwienieje nie tylko asercja o pustej liście, ale i ta
o możliwości zapisania stanowiska bez usług.

⚠️ **Mapa — trzecia próba i wniosek na przyszłość.** Kolejne wersje przewracały się na:
(1) `DOMContentLoaded`, które nie pada po nawigacji Livewire; (2) scrape'owaniu DOM-u
selektorami biblioteki stylującej select; (3) **lokalnym stanie Alpine** (`x-data="{ shown: false }"`)
— każda aktualizacja pola `live()` przerenderowuje ten fragment, więc stan wracał do wartości
początkowej i mapa znikała tuż po pokazaniu. Serwerowy HTML wyglądał w testach poprawnie
(iframe z właściwym adresem), bo problem był wyłącznie w cyklu życia komponentu w przeglądarce.
Rozwiązanie: **żadnego stanu po stronie widoku** — skoro adres i tak liczy się serwerowo,
mapa pojawia się sama, gdy adres jest kompletny. Przycisk „Pokaż mapę" był reliktem
architektury, w której trzeba było czymś wyzwolić odczyt DOM-u.

ℹ️ Przy okazji usunięto dziewięć kluczy tłumaczeń osieroconych przez te zmiany
(`Show Map`, `Form not found`, `Please fill in all address fields first.` i pozostałe po starym
podglądzie mapy). Zweryfikowano porównaniem zdekodowanego JSON-a przed i po: **żadnej
wartości nie zmieniono ani nie zgubiono**, zmiany to wyłącznie dodania i świadome usunięcia.

### 10. Doprecyzowana reguła kroku weryfikacji

Autor zadania doprecyzował regułę produktową: **przelew weryfikacyjny dotyczy wyłącznie nowo
zakładanej firmy.** Firma wybrana z listy jest z założenia zweryfikowana — nie sprawdzamy jej
`is_verified`.

Warunek uprościł się do `visible(fn (Get $get) => $this->wantsNewCompany($get))`. Przy okazji
zniknął efekt uboczny poprzedniej wersji: krok pytał o `is_verified` wybranej firmy, więc
**migotał** — pokazywał się, zanim użytkownik cokolwiek wybrał, i znikał po wyborze.

⚠️ **`is_verified` nie steruje już żadnym zachowaniem aplikacji** — po tej zmianie pole jest
odczytywane wyłącznie przez interfejs administratora (przełącznik i kolumna w `CompanyResource`).
Firma zakładana kreatorem trafia do bazy z `is_verified = false` (domyślna wartość kolumny),
mimo że przechodzi przez zamockowany krok weryfikacji. Dane są więc niespójne z założeniem
„firma z listy jest zweryfikowana", choć samo zachowanie UI jest spójne.
**Otwarte pytanie na przyszłość:** czy zaliczenie zamockowanego kroku ma ustawiać
`is_verified = true`? Celowo nie rozstrzygnięte tutaj — dopóki weryfikacja jest atrapą,
oznaczanie firmy jako zweryfikowanej byłoby stwierdzeniem nieprawdy w danych.

### 11. Błąd 500 przy zapisie usługi dodatkowej

`BindingResolutionException: An attempt was made to evaluate a closure for [TextInput],
but [$attribute] was unresolvable` przy zapisie formularza usługi dodatkowej.

Przyczyna w `Helper::getPriceInput()`: reguła walidacyjna była przekazana do `rules()` jako
domknięcie Laravela `fn (string $attribute, $value, Closure $fail)`. Filament woła `evaluate()`
na **każdym** elemencie `rules()` (`CanBeValidated::getValidationRules()`, linia 872) i wstrzykuje
argumenty **po nazwie** — próbował więc rozwiązać `$attribute` z kontenera. Poprawka: opakowanie
w domknięcie, które regułę **zwraca** (`static fn (): Closure => static function (...) {...}`).

⚠️ Błąd ujawniał się dopiero przy **wysłaniu** formularza, nie przy jego otwarciu — dlatego
testy renderujące stronę go nie widziały. `AdditionalServicePriceTest` woła `create()` i sprawdza
też, że reguła nadal **działa** (cena poniżej minimum jest odrzucana), a nie tylko przestała
rzucać wyjątkiem.

ℹ️ **Fałszywy alarm przy okazji, wart odnotowania.** Pierwsza wersja testu asertowała
`(float) $service->price` i pokazała `49.0` dla ceny `49,50`, co wyglądało na utratę części
dziesiętnej. To był błąd testu, nie aplikacji: akcesor `AdditionalService::getPriceAttribute()`
formatuje cenę po polsku (kropka → przecinek) przy locale `pl`, a Eloquentowy `value()`
przepuszcza wartość przez akcesor — `(float) "49,50"` ucina przy przecinku. W bazie jest
poprawne `49.50`. Test czyta teraz surową wartość przez `DB::table()`, z pominięciem akcesora.

### 12. Usunięty martwy kod

Po refakotrze przestały być używane i zostały usunięte: `app/Filament/Owner/Pages/VerifyCompany.php`,
`resources/views/filament/owner/pages/verify-company.blade.php`,
`resources/views/components/fishery-wizard-headers/{company,fishery}.blade.php`,
`resources/views/filament/resources/fisheries/pages/manage.blade.php`,
`resources/views/components/{tab-button,management-tab}.blade.php`,
`resources/views/components/icons/{plus,view}.blade.php`, `Helper::isWizard()` oraz cała
gałąź kreatora w `CreateCompany` (`selectCompany()`, `getHeader()`, `getViewData()`,
`handleRecordCreation()`, właściwości `$wizard`/`$companyId`). Każdy plik przed usunięciem
sprawdzony grepem na brak odwołań; wszystkie były śledzone przez gita, więc odwracalne.

### 13. Trzecia tura poprawek: hub przebudowany na RelationManagery

Zgłoszenia użytkownika: (a) listy w hubie schowane za przyciskiem „Lista", (b) ścieżka okruszków
`Stanowiska › Lista` zamiast `Łowiska › {łowisko} › Stanowiska`, (c) wejście w hub od razu
wrzuca w jedną z trzech list, (d) po przebudowie zakładki wjechały też do edycji łowiska,
(e) w zakładce z danymi brakowało „Edytuj", (f) z pozostałych zakładek zniknęły przyciski
dodawania.

Zrobione:

- **Trzy RelationManagery** (`FisheryResource/RelationManagers/`) delegujące `table()`/`form()`
  do `PositionResource` / `AdditionalServiceResource` / `LongTermPermitResource` — zero
  powielonych kolumn. Kolejność i zakładka „Dane łowiska" jako pierwsza: wbudowane
  `hasCombinedRelationManagerTabsWithContent()` + `getContentTabLabel()`. Zapis decyzji:
  sekcja „Aktualizacja" w [ADR-006](../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md).
- **`ManageFishery` jako `ViewRecord`** z `infolist` (dane + adres) i akcją nagłówka „Edytuj".
- **`Helper::fisheryBreadcrumbs()`** — jedno źródło okruszków dla dziewięciu stron podrzędnych.
- **`canViewForRecord()`** w każdym RelationManagerze. Bez tego `getRelations()` wstrzyknął
  listy również na stronę edycji łowiska (zgłoszenie d) — `getRelations()` obowiązuje
  **wszystkie** strony zasobu, nie tylko hub.

**Pułapka, która kosztowała najwięcej: `CreateAction` w RelationManagerze nie renderuje się.**
Przycisk przepada na własnej autoryzacji relacji — bez wyjątku, bez wpisu w logu, po prostu
`array_filter(…, isVisible())` w widoku tabeli wycina go z HTML-a. Zamiana na zwykłą `Action`
z jawnym `Gate::allows('create', Model::class)` i `url()` na pełną stronę tworzenia rozwiązała
sprawę (zgłoszenie f).

**Pułapka druga, testowa:** pierwsze próby diagnozy dały fałszywe „brak przycisku", bo
`Livewire::test(...)->html()` łapie stan `isTableLoaded: false` — tabela dociąga się osobnym
żądaniem. `assertCanSeeTableRecords()` wymusza doładowanie samo, surowy `->html()` wymaga
`->call('loadTable')`. Wniosek trafił do konwencji panelu właściciela.

**Liczniki:** przeniesione z `Tab::badge()` do `getBadge()` RelationManagerów. Przy okazji
naprawione trzy testy, które sprawdzały licznik przez `assertSee('1')` na całej stronie —
taka asercja przechodziła też wtedy, gdy plakietki w ogóle nie było; teraz czytają
`getBadge()` u źródła.

**PHPStan — baseline zmniejszony, nie powiększony.** Nowy kod dał 6 błędów (`Model::positions()`,
`HasMany::isActive()`, `Model::$name`). Zamiast wpisów do baseline'u: generyki na trzech
relacjach `Fishery` (`@return HasMany<Position, $this>`) i zawężenie typu przez `assert()`.
Larastan rozpoznaje wtedy `#[Scope]` i wszystko znika u źródła — razem ze **starymi** wpisami
dla `ManageFishery` (32 → 30 wpisów). Reguła trafiła do `CLAUDE.md`.

Testy: `OwnerPanelTest` urósł z 10 do 15 przypadków (nowe: renderowanie list w zakładkach dla
trzech zasobów, link „Edytuj" w zakładce danych, brak zakładek na stronie edycji).

### 14. Powrót po zapisie na zakładkę huba

Zgłoszenie: po dodaniu stanowiska użytkownik lądował na `/owner/positions?fishery=2`, czyli
na samotnej liście poza kontekstem łowiska, zamiast na `/owner/fisheries/2/manage?relation=2`.

Wszystkie sześć stron (Create/Edit × stanowiska, usługi, pozwolenia) dostało prywatną
`sectionUrl()` opartą o nowy `Helper::fisheryHubUrl()`; ta sama metoda obsługuje
`getRedirectUrl()` **i** link sekcji w okruszkach, żeby zapis i nawigacja prowadziły w to
samo miejsce. Strony `Edit*` wcześniej w ogóle nie miały `getRedirectUrl()` — zostawały po
zapisie na formularzu.

⚠️ **Klucz zakładki to POZYCJA w `FisheryResource::getRelations()`**, nie nazwa klasy.
Zaszycie `0/1/2` w sześciu miejscach przetrwałoby testy, a przestawienie kolejności zakładek
przekierowywałoby po zapisie na cudzą listę — bez błędu i bez czerwonego testu. Dlatego indeks
liczy `array_search()` z tej samej tablicy, którą renderuje hub, a testy liczą oczekiwany adres
tak samo, zamiast przyklepywać numer.

**To zmienia zachowanie także w panelu administratora** — trzy zasoby podrzędne to jedne
i te same klasy zarejestrowane w obu providerach, a rozgałęzienie `Helper::isOwnerPanel()`
tylko po to, by admin trafiał gdzie indziej, byłoby kosztem bez pokrycia. Reguła z zadania 011
(„po utworzeniu wracamy na `getUrl('index', ['fishery' => …])`") została dla tych trzech
zasobów **przepisana** w `docs/conventions/panel-admina.md`; dwa testy z 011 zaktualizowane.

**Przy okazji sprostowana nieaktualna reguła testowa.** Zadanie 011 zapisało, że
`Livewire::withQueryParams()` nie dowozi query stringa do `mount()`. Po upgrade z zadania 009
dowozi — nowy test w `OwnerPanelTest` montuje stronę tworzenia z `?fishery=` bez 404
z `assertFisheryAccessOrAbort()` i sprawdza gałąź `request()->get('fishery')`, czyli tę realnie
używaną w przeglądarce. Notatka w konwencjach panelu admina poprawiona.

⚠️ **Flake do obserwacji:** jeden z trzech pełnych przebiegów pokazał czerwony
`Owner can view only his fishery.`; dwa kolejne pełne przebiegi oraz cały plik uruchomiony
osobno przechodzą. Nie udało się powtórzyć ani powiązać ze zmianami tego zadania.

## Rozstrzygnięcia

- Zamiast punktowej łatki wizualnej wybrano refaktor kreatora i strony zarządzania łowiskiem
  na natywne komponenty Filamenta (`Wizard`, `Tabs`, `Action` z ikoną) — uzasadnienie: te same
  pliki wymagały ręcznej poprawki przy poprzednim upgradzie (Laravel 13), a ręcznie pisane
  zamienniki natywnych komponentów Filamenta są głównym źródłem obu regresji z tego zadania.
  Potwierdzone przez autora zadania w rozmowie poprzedzającej utworzenie tego pliku.
- Strona „Zarządzaj łowiskiem" ma pozostać szybkim hubem/submenu do zarządzania pozycjami
  podrzędnymi, **nie** stroną edycji łowiska z osadzonymi RelationManagerami — to świadome
  odejście od domyślnego wzorca Filamenta, zachowane celowo z obecnej implementacji.
- **Osiem testów URL-owego kreatora zostaje PRZEPISANYCH na nowy przepływ, nie usuniętych.**
  Ustalone przy `/review-task` (`AskUserQuestion`). Powód: zadanie wymaga, żeby logika
  biznesowa działała „tak samo jak dziś, funkcjonalnie bez regresji" — a jedyne, co dziś tego
  pilnuje, to właśnie te testy (przypadki brzegowe: brak firmy, firma zweryfikowana wcześniej).
  Skasowanie ich i napisanie 2-3 nowych na happy path zostawiłoby refaktor bez siatki
  bezpieczeństwa dokładnie tam, gdzie jest najbardziej potrzebna. Odrzucono też wariant
  „stare trasy jako przekierowania" — to warstwa zgodności wstecznej dla aplikacji, która nie
  ma jeszcze użytkowników, czyli dług bez powodu.
- **Kolejność wykonania: najpierw naprawa mapy (punkt 1), potem oba refaktory.** Ustalone przy
  `/review-task`. Mapa jest niezależna od pozostałych dwóch punktów i znacznie mniejsza —
  zrobiona pierwsza daje szybkie, weryfikowalne domknięcie jednego z trzech objawów, zanim
  zacznie się zmiana strukturalna. Zadanie zostaje jednym plikiem: wspólna przyczyna (ręczne
  zamienniki natywnych komponentów Filamenta) uzasadnia trzymanie tych prac razem.

## Powiązane ADR-y

- [**ADR-006 — Natywne komponenty Filamenta zamiast ręcznych przepływów; hub podrzędnych
  zasobów zamiast RelationManagerów**](../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md)
  — utworzony przy `/review-task`. Kandydat zgłoszony w treści zadania okazał się spełniać
  **wszystkie trzy** warunki kryterium z `CLAUDE.md`:
  1. **Zasięg poza zadaniem** — ustala wzorzec dla każdego przyszłego kreatora wieloetapowego
     i każdej strony zarządzania zasobami podrzędnymi w obu panelach.
  2. **Wysoki koszt odwrócenia** — powrót oznacza odtworzenie trzech stron, `verify-company`,
     `Helper::isWizard()`, komponentów Blade i ponowne przepisanie ośmiu testów.
  3. **Uzasadnienie warte zapamiętania** — utrzymanie huba **bez** formularza edycji jest
     świadomym odejściem od domyślnego wzorca Filamenta (RelationManagery na stronie edycji).
     Bez zapisu ktoś za rok „naprawi" to z powrotem do wzorca z dokumentacji frameworka,
     nie wiedząc, że nietypowy kształt jest celowy.

  ⚠️ **Sekcja „Decyzja" w ADR-ze jest pusta — wypełnia autor przed `/implement-task`.**
