# Konwencje: panel właściciela

Obowiązuje przy zmianach w `app/Filament/Owner/**`,
`app/Providers/Filament/OwnerPanelProvider.php` oraz w zasobach współdzielonych,
gdy dotykasz ich zachowania **w panelu właściciela**.

Zadania źródłowe: 012, 013, 029, 048. Ekrany konfiguracji jednego łowiska (§6–§10: ustawienia, doba i sezony, stanowiska,
dokumenty, publikacja) żyją w [`konfiguracja-lowiska.md`](konfiguracja-lowiska.md). Uzasadnienia w [ADR-006](../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md)
i [ADR-010](../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md).

---

## 1. Natywne komponenty Filamenta zamiast ręcznych zamienników

- **Przepływ wieloetapowy budujemy komponentem `Wizard`/`Step`, nie osobnymi stronami
  sklejonymi parametrami URL.** Stan kroków trzyma wtedy formularz, nie query string —
  znika cała klasa błędów „pęknięcie w łańcuchu przekierowań cicho gubi wybraną wartość".
  Wzorzec: [`FisheryResource\Pages\CreateFishery`](../../app/Filament/Resources/FisheryResource/Pages/CreateFishery.php).
- **Przyciski i ikony deklaruje się przez `Action::make()->icon('heroicon-…')`**, nie surowym
  `<svg>` z ręcznie wpisanymi klasami Tailwind. Rozmiar i styl pilnuje wtedy framework.
  ⚠️ **Oba panele ładują jeden wspólny motyw** — `resources/css/filament/theme.css`, rejestrowany
  `viteTheme()` w `AdminPanelProvider` i `OwnerPanelProvider` ([ADR-016](../adr/ADR-016-wlasny-motyw-panelu.md) A).
  Klasy Tailwinda wolno użyć **wyłącznie we własnym widoku Blade, którego nie da się złożyć ze
  standardowych komponentów, i tylko po uzasadnieniu w zadaniu**; domyślnie nadal składamy ekrany
  z komponentów. Klasa trafia do arkusza dzięki `@source` motywu, który obejmuje
  `app/Filament/**` i `resources/views/filament/**` — widok poza tymi katalogami nie dostanie stylów.
  Klasy piszemy w całości, nigdy sklejane z fragmentów (skaner nie wykonuje Blade'a).
  Nadal **bez** surowego `<svg>` i bez `style="…"` tam, gdzie wystarcza komponent.
- **Zakładki na stronie budują `Tabs`/`Tab` ze schematu**, nie ręczny Alpine
  (`x-data="{ activeTab: … }"` + `x-show`). `Tab::badge()` daje licznik bez własnego znacznika.
- **Krok kreatora ma być jedną decyzją użytkownika, nie workiem na wszystko.** W kreatorze
  łowiska pytanie „która firma?" i formularz nowej firmy to **dwa osobne kroki** — krok
  z pytaniem znika, gdy właściciel nie ma jeszcze żadnej firmy, a krok z formularzem, gdy
  wybrał istniejącą. Widoczność kroku steruje się `Step::visible()`, a nie ukrywaniem połowy
  pól wewnątrz jednego kroku; inaczej użytkownik ląduje na gołym formularzu bez kontekstu.
- ⚠️ **Nie polegaj na wartości pola ukrytego kroku.** Komponenty niewidoczne nie muszą trafiać
  do stanu przy zapisie, więc logika po stronie serwera (`handleRecordCreation()`) musi
  wyznaczać ścieżkę jawnie — w kreatorze łowiska po `company_mode`, a nie po tym, czy
  `$data['company']` przypadkiem coś zawiera.
- **Przycisk zapisu kreatora należy do jego ostatniego kroku** (`Wizard::submitAction()`),
  a strona zwraca wtedy z `getFormActions()` co najwyżej „Anuluj". Domyślne akcje
  `CreateRecord` („Utwórz", „Utwórz i utwórz kolejne") renderują się **niezależnie od
  `Wizard`-a**, więc bez tego widać je na każdym kroku — także tam, gdzie nie ma jeszcze
  czego zapisać. „Utwórz i utwórz kolejne" w przepływie krok po kroku nie ma sensu.
- **Kreator kończący proces przekierowuje tam, gdzie użytkownik ma pracować dalej**, a nie
  na listę. Po założeniu łowiska właściciel ląduje na jego stronie zarządzania — to świadome
  odstępstwo od reguły „CRUD wraca na listę" z `CLAUDE.md` (zadanie 011), bo kreator kończy
  zakładanie obiektu, a nie zwykłe dodanie rekordu.
- Powód całej tej sekcji jest empiryczny: kreator i strona zarządzania łowiskiem wymagały
  ręcznej naprawy przy **dwóch kolejnych** upgrade'ach Filamenta, zanim zostały przepisane
  na komponenty natywne.

## 2. Ekrany jednego łowiska to STRONY w sub-nawigacji rekordu

- **Wszystkie ekrany jednego łowiska — listy i ustawienia — są stronami `FisheryResource`**,
  wypisanymi w `getRecordSubNavigation()`. Operator dostaje **jedną** nawigację, bez
  rozróżnienia na „listy" i „konfigurację".
  ⚠️ Kolejność w `getRecordSubNavigation()` jest jedyną definicją kolejności w interfejsie.
  Idzie od tego, co operator ustawia najpierw i najrzadziej zmienia, do tego, co dokłada
  w trakcie sezonu; blokady są ostatnie, bo są reakcją na zdarzenie, nie konfiguracją.
- **Pozycja sub-nawigacji to `Start`, nie `Top`** — decyzja o skalowaniu, nie o guście.
  ⚠️ Zakładki u góry (`.fi-tabs`) to `display:flex; overflow-x:auto` **bez zawijania**,
  a Filament nie ma przełącznika, który by to zmienił; zawijanie wymagałoby własnego CSS-u
  na wewnętrzne klasy frameworka (`fi-*`), a granica z §1 obejmuje własne widoki, nie nadpisywanie
  stylów Filamenta.
  Siedem polskich etykiet już się nie mieściło, a makieta zapowiada około dziesięciu sekcji.
  Lista pionowa rośnie w dół i tego ograniczenia nie ma.
- ⚠️ **Panel właściciela NIE MA paska bocznego** — `OwnerPanelProvider` ustawia
  `topNavigation()`, a układ pomija gałąź paska, gdy `hasTopNavigation()` jest prawdą.
  Sub-nawigacja jest tam więc jedynym paskiem, nie drugim.
  Panel administratora ma własny pasek, dlatego dostał `sidebarCollapsibleOnDesktop()`:
  bez tego przy edycji łowiska dwa paski zjadały szerokość formularza.
- **Oba panele mają pełną szerokość treści** (`maxContentWidth(Width::Full)`): obok tabeli stoi sub-nawigacja, więc domyślne
  80 rem jej nie mieściło. ⚠️ Tabele łowiska i tak nie wolno rozbudowywać o kolejne kolumny — zwarty układ (stan i telefon
  pod nazwiskiem, płatność pod statusem, akcje w menu po LEWEJ stronie kolumn, `RecordActionsPosition::BeforeCells`)
  jest rozwiązaniem dla wąskich ekranów; pełna szerokość go nie zastępuje.
  ⚠️ **Akcje wiersza stoją po LEWEJ stronie kolumn w KAŻDEJ tabeli obu paneli** — ustawia to jedno miejsce,
  `Table::configureUsing()` w `AppServiceProvider`. Nie ustawiaj pozycji akcji per tabela i nie wracaj do prawej strony:
  wyjątek od reguły byłby mylący dla operatora.
- **`ManageFishery` celowo NIE zawiera formularza edycji łowiska.** Jest `ViewRecord`
  z podglądem (`infolist`) i akcją nagłówka „Edytuj" prowadzącą do osobnego formularza.
  ⚠️ **Nie zamieniaj tego na `EditRecord`** — to odwrócenie decyzji z
  [ADR-006](../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md).
- **Listę rekordów podrzędnych renderuje `ManageRelatedRecords`** ze statycznym
  `$relationship`, nie `RelationManager`. ⚠️ `FisheryResource::getRelations()` jest **puste
  i takie ma zostać**.
- **Tabela i formularz strony DELEGUJĄ do właściwego zasobu** (`PositionResource::table($table)`),
  zamiast powielać kolumny i pola. Zasoby podrzędne zachowują własne strony list i tworzenia
  filtrowane przez `?fishery=`.
  ⚠️ **Nic nie linkuje do samodzielnych stron list** — zostały jako cel deep-linku i jako
  fallback w `FisheryNavigation::fisherySectionUrl()`, gdy łowiska nie da się ustalić. Nie usuwaj ich
  w ramach „sprzątania martwego kodu" bez świadomej decyzji.
- ⚠️ **Akcje prowadzą na PEŁNE strony tworzenia i edycji, zwykłą `Action` z `url()`** — nie na
  modale, choć na `ManageRelatedRecords` akcje CRUD-owe działałyby. Powód jest świadomy, nie
  techniczny: pełne strony niosą `FisheryAccess::assertFisheryAccessOrAbort()`,
  `FisheryAccess::forceVerifiedFishery()` przy zapisie i własne przekierowania — modal omijałby te
  trzy warstwy ([`autoryzacja.md`](autoryzacja.md) §4). Widoczność sprawdzaj jawnie
  (`Gate::allows('create', Model::class)`, `Gate::allows('update', $record)`).
  ⚠️ Dotyczy to także akcji **odziedziczonych z delegowanego zasobu** — `recordActions()`
  z `PositionResource` niesie `EditAction`, więc strona musi je nadpisać.
- **Licznik niesie plakietka pozycji sub-nawigacji.** ⚠️ `Page::getNavigationBadge()` nie
  dostaje żadnych parametrów, więc rekordu **nie da się** z niego odczytać inaczej niż
  z `request()->route()` — czego nie da się przetestować. Dlatego strony nadpisują
  `getNavigationItems(array $urlParameters)` i liczą plakietkę z `$urlParameters['record']`,
  a samo liczenie siedzi w publicznej `badgeFor(Fishery $fishery)`, którą woła test.
- ⚠️ **Eager-load trzeba dołożyć osobno.** Strona jedzie po relacji `ownerRecord`, więc **nie**
  przechodzi przez `getEloquentQuery()` zasobu i nie dziedziczy stamtąd `with()`. Ponieważ
  `visible()` akcji wiersza pyta politykę, a ta sięga po `$record->fishery`, brak
  `->modifyQueryUsing(fn ($q) => $q->with('fishery'))` to N+1 na całą tabelę.
- **Zapis i okruszki stron podrzędnych wracają na stronę sekcji, nie na samotną listę** —
  adres składa `FisheryNavigation::fisheryHubUrl($fisheryId, ManageXxx::class)`, czyli **po klasie
  strony** (`Page::getRouteName()`).
  ⚠️ Zniknął parametr `?relation=N` i razem z nim pułapka „przestawienie kolejności zakładek
  przekierowuje zapis na cudzą listę i nic nie pęka". Nie wracaj do adresowania po pozycji.
- **Sub-nawigacja jest na KAŻDEJ stronie rekordu**, także na formularzu edycji łowiska — i to
  jest zamierzone: operator nie wypada z nawigacji łowiska, wchodząc w edycję.
- **Ścieżkę okruszków składa `FisheryNavigation::fisheryBreadcrumbs()`** — jedno źródło dla wszystkich
  stron List/Create/Edit zasobów podrzędnych. Daje `Łowiska › {łowisko} › {sekcja}`
  z klikalnymi dwoma pierwszymi elementami. Nie buduj tej tablicy ręcznie w stronie:
  `getBreadcrumbs()` bez łowiska w środku gubi drogę powrotną, a tego nie widać w żadnym
  teście sprawdzającym tylko status odpowiedzi.

## 3. Formularze współdzielone między panelami

- **Zasoby są współdzielone**: `OwnerPanelProvider` rejestruje te same klasy z
  `app/Filament/Resources/` co panel administratora. Różnicę zachowania robi się przez
  `FisheryAccess::isOwnerPanel()`, **nie** przez flagi w query stringu.
- **Gdy formularz ma wyglądać inaczej w każdym panelu, rozgałęziaj na poziomie strony**, nie
  duplikując pól. Wzorzec: `CreateFishery::form()` zwraca `parent::form()` poza panelem
  właściciela, a kreator tylko w nim; pola pochodzą ze wspólnych metod
  `FisheryResource::fisheryDetailComponents()` i `CompanyResource::formComponents()`.
- ⚠️ **`unique()` osadzone w cudzym formularzu musi mieć jawną tabelę.** Filament wnioskuje ją
  z modelu formularza — pola firmy renderowane wewnątrz kreatora łowiska sprawdzałyby
  unikalność w tabeli `fisheries`. Stąd `->unique(table: Company::class, …)`
  w `CompanyResource::formComponents()`.

### Zdjęcia łowiska (zadanie 036)

- Galeria i mapa w formularzu łowiska (także w kreatorze) to pola medialibrary wspólne dla obu paneli —
  reguły (dysk, typy plików, oczyszczanie na serwerze, kolejność = okładka) są w
  [`panel-admina.md`](panel-admina.md) §1, „Zdjęcia łowiska". Formularz jest osiągalny z otwartej
  samorejestracji, więc ograniczenie typów i oczyszczanie pliku nie są opcjonalne.

### Formularz usługi dodatkowej (zadanie 020)

- **Jednostka rozliczenia to `ToggleButtons`** (za dobę / za pobyt), spójnie z wyborem dób.
  Cena przez `getPriceInput()` z minimum **0,00**; tabela pokazuje cenę z jednostką albo „bezpłatna".
- **Pole „Dostępna na"** (całe łowisko / wybrane stanowiska). Przy „wybranych" bez przypięć formularz
  i lista pokazują ostrzeżenie „dostępna nigdzie". ⚠️ Zmiana na „całe łowisko" przy istniejących
  przypięciach wymaga **zaznaczenia zgody z liczbą przypięć, które znikną** — bez niej zapis nie
  przechodzi; samo odpięcie robi hak modelu.
- **Wymagane cechy** — `Select` wielokrotny z wyłącznie flagami, zapisywany przez bramkę
  `AdditionalServiceSync::syncRequiredAttributes()` wołaną ze stron `Create*`/`Edit*`, **nie**
  `->relationship()`: bramka przepuszcza tylko flagi, zachowuje wymóg cechy usuniętej miękko
  i zapisuje zmianę w dzienniku (relacja wiele-do-wielu nie przechodzi przez `LogsActivity`).
- Repeater usług w formularzu stanowiska oferuje wyłącznie usługi „wybrane stanowiska" — aktywne
  oraz ⚠️ **nieaktywne, które są już przypięte w danym wierszu** (z dopiskiem „nieaktywna").
  Przypięcie usługi nieaktywnej zostaje; bez tego wiersz miałby wartość spoza opcji, a zapis
  stanowiska odpadałby na walidacji albo gubił przypięcie.

## 4. Skrypty w widokach Filamenta

- ⚠️ **`DOMContentLoaded` nie odpala się po nawigacji Livewire (`wire:navigate`)** — listener
  podpięty w ten sposób działa tylko przy pierwszym twardym załadowaniu strony i cicho przestaje
  po dowolnej interakcji. Nie wiąż w ten sposób obsługi zdarzeń.
- **Nie czytaj wartości formularza z DOM-u.** Źródłem prawdy jest stan Livewire; wartości
  wyliczaj serwerowo (`ViewField::viewData()` + pola `live()`) albo przez Alpine na `$wire`.
  Poprzedni podgląd mapy scrape'ował selektory (`.choices__item`, `select#data\.state_id`)
  powiązane z biblioteką stylującą select i przestał działać, gdy Filament zmienił znaczniki.
  Wzorzec po naprawie: [`resources/views/filament/forms/map-preview.blade.php`](../../resources/views/filament/forms/map-preview.blade.php),
  pilnowany przez [`tests/Feature/FisheryMapPreviewTest.php`](../../tests/Feature/FisheryMapPreviewTest.php).
- ⚠️ **Nie trzymaj stanu UI w `x-data` wewnątrz komponentu, który sąsiaduje z polami `live()`.**
  Każda ich aktualizacja przerenderowuje fragment, a Alpine odtwarza `x-data` od wartości
  początkowych — przełącznik „pokaż/ukryj" wraca wtedy do stanu zamkniętego zaraz po kliknięciu.
  Objaw jest widoczny **wyłącznie w przeglądarce**: serwerowy HTML wygląda poprawnie, więc
  test asertujący treść odpowiedzi tego nie złapie. Jeśli widoczność da się policzyć z danych
  (jak kompletność adresu dla mapy), policz ją serwerowo zamiast dokładać stan.
- ⚠️ **Reguła walidacyjna będąca domknięciem musi być OPAKOWANA w domknięcie, które ją zwraca.**
  Filament woła `evaluate()` na każdym elemencie `rules()` i wstrzykuje argumenty po nazwie,
  więc reguła Laravela przekazana wprost wywala `BindingResolutionException:
  [$attribute] was unresolvable` — **dopiero przy wysłaniu formularza**, nie przy jego otwarciu.
  ```php
  ->rules([
      static fn (): Closure => static function (string $attribute, $value, Closure $fail): void {
          // …
      },
  ])
  ```
  Wzorzec: `SharedFormComponents::getPriceInput()`, pilnowany przez
  [`tests/Feature/AdditionalServicePriceTest.php`](../../tests/Feature/AdditionalServicePriceTest.php).
- ⚠️ **Testując zapisane wartości, uważaj na akcesory formatujące.** `AdditionalService::price`
  ma akcesor zamieniający kropkę na przecinek przy locale `pl`, a Eloquentowy `value()`
  przepuszcza wartość przez akcesor — `(float) $model->price` da wtedy `49.0` zamiast `49.5`
  i test skłamie o utracie danych, której nie ma. Do asercji o tym, co **naprawdę** trafiło
  do bazy, używaj `DB::table(...)->value(...)`.
- **Repeatery startują od zera pozycji, gdy pole w wierszu jest wymagane.** Filament domyślnie
  renderuje jedną pozycję (`defaultItems(1)`); jeśli w środku jest `required()`, formularz
  **nie da się zapisać** bez ręcznego usunięcia pustego wiersza. Patrz
  `PositionResource` (usługi dodatkowe) i
  [`tests/Feature/PositionAdditionalServicesTest.php`](../../tests/Feature/PositionAdditionalServicesTest.php).

## 5. Testy kreatora i stron panelu właściciela

- ⚠️ **`Livewire::test()` montuje komponent POZA kontekstem panelu** — `Filament::getCurrentOrDefaultPanel()`
  zwraca wtedy panel **domyślny** (`admin`), więc `FisheryAccess::isOwnerPanel()` jest fałszem.
  Bez jawnego `Filament::setCurrentPanel('owner')` testujesz wariant administratora, myśląc,
  że sprawdzasz panel właściciela — i test przechodzi, nie sprawdzając niczego z tego, co miał.
  Wzorzec: [`tests/Feature/FisheryWizardTest.php`](../../tests/Feature/FisheryWizardTest.php).
- ⚠️ **Strony sekcji testuj przez `Livewire::test($sectionPage, ['record' => $fishery->getKey()])`** —
  to strony REKORDU (`ManageRelatedRecords`), a nie osadzone komponenty relacji.
  `assertCanSeeTableRecords()` samo wymusza doładowanie tabeli, ale surowy `->html()` **nie** —
  wtedy potrzebne jest `->call('loadTable')`. Bez tego probe pokazuje „brak przycisku"
  i „brak wierszy" tam, gdzie w przeglądarce wszystko jest.
- ⚠️ **`assertDontSee()` na krótkim polskim słowie wymaga danych ustawionych WPROST.**
  `faker_locale` to `pl_PL`, a nazwy z fabryk lądują na stronie (nazwisko użytkownika w pasku
  Filamenta, nazwy firm i łowisk w tabelach). „Krajewski" zawiera „Kraje", czyli tłumaczenie
  `__('Countries')` — zmierzone **45 kolizji na 5000 losowań**, czyli test czerwieni się mniej
  więcej raz na sto przebiegów pakietu i wygląda wtedy na losową awarię środowiska.
  W testach porównujących „widać moje, nie widać cudzego" ustawiaj nazwy jawnie
  (`create(['name' => 'Lowisko Wlasne XYZ'])`), zamiast liczyć na to, że dwa losowania nie
  będą swoimi podciągami.
- **Kroki kreatora sprawdzaj po klasie `fi-sc-wizard-header-step-label`** (prefiks `fi-sc-`
  to komponent schematu). Szukanie `fi-wizard` niczego nie znajdzie i wygląda jak brak
  kreatora, choć nagłówek jest renderowany poprawnie.
- **Testuj też ścieżki „pomiędzy" wariantami, nie tylko skrajne.** Kreator łowiska przeszedł
  komplet testów dla właściciela **z** firmą i **bez** firmy, a mimo to gubił przypadek
  „mam firmy, ale zakładam kolejną" — czyli ten, w którym stary `company_id` najłatwiej
  przykryje świeżo utworzoną firmę.
- ⚠️ **Przenosząc stronę pod nowy adres, sprawdź WEJŚCIA do niej, nie tylko ją samą.**
  Testy wchodzące wprost na URL nie zobaczą, że przyciski i linki prowadzą gdzie indziej —
  zwłaszcza gdy stary adres **nadal istnieje i zwraca 200** (jak `companies/create` po
  przeniesieniu kreatora). Wtedy nie pęka nic: ani testy, ani `view:cache`, ani analiza
  statyczna. Pilnuje tego
  [`tests/Feature/FisheryWizardEntryPointsTest.php`](../../tests/Feature/FisheryWizardEntryPointsTest.php);
  przy sprzątaniu po refaktorze grepuj także po **trasach**, nie tylko po nazwach usuwanych klas.

---

## 6. Ekran ustawień jednego łowiska

Przeniesione do [`konfiguracja-lowiska.md`](konfiguracja-lowiska.md) §6 (numeracja zachowana, żeby odsyłacze „`panel-wlasciciela.md` §6" w kodzie i zadaniach nadal prowadziły do właściwej reguły).

---

## 7. Doba wędkarska, okresy sprzedaży i dostępność

Przeniesione do [`konfiguracja-lowiska.md`](konfiguracja-lowiska.md) §7 (numeracja zachowana, żeby odsyłacze „`panel-wlasciciela.md` §7" w kodzie i zadaniach nadal prowadziły do właściwej reguły).

---

## 8. Stanowiska: stan, pojemność, grupy i cechy

Przeniesione do [`konfiguracja-lowiska.md`](konfiguracja-lowiska.md) §8 (numeracja zachowana, żeby odsyłacze „`panel-wlasciciela.md` §8" w kodzie i zadaniach nadal prowadziły do właściwej reguły).

---

## 9. Dokumenty łowiska i polityka zwrotu (zadanie 021)

Przeniesione do [`konfiguracja-lowiska.md`](konfiguracja-lowiska.md) §9 (numeracja zachowana, żeby odsyłacze „`panel-wlasciciela.md` §9" w kodzie i zadaniach nadal prowadziły do właściwej reguły).

---

## 10. Publikacja łowiska w portalu i dane dla portalu (zadanie 030)

Przeniesione do [`konfiguracja-lowiska.md`](konfiguracja-lowiska.md) §10 (numeracja zachowana, żeby odsyłacze „`panel-wlasciciela.md` §10" w kodzie i zadaniach nadal prowadziły do właściwej reguły).

---

## 11. Rezerwacje

Reguły ekranów rezerwacji (pozycja w sub-nawigacji, formularz, modale, plakietka) żyją w
[`rezerwacje.md`](rezerwacje.md) — wydzielone przy zadaniu 041, żeby ten plik nie przekraczał limitu.
⚠️ Najważniejsze dla tego panelu: „Rezerwacje" stoją zaraz za „Kalendarzem", a krótkie akcje na
rezerwacji są modalami — świadome, opisane tam odstępstwo od §2.
