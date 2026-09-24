# 021 — Polityka zwrotu progowa, regulamin wersjonowany i wymagania wobec wędkarza

> **Pochodzenie:** numer z planu realizacji ([wymagania, §14.1](../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md)),
> zarezerwowany 20.09.2026 i uzupełniony przez `/create-task` 23.09.2026.
> **Moduły:** M7 + M8. Powiązane: D1, D2 (§14.4 wymagań), D7 i TODO-1/TODO-3 w
> [`DECYZJE-I-TODO-BIZNESOWE.md`](../project/DECYZJE-I-TODO-BIZNESOWE.md).
> **Przegląd:** wspólny przegląd po pakiecie 017–021 (§14.2) — 021 zamyka pakiet, więc przegląd
> i `/review-implementation` idą zaraz po nim.

**Kolejność implementacji: po zadaniu 020** (które z kolei rusza po zakończeniu testów mutacyjnych
i commicie zadania 023). Kod obu zadań prawie się nie przecina — wspólne są rejestracja stron
w `FisheryResource::getPages()` i sub-nawigacji, formularz łowiska, `lang/pl.json`, `CHANGELOG.md`,
`MANUAL.md` i `panel-wlasciciela.md` — ale wspólna jest **baza testowa `lowiska_test`** i katalog
roboczy (bez worktree), więc zadania idą po kolei, a nie równolegle.

Makieta: [`makieta-021-dokumenty-i-zwroty.html`](../project/mockups/makieta-021-dokumenty-i-zwroty.html)
— **propozycja do przeglądu**, poprawiona 24.09.2026 (podgląd szablonu w nowej karcie, kolejność
w sub-nawigacji). Rysuje trzy nowe ekrany (dokumenty, progi zwrotu, szablony w `/admin`) oraz nowe
pola łowiska. Tam, gdzie odpowiada na pytania otwarte,
niesie wariant rekomendowany, a nie decyzję.

## Opis problemu

Łowisko sprzedaje na własnych warunkach, a system tych warunków dziś nie zna:

- **Brak regulaminu i polityki prywatności w systemie.** Oba łowiska mają regulaminy (Łopienno
  26 punktów, Klasztorne 32) i zmieniają je w sezonie — Klasztorne zmieniło swój między 2025
  a 2026 (O16). Bez wersji z datą obowiązywania nie da się odtworzyć, na co wędkarz się zgodził.
  Stroną umowy jest łowisko, więc treść obu dokumentów jest **odpowiedzialnością operatora**.
- **Brak polityki zwrotu jako danych.** Oba łowiska mają dziś jeden próg (100% do 7 dni, potem 0%).
  Chcą móc go zmienić bez zmiany kodu (M7). Płatności policzą na tym zwrot (D1), a panel ma
  ostrzegać przy zerowym zwrocie (D2).
- **Brak parametrów, po których wędkarz wybiera łowisko** (O17): czy potrzebna karta wędkarska,
  ile wędek w cenie, czy wolno zabrać rybę, czy wolno palić ognisko.

**Stan w kodzie (rozpoznanie z 23.09.2026):**
- Nic z M7 ani M8 nie istnieje: żadnych tabel regulaminu, polityki prywatności ani progów zwrotu.
- Formularz łowiska (`FisheryResource`, strona `ManageFishery` + kreator `CreateFishery`) ma już
  `CheckboxList` „Fishing methods" (słownik `FishingMethod`) i relację `conveniences`.
- Treści operatora są jednojęzyczne, w kolumnach bez sufiksu językowego (D8).
- `RichEditor` jest już używany w formularzach Filamenta (np. opis usługi dodatkowej).
- **Nie ma modelu rezerwacji ani transakcji**, więc nie ma jeszcze komu wskazać „wersji
  zaakceptowanej przy zakupie".

## Wymagania

### 1. Dokumenty łowiska — regulamin i polityka prywatności

- Łowisko ma **dwa rodzaje dokumentów**: **regulamin** i **politykę prywatności**. Działają
  identycznie; rodzaj jest polem dokumentu, a nie osobnym mechanizmem (model otwarty — regulamin
  platformy z TODO-1 dojdzie jako kolejny rodzaj bez przepisywania, test Z4).
