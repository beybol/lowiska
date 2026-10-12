# Konwencje: ekrany konfiguracji łowiska

Obowiązuje przy zmianach w stronach konfiguracji jednego łowiska — `FisheryResource\Pages\Manage*` (ustawienia sprzedaży,
reguły, stanowiska i grupy, cechy, dokumenty, polityka zwrotu, publikacja) — w obu panelach.

Wydzielone 12.10.2026 z [`panel-wlasciciela.md`](panel-wlasciciela.md) (limit ~40 KB). **Numeracja sekcji §6–§10 jest
zachowana** — odsyłacze „`panel-wlasciciela.md` §N" w kodzie i w zrealizowanych zadaniach prowadzą przez krótkie
odsyłacze w tamtym pliku tutaj. Reguły ogólne panelu (natywne komponenty, sub-nawigacja, formularze współdzielone,
skrypty, testy) zostają w `panel-wlasciciela.md` §1–§5.

Zadania źródłowe: 014, 015, 016, 019, 020, 021, 030. Uzasadnienia w [ADR-006](../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md)
i [ADR-010](../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md).

---

## 6. Ekran ustawień jednego łowiska

Obok stron listowych (`ManageRelatedRecords`) w sub-nawigacji żyją **strony ustawień** —
formularze konfiguracji łowiska zapisywane jednym „Zapisz". Dziś są to „Sprzedaż i sezony"
oraz „Reguły sprzedaży"; makieta zapowiada kolejne (Cennik, Zwroty, Regulamin).

- **Jedno pytanie na ekran** — i to jest kryterium podziału, nie objętość formularza.
  „Sprzedaż i sezony" odpowiada **KIEDY** sprzedajesz (doba, strefa, okresy sprzedaży
  z przedsprzedażą, horyzont, a od 045 także sekcja „Rezerwacje": potwierdzanie rezerwacji ręcznej
  i publiczna zajętość — [`rezerwacje.md`](rezerwacje.md) §5), „Reguły sprzedaży" — **JAKI POBYT** wolno kupić (długość, weekend
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
- ⚠️ **Wybór dób tygodnia to JEDEN komponent** — `SharedFormComponents::weekdayNightsInput()`,
  a wiedza o dobach (nazwa dnia, przedział, godziny, podsumowanie, krótka forma do nagłówka)
  mieszka wyłącznie w `WeekdayNights`. Korzystają z niego weekend na „Regułach sprzedaży" i warunek
  dopłaty w „Cenniku". Kształt: rząd siedmiu chipów `ToggleButtons` (dwuwierszowych: przedział
  i godziny), pod nimi podsumowanie jako lista maksymalnych ciągów cyklicznych z łączną liczbą dób.
  Nie wracaj do `CheckboxList`, do samych nazw dni ani do własnej listy opcji na stronie.
  - Etykieta chipa jest `HtmlString`, więc **każda wartość przechodzi przez `e()`**.
  - Bez godzin doby chip traci linię godzin. Weekend jest wtedy wyłączony (sekcja zależna
    od innego ekranu), ale **dopłata nie** — wybiera doby po dniu rozpoczęcia.
  - Chipy przysyłają łańcuchy; rzutowanie na `int` robi strona w miejscu, gdzie powstaje
    zapisywany stan.
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
- **Usługę przypina się do stanowisk AKCJĄ ZBIORCZĄ, nie przez grupę** — „Przypnij usługę"
  i „Odepnij usługę" (zadanie 020), ten sam prymityw co „Ustaw cechę": dwa wejścia (zaznaczenie
  w tabeli stanowisk i skrót z poziomu grupy — także na stronie grup łowiska), jeden schemat pól
  i jeden kod zapisu. Przypięcie ląduje **wprost** na każdym stanowisku jako **dopisanie**, nie
  `sync()` całej listy — inne przypięcia zostają, a istniejące dostaje nową wartość `is_required`.
  Po przypięciu akcja ostrzega, na ilu stanowiskach usługa jest martwa przez brak wymaganej cechy.
  Każde zmienione stanowisko dostaje **wpis w dzienniku zmian** z listą przypięć przed i po.
  ⚠️ Przypisania usługi do grupy nie ma i mieć nie ma — działałoby jak dziedziczenie (F5, O11).
  Jawna autoryzacja: `update` na każdym stanowisku i na usłudze (bramka `AdditionalServiceSync`),
  a skrót z grupy dodatkowo `update` na grupie.
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
- **ekran podglądowy** — warstwa **„Oferta"** `ManageCalendar` (`?layer=offer`), która **niczego nie
  zapisuje** i pokazuje SKUTEK ustawień. Kalendarz stoi **za „Cennikiem"**: trzy ekrany konfiguracji,
  a zaraz po nich to, co z nich wynika. Od zadania 042 ten sam ekran ma drugą, domyślną warstwę
  **„Rezerwacje"** — operacyjną ([`rezerwacje.md`](rezerwacje.md) §2); reguły tej sekcji dotyczą warstwy
  „Oferta".

