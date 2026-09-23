# Konwencje: panel właściciela

Obowiązuje przy zmianach w `app/Filament/Owner/**`,
`app/Providers/Filament/OwnerPanelProvider.php` oraz w zasobach współdzielonych,
gdy dotykasz ich zachowania **w panelu właściciela**.

Zadania źródłowe: 012, 013, 014, 015, 016. Uzasadnienia w [ADR-006](../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md)
i [ADR-010](../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md).

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
  na wewnętrzne klasy frameworka, a projekt nie rejestruje `viteTheme()` w żadnym panelu.
  Siedem polskich etykiet już się nie mieściło, a makieta zapowiada około dziesięciu sekcji.
  Lista pionowa rośnie w dół i tego ograniczenia nie ma.
- ⚠️ **Panel właściciela NIE MA paska bocznego** — `OwnerPanelProvider` ustawia
  `topNavigation()`, a układ pomija gałąź paska, gdy `hasTopNavigation()` jest prawdą.
  Sub-nawigacja jest tam więc jedynym paskiem, nie drugim.
  Panel administratora ma własny pasek, dlatego dostał `sidebarCollapsibleOnDesktop()`:
  bez tego przy edycji łowiska dwa paski zjadały szerokość formularza.
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

Obok stron listowych (`ManageRelatedRecords`) w sub-nawigacji żyją **strony ustawień** —
formularze konfiguracji łowiska zapisywane jednym „Zapisz". Dziś są to „Sprzedaż i sezony"
oraz „Reguły sprzedaży"; makieta zapowiada kolejne (Cennik, Zwroty, Regulamin).

- **Jedno pytanie na ekran** — i to jest kryterium podziału, nie objętość formularza.
  „Sprzedaż i sezony" odpowiada **KIEDY** sprzedajesz (doba, strefa, okresy sprzedaży
  z przedsprzedażą, horyzont), „Reguły sprzedaży" — **JAKI POBYT** wolno kupić (długość, weekend
  i święta sprzedawane w całości). Kolejność w `getRecordSubNavigation()`: reguły **za** sezonami,
  bo reguły odwołują się do godzin doby i do okresów, a nie odwrotnie.

- **Strona ustawień to `EditRecord` zasobu `FisheryResource`** z własną pozycją w
  `getRecordSubNavigation()`. Nie jest RelationManagerem i nie jest stroną panelu.
- **Rekordy podrzędne bez własnego życia renderuje `Repeater`, nie osobny zasób CRUD.**
  Okres sprzedaży ma dwie daty i nazwę, więc trzy strony CRUD byłyby kosztem bez pokrycia.
  Osobny zasób należy się rekordowi, do którego prowadzi deep-link albo który ma własne akcje.
- ⚠️ **Zadeklaruj `->columns(1)` na schemacie, jeśli sekcje mają iść jedna pod drugą.**
  `EditRecord::defaultForm()` narzuca `columns(2)`, o ile schemat sam nie zadeklaruje kolumn —
  bez tego dwie sekcje stają obok siebie, a repeater ze swoimi kolumnami dostaje połowę
  szerokości i jest ściśnięty.
- ⚠️ **Pole kryterium, które nie jest kolumną, musi mimo to dojechać do
  `mutateFormDataBefore*`.** Nie zdejmuj go `->dehydrated(false)` — klucz usuwa się jawnie
  w metodzie mutującej. Inaczej wartość wyliczana z tego pola (np. `selection_label`) zapisuje
  się cicho jako `null`, a formularz nie zgłasza żadnego błędu.
- ⚠️ **Przełącznik, który NIE jest kolumną: stan wynika z danych, wyłączenie czyści dane.**
  Dotyczy dziś przełącznika weekendu (`weekend_days` niepuste) i przedsprzedaży okresu (obie daty
  okna wypełnione). Osobnej kolumny `*_enabled` nie ma i mieć nie ma — dwie prawdy o tym samym
  rozjeżdżają się przy pierwszym zapisie z pominięciem formularza. Wyłączenie **musi wyczyścić**
  pola, inaczej wartość zostaje w bazie i dalej działa, a formularz pokazuje ją jako wyłączoną.