- Wersja dokumentu to **tytuł**, **treść** z prostego edytora (`RichEditor`) i **data wejścia
  w życie**. System **nie narzuca zakresu ani parametrów treści** — to odpowiedzialność operatora.
- Wersja ma dwie **flagi wymagalności**: **„wymagany przy zakupie"** i **„wymagany przy
  rejestracji na łowisku"** (pierwszy kontakt wędkarza z łowiskiem, niezależnie od konkretnego
  zakupu). Zadanie **przechowuje i edytuje** flagi; akceptację przez wędkarza budują moduły
  rezerwacji i portalu. ⚠️ **Znaczenie flagi „przy rejestracji na łowisku" jest robocze** —
  portal nie ma dziś takiego pojęcia (IDEA: jedno konto wędkarza, zakładane przy pierwszym
  zakupie); przeanalizujemy je przy budowie części dla klientów.
  - Flagi są **edytowalne zawsze**, także w wersji obowiązującej — to ustawienie sposobu użycia
    dokumentu, a nie jego treść. Kopia wersji przenosi flagi.
  - O tym, co jest wymagane, decydują flagi **wersji obowiązującej**.
- **Wersja nie ma daty końca.** Przestaje obowiązywać, gdy wchodzi w życie następna wersja tego
  samego rodzaju. Obowiązuje wersja o **najpóźniejszej dacie wejścia w życie ≤ dziś** (w strefie
  łowiska).
- **Wędkarza wiąże wersja obowiązująca w chwili zakupu, a nie w dniu pobytu.**
- **Edycja do dnia wejścia w życie.** Wersję, której **zapisana** data wejścia w życie jest
  późniejsza niż dziś, można edytować (tytuł, treść i datę). Od dnia wejścia w życie jest
  **nienaruszalna** (F10) — nie zmienia się ani tytuł, ani treść, ani data, a serwer odrzuca edycję
  i usunięcie na podstawie daty zapisanej w bazie. **Wyjątkiem są flagi wymagalności**, edytowalne
  zawsze.
- **Wersja obowiązuje od północy** w strefie łowiska w dniu wejścia w życie.
- **Nowa wersja: źródło do wyboru.** Przy każdej nowej wersji operator wybiera, od czego zaczyna:
  - **kopia istniejącej wersji** — domyślnie obowiązującej, gdy taka istnieje; kopiuje treść;
  - **dowolny szablon** z panelu admina (pkt 2) — także „niepasujący" do rodzaju dokumentu, np.
    szablon polityki prywatności przy regulaminie. **System tego nie kontroluje.**
  Nienaruszalność obowiązującej wersji zapewnia zakaz edycji od dnia wejścia w życie, więc nie
  trzeba do tego ograniczać źródła nowej wersji.
- **Podgląd szablonu** (tylko do odczytu, bez zmiany edytowanej treści) jest **schowany pod
  przyciskiem „Podgląd szablonu" i otwiera się w nowym oknie albo nowej karcie** — nie zajmuje
  miejsca na ekranie edycji. Przycisk jest:
  - **na liście wersji** dokumentu,
  - **na ekranie edycji** wersji — edytor zajmuje całą szerokość.
  Podgląd pozwala wybrać dowolny szablon z listy. **Nie ma przycisku „kopiuj do schowka"** —
  fragment szablonu kopiuje się zaznaczeniem i Ctrl+C.
- **Domyślna data wejścia w życie** dla nowej i skopiowanej wersji: **dziś + 14 dni**. Obok pola
  daty stoi **wyraźny komunikat**, że od tej daty edycja nie będzie już możliwa.
- Data wejścia w życie wymaga **co najmniej jednego dnia wyprzedzenia**: najwcześniej **jutro**
  (w strefie łowiska). Data dzisiejsza albo przeszła daje **błąd walidacji** — przy tworzeniu
  i przy edycji.