⚠️ **Podgląd woła WARSTWĘ OFERTY, nigdy warstwy niższe.** `SaleCalendar` nie dotyka
`StaySellability`, `PositionAvailability` ani wyceny wprost — inaczej stałby się drugim miejscem
składania odpowiedzi, czyli dokładnie tym, czego zakazują ADR-013 i ADR-015. Ta sama zasada
obowiązuje każdy przyszły ekran podglądowy.

⚠️ **Jedyny wyjątek: lista usług przy stanowisku** (zadanie 020). Reguła dotyczy **komórek** —
sprzedawalności i ceny pobytu. Lista usług jest odczytem konfiguracji, nie ofertą, więc pochodzi
z [`PositionServices`](../../app/Services/PositionServices.php) ([`dostepnosc.md`](dostepnosc.md) §5):
- przy nazwie stanowiska **plakietka** z liczbą usług („3 usługi"), a przy usłudze niedostępnej
  w pokazywanym oknie — **kolor ostrzegawczy i dopisek** („3 usługi · 1 niedostępna"), bo tę
  informację ma być widać bez klikania; stanowisko bez usług plakietki nie dostaje;
- **pełna lista w podpowiedzi**: nazwa, cena podstawowa z jednostką albo „bezpłatna", „obowiązkowa",
  limit egzemplarzy informacyjnie, a dla niedostępnej — przyczyna i daty ograniczenia; kolejność:
  obowiązkowe, potem alfabetycznie;
- ⚠️ **siatka nie rośnie i komórki się nie zmieniają** — „ceny od" nie doliczają usług, a kalendarz
  nie pokazuje wolnych egzemplarzy („X z N") ani kwot zależnych od liczby dób czy osób. Dokładne
  wyliczenia należą do modułu zakładania rezerwacji. Liczba zapytań nie rośnie z liczbą usług ani dób.

⚠️ **Ekran podglądowy nie buforuje werdyktu** ([`dostepnosc.md`](dostepnosc.md) §2). Wolno wyłącznie
to, co buforem nie jest: jedna instancja warstwy oferty na stanowisko, jedno wczytanie cennika
i blokad na cały render, diagnostyka cennika pobierana raz na okno. Cennik wczytuje `SaleCalendar`
i podaje go warstwie oferty każdego stanowiska oraz audytowi (opcjonalny parametr konstruktorów
`StayOffer`, `StayPricing`, `PricingConfigurationAudit`) — bez tego każde stanowisko wczytywało
go osobno. Pomiar kosztu: zadanie 019.

**Kwoty na ekranach formatuje `AmountFormatter`**, nigdy `number_format` w widoku. Waluta jest
opcjonalna: siatka jej nie pokazuje, lista martwych stawek — tak. Zakres dat reguły jako tekst
składa `PriceRule::periodText()`, wspólny dla nagłówka wiersza cennika i kalendarza.

⚠️ **Strona podglądowa dziedziczy po `Page`, więc NIE MA z definicji pojęcia rekordu.** Potrzebuje
cechy `InteractsWithRecord` (to z niej biorą się `resolveRecord()` i `getRecord()`), a autoryzację
wykonuje **jawnie** — `abort_unless(... ->can('view', $record), 404)`. Samo zawężenie zapytania
w `resolveRecord()` też broniłoby dostępu, ale niejawnie, jako uboczny skutek zakresu widoczności;
reguła dostępu ma być widoczna w kodzie strony ([`autoryzacja.md`](autoryzacja.md) §5).

Widok siatki (`manage-calendar.blade.php`) to właśnie taki wyjątek z `panel-wlasciciela.md` §1: układ z klasami Tailwinda,
bo tabele Filamenta nie znają `colspan`. Kolory stanów bierze z palety Tailwinda (`amber-700`,
`gray-500` z przezroczystością), żeby działały w jasnym i ciemnym motywie panelu.

---

## 9. Dokumenty łowiska i polityka zwrotu (zadanie 021)

Uzasadnienie kształtu danych dokumentów: [ADR-017](../adr/ADR-017-dokumenty-i-wersje-dokumentow.md).

### Dokumenty — regulamin, polityka prywatności i inne

- **Jedna strona „Dokumenty"** (`ManageDocuments`, `ManageRelatedRecords` po relacji `documents`)
  z **zakładką na rodzaj** — rodzaje działają identycznie. Stoi **na samym końcu** sub-nawigacji.
  Trzeci rodzaj, **„Inne"** (`DocumentType::Other`, zadanie 034), to dokument o własnym tytule
  wersji („Zasady biwakowania") i podlega tej samej mechanice: **najwyżej jedna wersja obowiązująca
  na rodzaj** — kilka dokumentów jednego rodzaju naraz wymagałoby tożsamości dokumentu niezależnej
  od rodzaju, czyli odwrócenia ADR-017. Do publikacji łowiska nadal wymagany jest wyłącznie regulamin
  (`FisheryPublicationReadiness`). Wersje obowiązujące widać w portalu w zakładce „Dokumenty"
  ([`strona-publiczna.md`](strona-publiczna.md) §5).
  Wersje mają stan **liczony**, nie zapisany: obowiązująca / zaplanowana / archiwalna
  (`FisheryDocuments::statusOf()`).
- ⚠️ **Za treść odpowiada łowisko** (D9): ekran niczego nie generuje z konfiguracji, nie wstawia
  zasad zwrotu ani parametrów łowiska i nie sprawdza zgodności. Brak obowiązującego dokumentu to
  **sama informacja** nad tabelą, bez oceny treści.
- **Obowiązuje wersja o najpóźniejszej dacie wejścia w życie ≤ dziś** w strefie łowiska, od północy.
  Liczy ją **jedna** metoda — `FisheryDocuments::current()`.
- ⚠️ **Od dnia wejścia w życie wersja jest NIENARUSZALNA** (tytuł, treść, data, rodzaj) i
  **nieusuwalna** — pilnują tego **haki modelu `Document`** po dacie **zapisanej w bazie**, nie
  wyłączone pola formularza. **Flagi wymagalności** („przy zakupie", „przy rejestracji na łowisku")
  są edytowalne **zawsze**; kopia je przenosi.
  ⚠️ Filament **waliduje także pola wyłączone** — przy wersji obowiązującej reguły daty są zdjęte,
  inaczej odrzuciłyby sam zapis flag.
- **Data wejścia w życie: najwcześniej jutro** w strefie łowiska (`DocumentEffectiveDateIsAhead`,
  przy tworzeniu i edycji); domyślnie **dziś + 14 dni**, z komunikatem obok pola, że od tej daty
  edycja nie będzie możliwa. **Dwie wersje rodzaju z tą samą datą** blokuje reguła
  `DocumentEffectiveDateIsFree` — reguła, nie indeks, bo pomija wersje usunięte miękko.
- **Nowa wersja: źródło do wyboru** — kopia **dowolnej** wersji (domyślnie obowiązującej) albo
  **dowolny szablon**, także innego rodzaju; system tego nie kontroluje. Treść jest **kopią**.
  Wersję do skopiowania szuka się **wyłącznie w dokumentach tego łowiska** — identyfikator
  źródła przychodzi od klienta.
- **Podgląd szablonu** to osobna strona tylko do odczytu (`PreviewDocumentTemplate`), otwierana
  przyciskiem **w nowej karcie** — z listy wersji i z formularza wersji. Treść przechodzi przez
  `Str::sanitizeHtml()`. Bez przycisku „kopiuj": zaznaczenie i Ctrl+C.
- **Formularz wersji to modal `slideOver` na pełną szerokość** — świadomie, mimo reguły „akcje
  prowadzą na pełne strony" (`panel-wlasciciela.md` §2): `Document` nie ma własnego zasobu, rekord akcji pochodzi z tabeli
  po relacji łowiska, a `fishery_id` nowej wersji bierze się z rekordu strony. Każda akcja pyta
  jawnie `FisheryPolicy::update()`.
- **Admin pracuje na dokumentach łowisk na tych samych zasadach** — ta sama strona w obu panelach,
  ta sama nienaruszalność; dziennik zmian pokazuje autora wersji.

### Polityka zwrotu

- **Ekran „Polityka zwrotu"** (`ManageRefundPolicy`, strona ustawień) stoi **za „Kalendarzem",
  „Rezerwacjami" i „Wędkarzami"** — niczego w nich nie zmienia. Repeater na kolumnie JSON `fisheries.refund_policy`; zapis
  normalizuje progi do liczb całkowitych od najdalszego, a brak progów zapisuje jako `null`.
- ⚠️ **Brak progów = „polityka nieustawiona"**, pokazana wprost — nie 0% ani 100%.
- **„0% zawsze" to ostrzeżenie, nie blokada** (D2). Warunek i brzmienie są **robocze** i czekają na
  prawnika (TODO-3); jeden dom warunku: `RefundPolicy::refundsNothingIn()`.
- Znaczenie progów ma jeden dom — `RefundPolicy` ([`cennik.md`](cennik.md) §8); ekran niczego nie liczy.

### Wymagania wobec wędkarza

- **Karta wędkarska, liczba wędek w cenie, no-kill, zakaz ognisk** to pola **łowiska** obok
  „Fishing methods", w formularzu i w kreatorze (`FisheryResource::anglerRuleComponents()`).
  Bieżące, bez wersji; spójność z treścią regulaminu jest po stronie operatora (D9).
- ⚠️ **Każde ma stan „nie podano"**: flagi to `Select` z pustą opcją, nie `Toggle`; liczba wędek
  jest pusta, dopóki operator jej nie wpisze. `EditFishery::mutateFormDataBeforeFill()` zamienia
  `bool` na 1/0 ([`panel-admina.md`](panel-admina.md) §2 — bez tego „nie" ginęło przy zapisie).
  Zakaz ognisk to pole łowiska, **nie** pozycja słownika udogodnień.

---

## 10. Publikacja łowiska w portalu i dane dla portalu (zadanie 030)

- **Publikacja to kolumna `fisheries.published_at`** (`null` = niewidoczne dla wędkarzy, scope
  `Fishery::published()`), ustawiana **wyłącznie** akcjami „Opublikuj" / „Wycofaj z portalu"
  w nagłówku `ManageFishery`. Przyciski są **w obu panelach**: właściciel publikuje swoje łowisko,
  admin — każde (wdrożenie, interwencja). O widoczności decyduje wyłącznie `FisheryPolicy::publish()`,
  nie warunek na panelu; to samo ostrzeżenie o brakach i ta sama blokada bez województwa obowiązują
  obu. Status widać też w kolumnie listy łowisk.
- **Ostrzeżenie o brakach NIE blokuje publikacji — z jednym wyjątkiem: brak województwa.** Listę braków
  liczy **jedna** usługa, [`FisheryPublicationReadiness`](../../app/Services/FisheryPublicationReadiness.php),
  a każdy brak to przypadek `PublicationIssue` (tylko `StateMissing` ma `blocksPublication()`). Modal
  pokazuje braki z odsyłaczem do ekranu poprawy; bez województwa chowa przycisk zatwierdzenia.
  ⚠️ **Blokadę sprawdza też sama akcja**, nie tylko ukryty przycisk — żądanie Livewire da się wysłać
  z pominięciem modala. Nie zdejmuj tego sprawdzenia „bo przycisku i tak nie ma".
- ⚠️ **Punkty konfiguracji sprzedaży pochodzą z kalendarza podglądowego, nie z własnych zapytań:**
  godziny doby — `SaleCalendar::missingSetup()`, okres trwający albo przyszły — `SaleCalendar::seasons()`,
  „brak stanowisk" — `missingSetup()`, dziura w cenniku — `PricingConfigurationAudit::firstPricingGap()`.
  Jedyny punkt spoza tych klas to **„stanowiska są, ale żadne nie jest w sprzedaży"**
  (`positions()->available()`) — i to jest świadome: `missingSetup()` zostaje bez zmian, bo kalendarz
  pokazuje wtedy siatkę z wierszami „wycofane", a nie mylące „dodaj stanowiska".
- **Województwo jest wymagane w formularzu łowiska zawsze** (`state_id` z `required()`), więc opublikowane
  łowisko nie zapisze się bez niego. Pozostałe braki powstałe po publikacji łowiska **nie wycofują** —
  wycofuje wyłącznie właściciel.
- **Autoryzacja jest jawna:** widoczność akcji i jej wykonanie pytają `FisheryPolicy::publish()`
  ([`autoryzacja.md`](autoryzacja.md) §5).
- **Kontakt na łowisku i adresy w sieci** (`phone`, `email`, `contact_hours`, `website_url`, `facebook_url`)
  to sekcja `FisheryResource::contactSection()` wewnątrz `fisheryDetailComponents()` — więc także
  w kreatorze. Kontakt należy do **łowiska**, nie do firmy (jedna firma, kilka łowisk, różne numery).
  Reguły pól: [`PhoneNumber`](../../app/Rules/PhoneNumber.php), [`FacebookUrl`](../../app/Rules/FacebookUrl.php)
  (host sprawdzany dokładnie), WWW — `url:http,https`.
- **Slug łowiska właściciel widzi tylko do odczytu** na „Danych łowiska". Pole edycji istnieje wyłącznie
  w schemacie panelu admina ([`panel-admina.md`](panel-admina.md) §11), więc podmieniony stan Livewire
  niczego nie zmienia — ukryte pole nie jest dehydratowane.
- **Liczby stanowisk nie ma w danych łowiska** — „Stanowiska w sprzedaży" to liczba wyliczana
  z `Position::available()`. ⚠️ Nie wracaj do kolumny `positions_count`: była drugą prawdą obok tabeli stanowisk.