- ⚠️ **Repeater po relacji NIE przechodzi przez `mutateFormDataBefore*` strony.** Filament zapisuje
  go w `saveRelationships()` z własnego stanu, więc pole-przełącznik w wierszu repeatera dokłada się
  i zdejmuje w **hookach repeatera** (`mutateRelationshipDataBeforeFill/Create/SaveUsing`), nie
  w metodach strony. Wzorzec: przedsprzedaż w `ManageSaleSettings`.
  Skutek dla testów: `fillForm(['salePeriods' => [[...]]])` z listą pod kluczem `0` **nie trafia**
  w istniejący wiersz (Filament dopasowuje je po kluczach stanu, `record-<id>`) — test podmieniający
  całą listę zostawia stary rekord nietknięty i zielenieje na zepsutym kodzie. Przestawiaj pole po
  kluczu wiersza.
- ⚠️ **Sekcja zależna od innego ekranu jest WYŁĄCZONA, dopóki tamten nie jest wypełniony.**
  Bez godzin doby nie ma z czego policzyć przedziałów dób ani podpowiedzi „czyli pobyt", a walidacja
  zwróciłaby mylący powód — dlatego na „Regułach sprzedaży" przełącznik weekendu i sekcja świąt
  są wtedy nieaktywne, z podpowiedzią gdzie to ustawić.