- Brak stanu szkicu — każda wersja ma datę.
- **Dwie wersje tego samego rodzaju z tą samą datą wejścia w życie są blokowane.** Kilka wersji
  zaplanowanych naraz (z różnymi datami) jest dozwolone.
- **Wersję zaplanowaną można usunąć** przed dniem wejścia w życie. Wersji obowiązującej
  i archiwalnej — nie.
- **Kopia może powstać z dowolnej wersji** — obowiązującej, zaplanowanej albo archiwalnej.
  Domyślnie proponowana jest obowiązująca.
- **Brak obowiązującego regulaminu** (albo polityki prywatności) pokazuje w panelu **samą
  informację**, bez oceny treści (D9).
- **Admin Fisherya pracuje na dokumentach łowisk na tych samych zasadach co operator** — ta sama
  strona w sub-nawigacji łowiska w obu panelach. Nienaruszalność wersji i walidacja daty obowiązują
  także admina. Za treść odpowiada łowisko (D9), a dziennik zmian pokazuje, kto zapisał wersję.
- **Kształt danych dokumentów** — patrz [ADR-017](../adr/ADR-017-dokumenty-i-wersje-dokumentow.md):
  jedna tabela `documents` (wiersz = wersja; `fishery_id` NOT NULL bez kaskady, `type`, `title`,
  `effective_from`, `content`, dwie flagi wymagalności, soft delete) oraz tabela
  `document_templates` (`type`, `name`, `content`). **Jedynym kluczem głównym obu tabel jest `id`
  (`->id()`)** — bez złożonego klucza i bez indeksu unikalnego na kilku kolumnach; zakaz tej samej daty w rodzaju pilnuje reguła walidacji
  w `app/Rules/`, pomijająca wersje usunięte miękko.
- **Jedna strona „Dokumenty"** z zakładkami rodzajów, **na samym końcu sub-nawigacji** łowiska
  (dokumenty zmieniają się rzadko). Pokazuje wersje z oznaczeniem: **obowiązująca / zaplanowana /
  archiwalna**.

### 2. Szablony dokumentów — panel admina

