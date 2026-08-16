# Konwencje: panel właściciela

Obowiązuje przy zmianach w `app/Filament/Owner/**`,
`app/Providers/Filament/OwnerPanelProvider.php` oraz w zasobach współdzielonych,
gdy dotykasz ich zachowania **w panelu właściciela**.

Zadania źródłowe: 012. Uzasadnienia w [ADR-006](../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md).

---

## 1. Natywne komponenty Filamenta zamiast ręcznych zamienników

- **Przepływ wieloetapowy budujemy komponentem `Wizard`/`Step`, nie osobnymi stronami
  sklejonymi parametrami URL.** Stan kroków trzyma wtedy formularz, nie query string —
  znika cała klasa błędów „pęknięcie w łańcuchu przekierowań cicho gubi wybraną wartość".
  Wzorzec: [`FisheryResource\Pages\CreateFishery`](../../app/Filament/Resources/FisheryResource/Pages/CreateFishery.php).
- **Przyciski i ikony deklaruje się przez `Action::make()->icon('heroicon-…')`**, nie surowym
  `<svg>` z ręcznie wpisanymi klasami Tailwind. Rozmiar i styl pilnuje wtedy framework.
  ⚠️ W tym projekcie **żaden panel nie rejestruje własnego motywu** (`viteTheme()`), więc klasy
  Tailwind użyte wewnątrz stron Filamenta **nie mają skąd wziąć CSS-u** — ręcznie stylowany
  komponent wygląda poprawnie tylko przypadkiem i rozsypuje się przy pierwszym upgradzie.
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

## 2. Strona zarządzania zasobami podrzędnymi to hub, nie ekran edycji

- **`ManageFishery` celowo NIE zawiera formularza edycji łowiska.** Jest `ViewRecord`:
  pierwsza zakładka „Dane łowiska" to podgląd (`infolist`) z akcją nagłówka „Edytuj",
  prowadzącą do osobnego formularza. ⚠️ **Nie zamieniaj tego na `EditRecord`** — hub
  przestanie być hubem, a to odwrócenie decyzji z
  [ADR-006](../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md).
- **Zakładki z zasobami podrzędnymi budują RelationManagery** z
  `app/Filament/Resources/FisheryResource/RelationManagers/`, a kolejność (dane łowiska jako
  pierwsze, potem trzy listy) daje wbudowane
  `hasCombinedRelationManagerTabsWithContent()` + `getContentTabLabel()` — nie własne `Tabs`
  ani Alpine.
- **Tabela i formularz RelationManagera DELEGUJĄ do właściwego zasobu**
  (`PositionResource::table($table)`), zamiast powielać kolumny i pola. Dzięki temu zakładka
  i samodzielna strona listy pokazują to samo. Zasoby podrzędne zachowują własne strony list
  i tworzenia filtrowane przez `?fishery=` — RelationManagery ich **nie** zastępują.
  ⚠️ Uwaga: po zadaniu 012 **nic już nie linkuje do samodzielnych stron list** — hub pokazuje
  tabele wprost, a okruszki i przekierowania po zapisie prowadzą na zakładkę huba. Te strony
  zostały jako cel deep-linku i jako fallback w `Helper::fisherySectionUrl()`, gdy łowiska
  nie da się ustalić. Nie usuwaj ich w ramach „sprzątania martwego kodu" bez świadomej decyzji.
- ⚠️ **W zakładkach huba akcje CRUD-owe Filamenta NIE DZIAŁAJĄ — używaj zwykłych `Action`.**
  `RelationManager::isReadOnly()` zwraca prawdę na stronie `ViewRecord` (a hub nią jest,
  bo taki jest domyślny tryb panelu dla RelationManagerów na stronach podglądu), a autoryzacja
  odmawia **po klasie akcji**: `CreateAction`, `EditAction`, `DeleteAction`, `AttachAction`
  i pokrewne. Efekt jest cichy — akcja wypada z HTML-a bez błędu i bez wpisu w logu, więc
  „nie ma przycisku" wygląda jak problem ze stylami. Zwykła `Action` nie jest na tej liście,
  więc przechodzi. Widoczność sprawdzaj wtedy jawnie (`Gate::allows('create', Model::class)`,
  `Gate::allows('update', $record)`), a `url()` kieruj na pełną stronę tworzenia/edycji.
  ⚠️ Dotyczy to także akcji **odziedziczonych z delegowanego zasobu** — `recordActions()`
  z `PositionResource` niesie `EditAction`, więc RelationManager musi je nadpisać, inaczej
  z zakładki nie da się wejść w edycję.
- ⚠️ **`Resource::getRelations()` obowiązuje WSZYSTKIE strony zasobu**, więc bez
  `canViewForRecord()` zwracającego `$pageClass === ManageFishery::class` listy doklejają się
  także do formularza edycji łowiska.