- ⚠️ **Doby pokazuj jako PRZEDZIAŁY liczone z godzin doby łowiska** („pt → sob · 15:00 → 15:00"),
  nigdy jako same nazwy dni. To zabezpieczenie, nie ozdoba: przy nazwach dni operator zaznacza
  „piątek, sobotę i niedzielę" dla weekendu, który składa się z **dwóch** dób. Ten sam zabieg przy
  świętach (`Placeholder` „czyli pobyt"), liczony przez `FishingDayCalendar`, nigdy różnicą dat.
- **Autoryzacja jest jawna.** `EditRecord::authorizeAccess()` pyta `FisheryPolicy::update()`,
  a wiązanie rekordu przechodzi przez `FisheryResource::getEloquentQuery()` z `forCurrentUser()`.

Uzasadnienie i odrzucone warianty:
[ADR-006, aktualizacja z zadania 016](../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md).

---

## 7. Doba wędkarska, okresy sprzedaży i dostępność

Reguły sprzedaży **nie należą do panelu** — wiążą tak samo portal wędkarza, cennik i kalendarz.
Mieszkają w [`dostepnosc.md`](dostepnosc.md): doba jako przedział, asymetria reguł granic, jedno
źródło prawdy o dostępności (`PositionAvailability`), blokady ze zmaterializowanym zbiorem oraz
pojęcie **pobytu** ze spoiwem dób (`StaySellability`, §4 tamtego pliku).
Tutaj zostaje wyłącznie to, co dotyczy ekranów panelu:

- **Pola doby żyją POZA `FisheryResource::fisheryDetailComponents()`** — metoda jest współdzielona
  z krokiem „Fishery" kreatora, a łowisko ma powstawać niesprzedające.
- **Ekrany „Sprzedaż i sezony" oraz „Reguły sprzedaży" są stronami ustawień** (sekcja 6), nie
  RelationManagerami. Przedsprzedaż jest **polem w wierszu repeatera okresów**, nie osobną sekcją
  ani osobnym ekranem — bo jest właściwością okresu.
- ⚠️ **Ekran reguł nie liczy sprzedawalności.** Ostrzeżenia przy zapisie („minimum dłuższe niż
  weekend", „maksimum krótsze niż pakiet", „przedsprzedaż bez horyzontu") są jedynym miejscem,
  w którym panel wypowiada się o regułach — i są **ostrzeżeniami**, nie błędami, bo operator
  porządkuje sezon w dowolnej kolejności. Werdykt liczy `StaySellability`.
- **Blokady i ograniczenia są stroną sekcji** (lista rekordów z własnymi stronami). Formularz
  **prowadzi przez wybór zbioru**: sposób wyboru → kryterium → lista objętych stanowisk
  z licznikiem i przyciskiem „Przelicz". Lista jest edytowalna po przeliczeniu.
- ⚠️ **`CreatePosition` ostrzega, gdy nowe stanowisko nie wchodzi do trwającej blokady całościowej.**
  To skutek materializowania zbioru, nie błąd — bez ostrzeżenia nowe stanowisko sprzedaje się
  w środku zamknięcia całego łowiska.
- ⚠️ **Pola kryterium (`position_group_id`, `selection_attribute_id`) NIE są kolumnami**, ale muszą
  dojechać do `mutateFormDataBefore*` — z nich powstaje `selection_label`. Nie zdejmuj ich
  `->dehydrated(false)`; klucze usuwa `AvailabilityBlockResource::withSelectionLabel()`.

---

## 8. Stanowiska: stan, pojemność, grupy i cechy

- **Stanowisko ma STAN WŁASNY (`status`: `available` / `withdrawn`), nie przełącznik.** To decyzja
  operatora, czy miejsce jest w sprzedaży.
  ⚠️ **Nie mylić z dostępnością w terminie** — ta zależy od czasu, składa ją zadanie 016 z blokad
  i okresów sprzedaży, i celowo NIE odzwierciedla jej ani plakietka zakładki, ani zakres
  `Position::available()`. W `positions` nie ma żadnej kolumny dat ani przyczyn.
- **Etykieta stanowiska jest unikalna w obrębie łowiska**, a indeks obejmuje także wiersze usunięte
  miękko — etykieta wycofanego stanowiska **nie wraca do obiegu**. To cel, nie efekt uboczny:
  historia pozwoleń nie ma zacząć wskazywać na „to samo" stanowisko.
  ⚠️ Fabryka stanowisk musi generować etykiety unikalne, inaczej pakiet czerwienieje losowo
  i wygląda to na awarię środowiska.
- **Grupa stanowisk jest ETYKIETĄ w relacji wiele-do-wielu**, nie poziomem hierarchii. Nie niesie
  cech, dojazdu ani stanu, więc nie ma dziedziczenia ani reguły „co wygrywa", a łowisko nieużywające
  grup zachowuje się dokładnie jak przed ich wprowadzeniem. Grupa **nie jest jednostką sprzedaży** —
  kupuje się stanowisko.
- **Wygodę ustawiania cech hurtem daje AKCJA ZBIORCZA, nie dziedziczenie.** Zapisuje wartość wprost
  na każdym stanowisku, więc po wykonaniu każde niesie własny wiersz i nic nie jest rozwiązywane
  przy odczycie. Akcja z poziomu grupy jest **skrótem do tego samego kodu** z zaznaczeniem
  wypełnionym stanowiskami grupy — jeden schemat pól, jedna metoda zapisu, dwa wejścia.
  ⚠️ Nie dorabiaj wariantu „ustaw wszystkim" bez wskazania wartości: to dziedziczenie tylnymi
  drzwiami, tylko niewidoczne.
- **Dziennik zmian dostaje N wpisów, po jednym na stanowisko.** Wpis opisuje zmianę atrybutów
  jednego rekordu i ten niezmiennik zostaje — pytanie „co się działo z TYM stanowiskiem" jest
  zadawane najczęściej.
- ⚠️ **Formularz cech GENERUJE SIĘ ZE SŁOWNIKA** (`position_attributes`), pod kluczem stanu
  `position_attributes.{id}`. Nie `attributes` — to koliduje z magiczną właściwością Eloquenta
  i zapis nadpisywałby model. Klucz musi wypaść z tablicy przed `fill()`, bo nie jest kolumną.
- ⚠️ **Brak wiersza wartości to TRZECI STAN**, nie „nie". Cecha niewypełniona znaczy „nikt się nie
  wypowiedział" i nie bierze udziału w filtrowaniu w żadną stronę — dlatego flaga jest `Select`
  z pustą opcją, a nie `Toggle`, który zawsze niesie fałsz, a wyczyszczenie wartości **kasuje
  wiersz** zamiast zapisywać fałsz.
- **Klucz obcy do łowiska: grupa ma go WYMAGANY z kaskadą, stanowisko nadal `nullable` z `set null`.**
  Różnica jest celowa — stanowisko po odcięciu wciąż wisi w tabelach pośrednich pozwoleń i usług,
  więc coś znaczy; grupa po odcięciu nie znaczy nic.

Uzasadnienie kształtu wartości cech: [ADR-011](../adr/ADR-011-ksztalt-wartosci-cech-stanowiska.md).

### Ekran podglądowy obok stron ustawień (zadanie 019)

Sub-nawigacja łowiska niesie dziś dwa rodzaje ekranów i warto je odróżniać:

- **strony ustawień** — formularze zapisujące konfigurację (`ManageSaleSettings`, `ManageSaleRules`,
  `ManagePricing`, …);
- **ekran podglądowy** — `ManageCalendar`, który **niczego nie zapisuje** i pokazuje SKUTEK
  ustawień. Stoi **za „Cennikiem", przed „Stanowiskami"**: trzy ekrany konfiguracji, a zaraz po
  nich to, co z nich wynika.

⚠️ **Podgląd woła WARSTWĘ OFERTY, nigdy warstwy niższe.** `SaleCalendar` nie dotyka
`StaySellability`, `PositionAvailability` ani wyceny wprost — inaczej stałby się drugim miejscem
składania odpowiedzi, czyli dokładnie tym, czego zakazują ADR-013 i ADR-015. Ta sama zasada
obowiązuje każdy przyszły ekran podglądowy.

⚠️ **Ekran podglądowy nie buforuje werdyktu** ([`dostepnosc.md`](dostepnosc.md) §2). Wolno wyłącznie
to, co buforem nie jest: jedna instancja warstwy oferty na stanowisko, jedno wczytanie cennika
i blokad na cały render, diagnostyka cennika pobierana raz na okno. Pomiar dla 806 komórek: 191
zapytań, ≈ 876 ms — patrz zadanie 019.

⚠️ **Strona podglądowa dziedziczy po `Page`, więc NIE MA z definicji pojęcia rekordu.** Potrzebuje
cechy `InteractsWithRecord` (to z niej biorą się `resolveRecord()` i `getRecord()`), a autoryzację
wykonuje **jawnie** — `abort_unless(... ->can('view', $record), 404)`. Samo zawężenie zapytania
w `resolveRecord()` też broniłoby dostępu, ale niejawnie, jako uboczny skutek zakresu widoczności;
reguła dostępu ma być widoczna w kodzie strony ([`autoryzacja.md`](autoryzacja.md) §5).

⚠️ **Widok siatki używa stylów wpisanych wprost, a nie klas Tailwinda** — i to jest stan
TYMCZASOWY, nie wzorzec. Powód w §1: panel nie rejestruje własnego motywu, więc klasa użyta
w widoku nie ma skąd wziąć CSS-u. Rejestracja motywu okazała się wymagać migracji potoku zasobów
z Tailwinda 3 na 4 (patrz [ADR-016](../adr/ADR-016-wlasny-motyw-panelu.md) i uwaga w zadaniu 019),
więc czeka na własne zadanie. Po jego wykonaniu ten widok przepisuje się na klasy.