- Admin Fisherya utrzymuje w panelu `/admin` **dowolną liczbę szablonów**. Szablon to **typ**
  (ten sam enum co rodzaj dokumentu), **nazwa i treść** (np. „Regulamin — łowisko karpiowe",
  „Regulamin — wariant krótki").
- **Typ porządkuje i podpowiada, ale nie ogranicza.** W adminie grupuje szablony; przy nowej wersji
  lista pokazuje najpierw szablony pasującego typu. Wybór dowolnego szablonu, także niepasującego,
  zostaje dozwolony.
- Wersja utworzona z szablonu dostaje **kopię** jego treści. Późniejsza zmiana albo usunięcie
  szablonu **nie rusza** dokumentów łowisk.
- W panelu właściciela szablony są **tylko do odczytu**: lista do wyboru przy tworzeniu wersji
  i podgląd (pkt 1). Operator nie tworzy ani nie edytuje szablonów. Rola właściciela dostaje
  uprawnienie **tylko do odczytu** szablonów; tworzenie, edycja i usuwanie zostają przy adminie.
- Treść szablonów przygotuje TODO-1 / prawnik (TODO-3). Zadanie dostarcza mechanizm i **roboczy
  szablon regulaminu**, wyraźnie oznaczony jako **do weryfikacji prawnej**.
- **Szablon polityki prywatności czeka na prawnika.** Jej treść zależy od rozstrzygnięcia ról
  w RODO — odrębni administratorzy czy współadministratorzy (TODO-3, pytanie 6). Mechanizm
  polityki prywatności powstaje w tym zadaniu, szablon nie.

### 3. Polityka zwrotu — konfiguracja łowiska

- Łowisko ustawia **dowolną liczbę progów**: liczba dni przed początkiem pobytu → procent zwrotu
  (M7, F9). Przykład docelowy: 100% do 7 dni → 50% do 3 dni → 0% poniżej 3 dni.
- **Procent zwrotu liczy się od całej zapłaconej kwoty, ze wszystkimi usługami dodatkowymi.**
  To znaczenie ustawienia widoczne dla operatora i wędkarza; samo liczenie zwrotu powstaje
  z płatnościami (D1, D7).
- **Odwołać można wyłącznie przed rozpoczęciem pierwszej doby pobytu** (np. przed 15:00 w dniu
  przyjazdu). Później jest to przerwanie pobytu, nie odwołanie — poza zakresem; próg „0 dni"
  działa tylko do tej chwili.
- **Polityka dotyczy wyłącznie odwołania przez wędkarza.** Odwołanie przez łowisko (blokada na
  sprzedany termin, G8) to **zawsze pełny zwrot**, niezależnie od progów — tego nie da się
  skonfigurować.
- **Czym jest „N dni przed".** Liczymy **daty kalendarzowe w strefie łowiska**: różnicę między
  datą rozpoczęcia pierwszej doby pobytu a datą odwołania. Godziny doby nie grają roli.
  **Granica jest domknięta**: próg „N dni" obowiązuje, gdy różnica wynosi **co najmniej N**.
  Przykład: pobyt od 10.07, próg „7 dni → 100%" — odwołanie do 03.07 włącznie daje 100%.
- **Odwołanie wcześniejsze niż najdalszy próg** podlega najdalszemu progowi, bo „co najmniej N"
  obejmuje każdą większą różnicę.
- **Odwołanie bliżej niż najbliższy próg** daje **0%**. Łowisko, które chce zwracać coś do
  ostatniej chwili, ustawia próg „0 dni".
- **Ekran „Polityka zwrotu"** stoi w sub-nawigacji **za „Kalendarzem"** — kalendarz zostaje zaraz za
  trzema ekranami, których skutki pokazuje (019), a polityka zwrotu nic w nim nie zmienia.
- **Brak progów znaczy „polityka nieustawiona"** — a nie 0% ani 100%. Panel pokazuje ten stan
  wprost. Co wtedy ze sprzedażą, rozstrzyga koszyk.
- **Bieżąca konfiguracja, bez wersji** — tak jak cennik. Zamrożenie w chwili zakupu robi snapshot
  transakcji (G1, Z1) z modułem rezerwacji.
- **Zapis: kolumna JSON na łowisku** — lista `{dni, procent}`, wzorem `weekend_days`. Dni i procent
  to liczby całkowite: dni 0–365, procent 0–100. Pusta wartość znaczy „polityka nieustawiona".
  Zmiany loguje istniejący `LogsActivity` łowiska.
- **Portal nie narzuca widełek** (D2). Dopuszczalne jest „0% zawsze".
- **Ostrzeżenie, nie blokada**, przy polityce z zerowym zwrotem. Tekst roboczy wskazuje na
  bezskuteczność klauzuli wobec konsumenta, a nie na prawo odstąpienia (adnotacja prawna do M7).
  Brzmienie jest do potwierdzenia u prawnika (TODO-3, pytanie 2).
- **Warunek ostrzeżenia należy do prawnika** (TODO-3, pytanie 3), nie do `/review-task`. Do
  czasu odpowiedzi ostrzeżenie pokazuje się roboczo przy polityce, w której **żaden próg nie
  zwraca więcej niż 0%** („0% zawsze" z D2).
- Walidacja: progi bez duplikatów liczby dni, procent 0–100, a procent **nie rośnie** wraz
  z przybliżaniem się terminu.
- Bez kary za brak przyjazdu (O18/P8) — brak przyjazdu obsługuje ostatni próg.

### 4. Parametry łowiska — wymagania wobec wędkarza

Nowe pola w **danych łowiska**, obok „Fishing methods" — bieżące, bez wersji, dla wyszukiwarki
i opisu oferty:

| Pole | Typ | Łopienno / Klasztorne |
|---|---|---|
| Karta wędkarska wymagana | flaga | tak / tak |
| Liczba wędek w cenie | liczba | 2 / 2 |
| Zakaz zabierania ryb (no-kill) | flaga | tak / tak |
| Zakaz ognisk | flaga | nie / tak (teren Parku) |

- **Każde pole dopuszcza stan „nie podano"**: flagi przyjmują tak / nie / puste, liczba wędek jest
  pusta, dopóki operator jej nie wpisze. Istniejące łowiska startują z pustymi wartościami.
  Wyszukiwarka i opis oferty nie traktują pustego jako „nie" — tak samo jak trzeci stan cech
  stanowiska. W formularzu flagi to `Select` z pustą opcją, nie `Toggle`, bo `Toggle` zawsze
  niesie „nie".
- **Zakaz ognisk to osobne pole łowiska**, nie pozycja słownika udogodnień (`conveniences`).
- **Dozwolone metody połowu** zostają w istniejącym polu „Fishing methods" (bez zmian).
- **Spójność parametrów z treścią regulaminu jest odpowiedzialnością operatora.** System jej
  nie sprawdza (D9).
- Wymagany sprzęt (mata, podbierak, żyłka, sling, dezynfekcja), limit zanęty i zasady zdjęć
  zostają **wyłącznie w treści regulaminu**.
- Pola są w formularzu łowiska i w kreatorze zakładania łowiska.

## Kryteria akceptacji

- [ ] Łowisko ma regulamin i politykę prywatności jako wersjonowane dokumenty (tytuł, treść, data
      wejścia w życie) w jednej tabeli wg ADR-017. Obowiązującą wersję wyznacza najpóźniejsza
      data ≤ dziś w strefie łowiska.
- [ ] Flagi „wymagany przy zakupie" i „wymagany przy rejestracji na łowisku" są edytowalne także
      w wersji obowiązującej, a kopia je przenosi; tytuł, treść i data wersji obowiązującej są
      nienaruszalne.
- [ ] Wersja z przyszłą datą jest edytowalna; od dnia wejścia w życie edycja i usunięcie są
      niemożliwe (formularz i zapis po stronie serwera — nie tylko ukryty przycisk). Serwer
      rozstrzyga po **dacie zapisanej w bazie**: próba zmiany treści albo daty wersji obowiązującej
      jest odrzucana także wtedy, gdy formularz przyśle przyszłą datę.
- [ ] Nowa wersja powstaje z kopii obowiązującej albo z dowolnego szablonu (bez kontroli
      zgodności z rodzajem); domyślna data to dziś + 14 dni z komunikatem o końcu edycji; data
      dzisiejsza albo przeszła daje błąd walidacji (przy tworzeniu i edycji).
- [ ] Szablony (typ + nazwa + treść, dowolnie wiele) tworzy, edytuje i usuwa wyłącznie admin w `/admin`;
      przy nowej wersji szablony pasującego typu są na górze listy, a niepasujące da się wybrać;
      właściciel ma do nich dostęp tylko do odczytu (lista i podgląd); zmiana lub usunięcie
      szablonu nie rusza istniejących dokumentów.
- [ ] Podgląd dowolnego szablonu otwiera się przyciskiem w nowej karcie — z listy wersji
      i z ekranu edycji — bez zmiany edytowanej treści.
- [ ] Polityka zwrotu: dowolna liczba progów z walidacją; ostrzeżenie (nie blokada) przy
      „0% zawsze"; brak progów pokazuje się jako „polityka nieustawiona".
- [ ] Jedna klasa w `app/Services/` odpowiada na pytanie „jaki procent zwrotu przy odwołaniu
      w dniu D pobytu od dnia S". Testy pokrywają: granicę domkniętą (dokładnie N dni),
      odwołanie przed najdalszym progiem, odwołanie bliżej niż najbliższy (0%), próg „0 dni",
      odwołanie po rozpoczęciu pierwszej doby (brak odwołania), brak progów oraz datę liczoną
      w strefie łowiska.
- [ ] Wersje: ta sama data wejścia w życie dla dwóch wersji rodzaju jest odrzucana; wersję
      zaplanowaną da się usunąć, obowiązującej i archiwalnej nie; kopia z dowolnej wersji.
- [ ] Cztery nowe parametry łowiska w formularzu i kreatorze, każdy ze stanem „nie podano";
      istniejące łowiska mają puste wartości.
- [ ] Progi zwrotu zapisane jako JSON na łowisku; puste = „polityka nieustawiona".
- [ ] Admin edytuje dokumenty łowiska na tych samych zasadach co operator, łącznie
      z nienaruszalnością wersji obowiązującej.
- [ ] Sub-nawigacja: „Polityka zwrotu" za „Kalendarzem", „Dokumenty" na samym końcu.
- [ ] Dostęp: operator widzi i edytuje wyłącznie dokumenty i konfigurację swojego łowiska
      (test wzorem `FisheryAccessTest` / `CrossTenantRelationTest`); zarządzanie szablonami tylko
      w `/admin`.
- [ ] Nowe dane objęte dziennikiem zmian (`LogsActivity`, `dziennik-zmian.md`).
- [ ] Nowe klucze tłumaczeń w `lang/pl.json`.
- [ ] Zielony zakres T2 zadeklarowany niżej. **Pełny pakiet (T3) odroczony** na wspólny przegląd
      po pakiecie 017–021 (`/review-implementation`, Krok 1), który idzie zaraz po tym zadaniu.

## Zakres testów

- **Tier:** T2 (decyzja pakietowa z §14.2 wymagań)
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="FisheryAccessTest|FisheryWizardTest|FisheryWizardEntryPointsTest|SaleSettingsPageTest|SaleRulesPageTest|ActivityLoggingTest|ShieldPermissionNamesTest|AdminPanelTest|OwnerPanelTest|OwnerRoleProvisioningTest|CrossTenantRelationTest"` + nowe klasy testów dokumentów łowiska, szablonów i polityki zwrotu (nazwy ustala implementacja; dopisać je do filtra)
- **Uzasadnienie:** zadanie trafia w **dwa** wyzwalacze T3 z `CLAUDE.md`: migracje (dokumenty,
  szablony, progi, parametry łowiska) oraz nowa polityka z uprawnieniami Shielda (zasób szablonów
  w `/admin`, ewentualnie zasoby dokumentów). Zgodnie z §14.2 i precedensem zadania 014 (T2 mimo
  trzech wyzwalaczy, w tym polityk) każde zadanie pakietu 014–021 deklaruje T2, a pełny pakiet
  biegnie na **wspólnym przeglądzie po 017–021**. To **odroczenie, nie zwolnienie**; 021 zamyka
  pakiet, więc przegląd następuje zaraz po nim. Warunek: 021 nie trafia do
  `docs/tasks/implemented/`, dopóki ten przegląd nie jest zielony.

## Zakres wyłączeń

- **Parametry przy regulaminie i ich wersjonowanie** — parametry żyją w danych łowiska; wersja
  dokumentu to sama treść (decyzja autora).
- **Sprawdzanie spójności parametrów łowiska z treścią regulaminu.**
- **Wymagany sprzęt, limit zanęty, zasady zdjęć jako pola** — tylko treść regulaminu.
- **Wystawienie dokumentów i polityki zwrotu w warstwie oferty** oraz ich zamrożenie
  w transakcji (G1, Z1) — z modułem rezerwacji, spójnie z 020.
- **Akceptacja regulaminu przez wędkarza** i komunikat o braku prawa odstąpienia — strona
  portalu / koszyka.
- **Regulamin platformy** (TODO-1) — model go przewiduje, treść i ekran osobno.
- **Algorytm zwrotu i podział prowizji** (D1, D7) — z płatnościami.
- **Kara za brak przyjazdu** (O18/P8), przełożenie rezerwacji, zwrot w formie bonu.
- **Filtry wyszukiwarki** — zadanie dostarcza dane, nie wyszukiwarkę.

## Zmiany dokumentacji

- [ ] `docs/conventions/panel-wlasciciela.md` — ekran dokumentów łowiska (wersje, edycja do dnia
      wejścia w życie, kopia, domyślna data +14 dni), ekran polityki zwrotu, nowe pola łowiska.
- [ ] `docs/conventions/panel-admina.md` — zasób szablonów dokumentów.
- [ ] `docs/conventions/autoryzacja.md` — polityki nowych zasobów (jeśli powstaną).
- [ ] `docs/project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md`:
  - [ ] §0.1: M7 i M8 zrealizowane;
  - [x] §5: zależność M8 → M7 zniesiona — zrobione przy decyzji D9 (24.09.2026);
  - [x] M8 i F10: wymagania wobec wędkarza jako pola **łowiska**, regulamin jako sama treść —
    zrobione przy D9;
  - [ ] M8: polityka prywatności jako drugi rodzaj dokumentu.
- [ ] `docs/project/DECYZJE-I-TODO-BIZNESOWE.md` — TODO-1 §2.2: odpowiedzialność za treść
      i rola wzorca zapisane przy D9 (24.09.2026); do dopisania przy implementacji: szablony
      (dowolnie wiele, nazwa + treść) żyją w panelu admina; TODO-3: tekst roboczy ostrzeżenia
      czeka na brzmienie od prawnika.
- [ ] `MANUAL.md` — dokumenty łowiska, polityka zwrotu i nowe parametry z perspektywy operatora.
- [ ] `CHANGELOG.md` — wpis przez skill `changelog`.

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4.
- Daty wejścia w życie i „dziś" liczone w **strefie łowiska** (`fisheries.timezone`).
- Treści operatora jednojęzyczne, jedna kolumna bez sufiksu językowego (D8).
- Treść z `RichEditor` przy wyświetlaniu **sanityzowana** — pochodzi od operatora (XSS).
- Nienaruszalność wersji pilnowana **po stronie serwera** (polityka albo model), nie tylko
  w widoku — każde ID i właściwość komponentu to dane od klienta (`CLAUDE.md`).
- Ekrany jednego łowiska to strony w sub-nawigacji rekordu (`panel-wlasciciela.md` §2).
- Autoryzacja przez polityki i Shielda; model bez własnego zasobu autoryzuje się przez rodzica
  (`autoryzacja.md` §5).
- Tłumaczenia wyłącznie w `lang/pl.json`.
- Powierzchnie do przeczytania przed edycją: `panel-wlasciciela.md`, `panel-admina.md`,
  `autoryzacja.md`, `dziennik-zmian.md`.

### Sprawy poza zadaniem

Poza `/review-task`: **warunek i treść ostrzeżenia o zerowym zwrocie** należą do prawnika
(TODO-3, pytania 2 i 3). **Szablon polityki prywatności** czeka na rozstrzygnięcie ról w RODO
(TODO-3, pytanie 6).

## Rozstrzygnięcia
<!-- Punktowe decyzje ustalone przy /review-task: wygląd, copy, próg, nazwa, umiejscowienie.
     Wiążą implementację tak samo jak decyzje z ADR-ów, ale nie mają zasięgu poza zadaniem.
     Format: decyzja + jednozdaniowe uzasadnienie. -->
- **Za treść regulaminu i polityki prywatności odpowiada operator; system nie łączy treści
  z konfiguracją** (decyzja D9, 24.09.2026, `DECYZJE-I-TODO-BIZNESOWE.md` rozdz. 8). Nie
  generujemy treści z ustawień, nie wstawiamy do niej zasad zwrotu ani parametrów, nie sprawdzamy
  zgodności i nie ostrzegamy o rozbieżnościach. Automatyczna ingerencja dzieliłaby
  odpowiedzialność między system a operatora, a w sporze byłaby ryzykiem dla Fisheryi.
- **Ta sama data wejścia w życie dla dwóch wersji rodzaju — blokowana.** Inaczej nie da się
  jednoznacznie wskazać wersji obowiązującej.
- **Wersję zaplanowaną wolno usunąć.** Nikt jej jeszcze nie zaakceptował, więc nie ma czego chronić.
- **Kopia z dowolnej wersji**, domyślnie z obowiązującej. Operator może wrócić do treści
  archiwalnej albo zacząć od innej zaplanowanej.
- **Zakaz ognisk jako osobne pole łowiska**, nie pozycja słownika udogodnień.
- **Brak obowiązującego regulaminu — sama informacja w panelu**, bez oceny treści (D9).
- **Działanie progów zwrotu** (daty w strefie łowiska, granica domknięta, poza najdalszym progiem
  — najdalszy, bliżej niż najbliższy — 0%, brak progów = „polityka nieustawiona", odwołanie przez
  łowisko zawsze pełny zwrot). D9 tego nie obejmuje, bo to reguła rozliczenia, nie treść.
- **Wędkarza wiąże wersja z chwili zakupu**, nie z dnia pobytu.
- **Szablon polityki prywatności poza zadaniem** do czasu odpowiedzi prawnika w sprawie RODO.
- **Kształt danych dokumentów — ADR-017, wariant opcji B:** jedna tabela `documents` (wiersz =
  wersja, `fishery_id` NOT NULL bez kaskady) i tabela `document_templates` z typem. Regulamin platformy
  będzie wymagał później zmiany kolumny albo osobnej tabeli — migracji bez przepisywania danych.
- **Flagi wymagalności na wersji, edytowalne zawsze** (decyzja autora). Obowiązują flagi wersji
  obowiązującej; snapshot transakcji musi sam zapisać, co wędkarz zaakceptował.
- **„Wymagany przy rejestracji" znaczy rejestrację wędkarza na łowisku** — pierwszy kontakt
  z łowiskiem, nie założenie konta w portalu. **Znaczenie robocze** — do analizy przy budowie
  części dla klientów (24.09.2026).
- **Bez indeksu unikalnego na kilku kolumnach** (decyzja autora, 24.09.2026) — zakaz tej samej
  daty w rodzaju pilnuje reguła walidacji pomijająca wersje usunięte miękko, więc usunięcie
  zaplanowanej wersji zwalnia jej datę.
- **Typ szablonu porządkuje i podpowiada, nie ogranicza** — wcześniejsze „nie kontrolujemy wyboru
  szablonu" zostaje w mocy.
- **Data wejścia w życie z co najmniej jednodniowym wyprzedzeniem** — dziś lub wcześniej to błąd
  walidacji. Edycja i usunięcie rozstrzygane po dacie zapisanej w bazie; wersja obowiązuje od
  północy w strefie łowiska.
- **Podgląd szablonu pod przyciskiem, w nowej karcie**; edytor na całą szerokość; bez przycisku
  „kopiuj" — wystarcza zaznaczenie i Ctrl+C.
- **Umiejscowienie:** jedna strona „Dokumenty" na samym końcu sub-nawigacji; „Polityka zwrotu"
  za „Kalendarzem".
- **Procent zwrotu od całej zapłaconej kwoty ze wszystkimi usługami dodatkowymi.**
- **Odwołanie możliwe wyłącznie przed rozpoczęciem pierwszej doby**; później to przerwanie
  pobytu, poza zakresem.
- **Implementacja po zadaniu 020** — wspólna baza testowa i katalog roboczy.

Ustalone przy `/review-task`, 24.09.2026:

- **Admin Fisherya edytuje dokumenty łowisk na tych samych zasadach co operator.** Concierge
  konfiguruje pierwsze łowiska (IDEA 10.1). Odpowiedzialność za treść zostaje przy łowisku (D9),
  a dziennik zmian pokazuje autora wersji.
- **Progi zwrotu jako kolumna JSON na łowisku**, liczby całkowite (dni 0–365, procent 0–100).
  Nikt nie odpytuje progów SQL-em, a zmiany loguje istniejący dziennik łowiska — osobna tabela
  byłaby kodem bez korzyści.
- **Parametry łowiska z trzecim stanem „nie podano"** (flagi jako `Select` z pustą opcją, liczba
  wędek pusta). Niewypełnione łowisko nie może ogłaszać „karta niewymagana" ani „można zabrać rybę".
- **Właściciel ma szablony tylko do odczytu** (lista i podgląd). Poprawia kryterium „szablony tylko
  w `/admin`", które kłóciło się z podglądem w panelu właściciela.

## Powiązane ADR-y

- [ADR-017 — Dokumenty łowiska jako jedna tabela wersji, niezależna od cyklu życia łowiska](../adr/ADR-017-dokumenty-i-wersje-dokumentow.md) — przyjęty 24.09.2026 (wariant opcji B).