- **Liczniki aktywnych pozycji niesie `getBadge()` RelationManagera**, nie ręczne `Tab::badge()`.
- ⚠️ **Eager-load w RelationManagerze trzeba dołożyć osobno.** RelationManager jedzie po relacji
  `ownerRecord`, więc **nie** przechodzi przez `getEloquentQuery()` zasobu i nie dziedziczy
  stamtąd `with()`. Ponieważ `visible()` akcji wiersza pyta politykę, a ta sięga po
  `$record->fishery`, brak `->modifyQueryUsing(fn ($q) => $q->with('fishery'))` to N+1 na całą
  tabelę — dokładnie ten przypadek, przed którym ostrzega `CLAUDE.md`.
- **Z zakładki nie da się usunąć rekordu i to jest stan zamierzony.** `DeleteAction`
  i `DeleteBulkAction` przepadają w trybie read-only tak samo jak edycja; Filament ukrywa wtedy
  całą grupę akcji masowych razem z kolumną zaznaczeń, więc nie zostaje nieklikalny element.
  Kasowanie żyje na stronie edycji. Przeniesienie go do zakładki wymaga zwykłej `Action`
  z `requiresConfirmation()` i jawnym `Gate::allows('delete', $record)`.
- **Zapis i okruszki stron podrzędnych wracają na zakładkę huba, nie na samotną listę** —
  adres składa `Helper::fisheryHubUrl($fisheryId, XRelationManager::class)`.
  ⚠️ Filament identyfikuje zakładkę **pozycją** w `FisheryResource::getRelations()`
  (parametr `?relation=`), nie nazwą klasy. Nigdy nie wpisuj tego numeru ręcznie:
  przestawienie kolejności zakładek przekierowywałoby po zapisie na cudzą listę i **nic by
  nie pękło**. Ta reguła zastępuje wcześniejszy powrót na `getUrl('index', ['fishery' => …])`
  z zadania 011 — dotyczy stanowisk, usług dodatkowych i pozwoleń w **obu** panelach, bo
  zasoby są współdzielone.
- **Ścieżkę okruszków stron podrzędnych składa `Helper::fisheryBreadcrumbs()`** — jedno źródło
  dla wszystkich dziewięciu stron (List/Create/Edit × stanowiska, usługi, pozwolenia). Daje
  `Łowiska › {łowisko} › {sekcja}` z klikalnymi dwoma pierwszymi elementami. Nie buduj tej
  tablicy ręcznie w stronie: `getBreadcrumbs()` bez łowiska w środku gubi drogę powrotną
  do huba, a tego nie widać w żadnym teście sprawdzającym tylko status odpowiedzi.

## 3. Formularze współdzielone między panelami

- **Zasoby są współdzielone**: `OwnerPanelProvider` rejestruje te same klasy z
  `app/Filament/Resources/` co panel administratora. Różnicę zachowania robi się przez
  `Helper::isOwnerPanel()`, **nie** przez flagi w query stringu.
- **Gdy formularz ma wyglądać inaczej w każdym panelu, rozgałęziaj na poziomie strony**, nie
  duplikując pól. Wzorzec: `CreateFishery::form()` zwraca `parent::form()` poza panelem
  właściciela, a kreator tylko w nim; pola pochodzą ze wspólnych metod
  `FisheryResource::fisheryDetailComponents()` i `CompanyResource::formComponents()`.
- ⚠️ **`unique()` osadzone w cudzym formularzu musi mieć jawną tabelę.** Filament wnioskuje ją
  z modelu formularza — pola firmy renderowane wewnątrz kreatora łowiska sprawdzałyby
  unikalność w tabeli `fisheries`. Stąd `->unique(table: Company::class, …)`
  w `CompanyResource::formComponents()`.

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
  Wzorzec: `Helper::getPriceInput()`, pilnowany przez
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
  zwraca wtedy panel **domyślny** (`admin`), więc `Helper::isOwnerPanel()` jest fałszem.
  Bez jawnego `Filament::setCurrentPanel('owner')` testujesz wariant administratora, myśląc,
  że sprawdzasz panel właściciela — i test przechodzi, nie sprawdzając niczego z tego, co miał.
  Wzorzec: [`tests/Feature/FisheryWizardTest.php`](../../tests/Feature/FisheryWizardTest.php).
- ⚠️ **Zawartości zakładek huba NIE zobaczysz w GET-cie strony.** Przy zakładkach połączonych
  z treścią pierwsze żądanie renderuje tylko zakładkę „Dane łowiska", a listy dociąga Livewire
  po kliknięciu (`isTableLoaded: false`). Testuj RelationManagery przez
  `Livewire::test($manager, ['ownerRecord' => …, 'pageClass' => ManageFishery::class])`;
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
