# 016 — Blokady, ograniczenia i wyliczanie dostępności

## Opis problemu

Po zadaniu 015 wiadomo, czym jest doba i w jakich okresach łowisko sprzedaje. Po zadaniu 014 wiadomo,
jakie ma stanowiska, jakie mają cechy i czy są w sprzedaży. Brakuje trzeciego składnika — **wyłączeń
obowiązujących w czasie** — oraz miejsca, które te trzy składniki składa w jedną odpowiedź.

Wyłączenia występują w dwóch odmianach o **identycznym kształcie**: zbiór stanowisk, zakres dat,
powód i widoczność powodu. Różnią się wyłącznie skutkiem — jedno wyłącza sprzedaż w terminie, drugie
zawiesza na czas określony jedną cechę stanowiska, nie ruszając jej wartości. Zbiór, którego dotyczą,
**nie pokrywa się z żadnym stałym podziałem łowiska**: remont infrastruktury obejmuje stanowiska
rozrzucone po całym obiekcie, a decyzja administracyjna — jeden zwarty blok.

Drugi brak jest poważniejszy: **nic nie odpowiada na pytanie „czy tę dobę można sprzedać i dlaczego
nie".** Dopóki go nie ma, poprzednie zadania da się sprawdzić wyłącznie po zawartości tabel, a nie po
zachowaniu, a każde kolejne będzie kuszone, żeby dorobić własne wyliczenie.

Zadanie realizuje **M2** w części blokad wraz z punktem elastyczności **F3** i mechanizmami **G8**
i **G11** z [Wymagań konfiguracji sprzedaży krótkoterminowej](../../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md),
a przy okazji wnosi pierwsze wcielenie zasady **Z3** i mechanizmu **G5** — jednego źródła prawdy
o dostępności.

## Wymagania

### Nowa tabela `availability_blocks`

| Kolumna | Typ | Uwagi |
|---|---|---|
| `id` | `id()` | |
| `fishery_id` | `foreignId`, **wymagany**, `cascadeOnDelete()` | jak `sale_periods` (015) i `position_groups` (014) — patrz „Rozstrzygnięcia" |
| `effect` | `string(20)` | enum `BlockEffect`: `sale_blocked`, `attribute_suspended` |
| `position_attribute_id` | `foreignId` nullable, `onDelete('set null')` | wymagane przy `attribute_suspended`, puste przy `sale_blocked` |
| `starts_on` | `date` | |
| `ends_on` | `date` nullable | puste = do odwołania |
| `reason` | `text` | |
| `reason_visible` | `boolean`, domyślnie `true` | czy wędkarz widzi powód |
| `selection_kind` | `string(20)` | enum `SelectionKind`: `fishery`, `group`, `manual`, `attribute` — **wyłącznie opis tego, jak wybrano zbiór** |
| `selection_label` | `string` nullable | czytelny zapis kryterium, np. nazwa grupy albo nazwa cechy |
| | `softDeletes()`, `timestamps()` | |
| — | `index(['fishery_id','starts_on'])` | |

### Nowa tabela pośrednia `availability_block_position` — rozwiązany zbiór

| Kolumna | Typ | Uwagi |
|---|---|---|
| `availability_block_id` | `foreignId`, `cascadeOnDelete()` | |
| `position_id` | `foreignId`, `cascadeOnDelete()` | |
| — | `primary(['availability_block_id','position_id'])` | |

**Zbiór jest zawsze materializowany jako lista konkretnych stanowisk** — również wtedy, gdy operator
wybrał „całe łowisko". `selection_kind` i `selection_label` służą wyłącznie do wyjaśnienia i do
ponownego przeliczenia na żądanie; **nie są rozwiązywane przy odczycie**, żeby zmiana cechy na jednym
stanowisku nie wciągała go po cichu w ograniczenie ani z niego nie wypychała.

### Enumy — `app/Enums/`

- `BlockEffect`: `sale_blocked` (wyłączenie sprzedaży), `attribute_suspended` (zawieszenie cechy).
- `SelectionKind`: `fishery`, `group`, `manual`, `attribute`.

Etykiety obu w `lang/pl.json`.

### Usługa wyliczająca dostępność — rdzeń zadania

Jedno miejsce w `app/Services/`, **jedyne w całym projekcie**, które odpowiada na pytania o dostępność.

⚠️ **Nie zaczyna od zera.** Zadanie 015 zostawiło `FishingDayCalendar`, który odpowiada na to samo
pytanie **dla łowiska** (doba mieści się w okresie sprzedaży, albo nie). Nowa usługa (`PositionAvailability`)
**komponuje** kalendarz, a nie zastępuje go ani nie powtarza jego reguł: kalendarz wie o **czasie**,
nowa usługa o **stanowisku**. Enum `SaleUnavailabilityReason` zostaje **rozszerzony** o powody z tego
zadania, nie duplikowany — jeden słownik komunikatów dla całej sprzedaży. Uzasadnienie:
[ADR-012](../../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md).
Wołają je panel, przyszły portal wędkarza, sprzedaż wpisywana ręcznie oraz zadania 017, 018 i 019 —
żadne z nich nie buduje własnego wariantu.

Odpowiada na trzy pytania:

1. **Czy doba D na stanowisku P jest sprzedawalna?** — odpowiedź zawsze z **powodem odmowy**,
   nigdy samo „nie".
2. **Które cechy stanowiska P są zawieszone w dobie D?**
3. **Które doby w zakresie dat są sprzedawalne dla stanowiska P?**

Składa cztery warunki, w tej kolejności:

| Warunek | Skąd | Skutek |
|---|---|---|
| stan stanowiska | 014 | `withdrawn` → niesprzedawalne niezależnie od dat |
| okres sprzedaży | 015 | doba musi mieścić się w oknie okresu **w całości** |
| blokada `sale_blocked` | to zadanie | doba **przecinająca** okno blokady → niesprzedawalne |
| ograniczenie `attribute_suspended` | to zadanie | nie wpływa na sprzedawalność; zawiesza wskazaną cechę na czas przecięcia |

**Kolejność jest stała i wyraża TRWAŁOŚĆ przyczyny**, nie koszt zapytania: stan stanowiska →
okres sprzedaży → blokada. Odmowa niesie **pierwszy napotkany powód** — „to stanowisko jest
wycofane" jest dla pytającego ważniejsze niż „poza sezonem", bo pierwsze nie zmieni się jutro.
⚠️ Kolejność wyznaczona kosztem zapytania zmieniłaby komunikat widziany przez wędkarza przy
pierwszej optymalizacji, czyli zmieniłaby produkt przy okazji zmiany technicznej (ADR-012).

⚠️ **Zawieszenie cechy NIE jest odmową sprzedaży** i nie wchodzi do łańcucha powodów. Stanowisko
z zawieszonym pomostem nadal się sprzedaje — tylko bez pomostu. Usługa zwraca zawieszone cechy
osobno.

⚠️ **Asymetria reguł jest celowa i pochodzi z 015:** okres sprzedaży mówi, co wolno sprzedać, więc
wymaga **zawierania**; blokada mówi, co jest wyłączone, więc wystarczy **dowolne przecięcie**.
Dzięki temu nie da się sprzedać doby wpadającej w zakaz choćby na godzinę.

### Reguły zachowania

- **`attribute_suspended` wymaga wskazania cechy, a `sale_blocked` nie może jej wskazywać.**
- **Zawiesić można wyłącznie cechę typu `flag`.** Zawieszenie odległości do parkingu albo głębokości
  przy brzegu nie ma znaczenia — wyłączyć da się to, co stanowisko ma albo czego nie ma.
- **Zawieszenie nie zmienia wartości cechy na stanowisku.** Po upływie okresu wszystko wraca samo,
  a informacja o tym, które stanowiska daną cechę naprawdę mają, nie zostaje utracona. To jest
  warunek, bez którego ograniczenie byłoby nieodwracalne.
- **`ends_on` może być puste** — wpis obowiązuje do odwołania.
- **`ends_on` nie może być wcześniejsze niż `starts_on`.**
- **Zbiór nie może być pusty.**
- **Wszystkie stanowiska w zbiorze należą do tego samego łowiska co wpis.**
- **Blokada nie unieważnia sprzedanej rezerwacji sama z siebie** (G8). Przy kolizji panel pokazuje
  ostrzeżenie z liczbą objętych rezerwacji, a odwołanie wymaga świadomej decyzji właściciela
  i oznacza pełny zwrot. ⚠️ Model rezerwacji jeszcze nie istnieje, więc w tym zadaniu powstaje
  **miejsce na to sprawdzenie i jego komunikat**, a nie samo odwoływanie.
- **Ostrzeżenie przy zakładaniu stanowiska w trakcie trwającej blokady całościowej.** Skutkiem
  materializowania zbioru jest to, że stanowisko dodane później nie wchodzi do istniejącej blokady.
  Panel ma o tym powiedzieć przy zapisie nowego stanowiska, wskazując, które wpisy go nie obejmują.

### Zasoby Filamenta

| Zasób | Panel | Wzorzec |
|---|---|---|
| `AvailabilityBlockResource` (nowy) | właściciel i administrator | jak `PositionResource`: `shouldRegisterNavigation()` = `false`, zawężenie przez `Helper::scopeToOwnedFisheries()` |
| `AvailabilityBlocksRelationManager` (nowy) | właściciel | zakładka huba, jak `PositionsRelationManager` |
| `PositionResource` (zmiana) | oba | ostrzeżenie o nieobjęciu przez trwającą blokadę całościową przy zapisie nowego stanowiska |

**Formularz prowadzi przez wybór zbioru:** sposób wyboru → kryterium → **lista objętych stanowisk
z licznikiem i przyciskiem przeliczenia**. Lista jest edytowalna ręcznie po przeliczeniu — kryterium
podpowiada, nie przesądza.

⚠️ **Nowy zasób wymaga dopisania uprawnień do ręcznej listy w `tests/TestCase.php`.**
`createSuperAdmin()` zakłada uprawnienia z własnej listy literałów (`autoryzacja.md` §2), więc bez
wpisu `*:availability_block` administrator dostaje 403 na stronie, która w przeglądarce działa.
To jest **czwarty** wyzwalacz T3 w tym zadaniu — przewidziany, nie odkryty w trakcie.

Model `AvailabilityBlock` z `SoftDeletes`, `LogsActivity`, `HasFactory`; relacje
`belongsTo(Fishery)`, `belongsTo(PositionAttribute)`, `belongsToMany(Position)`. Polityka i uprawnienia
Shielda zgodne ze wzorcem pilnowanym przez `ShieldPermissionNamesTest` — model ma własny zasób, więc
`shield:generate` ma z czego wytworzyć uprawnienia (niezmiennik „polityka odpowiada zasobowi jeden do
jednego" z zadania 014). Tabela pośrednia `availability_block_position` polityki nie dostaje.

### Tłumaczenia

Etykiety pól, nazwa zasobu, etykiety wartości obu enumów oraz komunikaty odmowy w `lang/pl.json`.
Komunikat odmowy widzi wędkarz, więc jest częścią interfejsu, a nie treścią operatora.

## Kryteria akceptacji

- [ ] Migracje przechodzą w obie strony.
- [ ] Wpis `sale_blocked` z wyborem „całe łowisko" materializuje wszystkie stanowiska łowiska jako
      listę; dodanie stanowiska po jego założeniu **nie** rozszerza zbioru, a panel o tym ostrzega.
- [ ] Wpis `attribute_suspended` bez wskazanej cechy jest odrzucany; wpis `sale_blocked` ze wskazaną
      cechą jest odrzucany.
- [ ] Próba zawieszenia cechy typu `number` albo `choice` jest odrzucana.
- [ ] Zawieszenie cechy nie zmienia jej wartości na żadnym stanowisku — po upływie okresu odczyt
      wraca do stanu sprzed ograniczenia bez żadnej operacji.
- [ ] Zbiór pusty jest odrzucany; stanowisko z innego łowiska jest odrzucane.
- [ ] `ends_on` wcześniejsze niż `starts_on` jest odrzucane; puste `ends_on` zapisuje się.
- [ ] Doba **przecinająca** okno blokady jest niesprzedawalna, także gdy przecięcie obejmuje tylko
      część doby.
- [ ] Doba **stykająca się** z oknem blokady, ale go nieprzecinająca, pozostaje sprzedawalna.
- [ ] Stanowisko `withdrawn` jest niesprzedawalne niezależnie od okresów i blokad.
- [ ] Doba poza okresem sprzedaży jest niesprzedawalna, nawet gdy żadna blokada jej nie dotyczy.
- [ ] **Każda odmowa niesie powód**, a powód rozróżnia trzy przyczyny odmowy: stan stanowiska, brak
      okresu sprzedaży i blokadę. Zawieszone cechy wracają **osobno**, bo zawieszenie nie jest
      odmową sprzedaży — stanowisko z zawieszonym pomostem nadal się sprzedaje, tylko bez pomostu.
- [ ] Gdy zachodzi więcej niż jedna przyczyna, odmowa niesie **pierwszą w ustalonej kolejności**:
      stan stanowiska → okres sprzedaży → blokada. Stanowisko wycofane ORAZ poza sezonem raportuje
      stan stanowiska, nie sezon.
- [ ] Usługa dostępności jest **jedynym** miejscem liczącym dostępność; panel i zasoby wołają ją,
      a nie powtarzają warunków.
- [ ] Usługa **woła `FishingDayCalendar`** zamiast powtarzać reguły granic — zmiana godziny doby
      albo okresu sprzedaży zmienia jej wynik bez dotykania kodu tego zadania.
- [ ] `ShieldPermissionNamesTest` jest zielony, a uprawnienia nowego zasobu są dopisane do listy
      w `tests/TestCase.php`.
- [ ] Właściciel nie widzi ani nie edytuje wpisów cudzego łowiska.
- [ ] Zielony zakres T2 zadeklarowany niżej.
- [ ] **Pełny pakiet testów ODROCZONY** do punktu kontrolnego A — odroczony, nie pominięty.

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="PositionAvailabilityTest|AvailabilityBlockTest|PositionResourceTest|ActivityLoggingTest|ShieldPermissionNamesTest|AdminPanelTest|OwnerPanelTest|HelperFisheryAccessTest"`
- **Uzasadnienie:** zadanie trafia w **cztery** wyzwalacze T3 z `CLAUDE.md` — migracje, nowa polityka
  wraz z uprawnieniami Shielda, `OwnerPanelProvider` oraz `tests/TestCase.php`.
  ⚠️ Tylko **panel właściciela** trzyma jawną listę zasobów (`->resources([...])`); panel
  administratora odkrywa je katalogiem (`discoverResources`), więc widzi nowy zasób sam i jego
  providera zadanie nie dotyka. Filtr obejmuje `AdminPanelTest` i `OwnerPanelTest`, czyli dokładnie
  to, co chroni tę zmianę. Odstępstwo jest **pakietowe, nie punktowe**: rozdział 14.2
  [wymagań](../../project/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md) ustala T2 dla zadań 014–021 i pełny
  pakiet na trzech punktach kontrolnych; to zadanie **domyka punkt A**, więc pełny pakiet biegnie
  bezpośrednio po nim.
  ⚠️ Nazwy dwóch pierwszych klas w filtrze są **propozycją** — jeśli implementacja nazwie je inaczej,
  poprawia filtr razem z nimi, zamiast zostawiać komendę, która cicho nic nie uruchamia.
  ⚠️ Żadne z zadań 014–016 nie trafia do `tasks/implemented/`, dopóki punkt A nie jest zielony.

## Zakres wyłączeń

- **Odwoływanie rezerwacji i zwroty** — płatności i rezerwacje są poza iteracją; powstaje miejsce na
  sprawdzenie kolizji i jego komunikat, nie sama operacja.
- **Automatyczne powiadamianie kupujących** przy założeniu blokady na sprzedany termin — zapisane
  jako pomysł przy decyzji D6, świadomie poza zakresem.
- **Automatyczne przenoszenie rezerwacji** na inny termin.
- **Reguły długości pobytu, okna wcześniejszej sprzedaży, horyzont** — zadanie 017.
- **Cennik** — zadanie 018. Dostępność nie zna cen.
- **Kalendarz podglądowy** pokazujący skutek ustawień doba po dobie — zadanie 019; korzysta
  z usługi powstającej tutaj.
- **Filtrowanie i prezentacja w portalu wędkarza.**
- **Bufor wydajnościowy dla wyliczeń dostępności** — dostępność wylicza się z rekordów przy każdym
  pytaniu i nie ma kolumny, która by ją buforowała. Gdyby bufor kiedyś był potrzebny, jest to nowa
  decyzja z własnym uzasadnieniem pomiarowym, a nie rozwinięcie tego zadania.

## Zmiany dokumentacji

- [x] `docs/conventions/panel-wlasciciela.md` — blokady i ograniczenia jako jeden wpis ze skutkiem;
      zbiór materializowany; ostrzeżenie przy zakładaniu stanowiska
- [x] `docs/conventions/dostepnosc.md` — **nowy plik**: niezmiennik „jedno źródło prawdy
      o dostępności", kolejność składania czterech warunków, kształt odmowy oraz granica między
      `FishingDayCalendar` a usługą dostępności — niezmiennik + odsyłacz do ADR-012.
      ⚠️ Osobny plik, bo reguła **nie należy do żadnego panelu**: wiążą się nią portal wędkarza,
      cennik (018) i kalendarz (019). Przy okazji przenieś tam sekcję §7 „Doba wędkarska i okresy
      sprzedaży" z `panel-wlasciciela.md` i zostaw w niej odsyłacz — dziś reguły sprzedaży są
      w pliku o panelu, co było najbliższym dostępnym miejscem, a nie właściwym
- [x] `README.md` — bez zmian
- [x] `CLAUDE.md` — bez zmian
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne

- Laravel 13, Filament 5, PHP 8.4.
- **Zadanie idzie po 015 i po 014** — potrzebuje pojęcia doby, okresów sprzedaży, stanu stanowiska
  i słownika cech. Domyka punkt kontrolny A. Reguły granic dat pochodzą z
  [ADR-010](../../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md) i nie są tu ustalane na nowo.
- **Osobny plik migracji na każdą tabelę**: `availability_blocks` i `availability_block_position`.
- Wyliczenia dat idą przez pojęcie doby z zadania 015 i w strefie czasowej łowiska — to zadanie
  **nie liczy dób po swojemu**.
- Nazwy tabel, kolumn, klas i tras po angielsku; dokumentacja po polsku.
- Testy biegną na MySQL-u w schemacie `lowiska_test`.

## Rozstrzygnięcia

- **Nowa usługa `PositionAvailability` KOMPONUJE `FishingDayCalendar`, nie zastępuje go.** Kalendarz
  z zadania 015 zostaje przy tym, co ustala ADR-010 — wyznacza doby i stosuje reguły granic; nowa
  usługa dokłada stan stanowiska i blokady i jest jedynym wejściem dla panelu, portalu, cennika
  i kalendarza podglądowego. Bez tego rozstrzygnięcia powstałyby dwa byty, z których każdy mógłby
  nazywać siebie jedynym źródłem prawdy, a różnica między nimi jest niewidoczna w nazwie.
  Enum `SaleUnavailabilityReason` **rozszerzamy**, nie duplikujemy.

- **Byt nazywa się `AvailabilityBlock`**, jeden model z polem `effect` rozróżniającym skutek. Dwa
  osobne modele dublowałyby politykę, zasób i formularz dla bytu o IDENTYCZNYM kształcie — a to
  właśnie była przesłanka całego zadania. Dokumentacja mówi „blokada" albo „ograniczenie" zależnie
  od skutku, niezależnie od nazwy klasy.

- **Zawiesić można wyłącznie cechę typu `flag`.** Zawiesza się to, co stanowisko MA albo czego NIE MA
  („tu obecnie nie wjedziesz"); liczba i wybór z listy opisują rzeczywistość, która nie znika na dwa
  tygodnie. Zawieszenie cechy liczbowej z wartością zastępczą byłoby **nadpisaniem**, nie
  wyłączeniem — czyli innym pojęciem niż to, które opisuje zadanie. Wąski zakres łatwo później
  rozszerzyć; odwrotnie już nie.

- **`availability_blocks.fishery_id` jest wymagany i kasuje się kaskadowo** — trzeci raz ta sama
  przesłanka co przy `sale_periods` (015) i `position_groups` (014): wpis nie ma wartości
  historycznej ani ścieżki dostępu poza łowiskiem. Wyłamuje się wyłącznie `positions`, bo wisi
  w tabelach pośrednich pozwoleń i usług, więc po odcięciu wciąż coś znaczy.

- **Hub z zakładkami zastąpiony sub-nawigacją rekordu — decyzja podjęta PO implementacji.**
  Ekran „Sprzedaż i sezony" z zadania 015 dostał akcję nagłówka zamiast zakładki, bo pasek
  zakładek buduje `Livewire::make($relationManagerClass, …)` i strony z własną trasą nie
  przyjmuje. Przy trzecim ekranie wyszło, że to nie skaluje się: makieta zapowiada pięć
  kolejnych ekranów konfiguracyjnych, czyli pięć kolejnych przycisków obok „Edytuj".
  Rozróżnienie na listy i ustawienia okazało się artefaktem ograniczenia frameworka,
  a nie właściwością produktu. Wszystkie ekrany łowiska są teraz stronami
  w `FisheryResource::getRecordSubNavigation()` — jedna nawigacja, jak w makiecie.
  Uzasadnienie i to, co przestało obowiązywać:
  [ADR-006, aktualizacja z zadania 016](../../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md).
  ⚠️ Skutek uboczny wart odnotowania: zniknął parametr `?relation=N`, a z nim pułapka
  „przestawienie kolejności zakładek przekierowuje zapis na cudzą listę i nic nie pęka".

- **Niezmiennik o dostępności ląduje w NOWYM pliku `docs/conventions/dostepnosc.md`**, nie w pliku
  o panelu. Reguła nie należy do żadnej powierzchni panelu — wiążą się nią portal wędkarza, cennik
  i kalendarz. Przy okazji przenosi się tam sekcja o dobie i okresach sprzedaży, dziś zaparkowana
  w `panel-wlasciciela.md` §7 za brakiem lepszego miejsca.

## Powiązane ADR-y

- [ADR-012 — Jedno źródło prawdy o dostępności: skład warunków i kształt odmowy](../../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md)
  — **Decyzja do wypełnienia przez autora.** Obejmuje trzy rzeczy naraz, bo każda osobno nie ma
  sensu: gdzie usługa mieszka wobec `FishingDayCalendar`, w jakiej kolejności składa warunki
  i co zwraca przy odmowie.
- [ADR-010 — Doba wędkarska jako przedział czasu](../../adr/ADR-010-doba-wedkarska-jako-przedzial-czasu.md)
  — to zadanie **korzysta** z reguły przecięcia dla blokad, nie ustala jej na nowo. ADR-010 zostaje
  nietknięty: jego zakres to doby i granice, nie skład dostępności.
- [ADR-006, aktualizacja z zadania 015](../../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md)
  — wpisy o dostępności są **listą rekordów**, więc idą RelationManagerem, a nie stroną ustawień.

## Otwarte pytania dla `/review-task` — zamknięte

1. **Jedno źródło prawdy o dostępności** → [ADR-012](../../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md).
   Spełnia wszystkie trzy warunki; przesądził **koszt odwrócenia** — odwrócenie oznacza przepisanie
   każdego miejsca, które pyta o dostępność, a takich miejsc przybywa z każdym kolejnym zadaniem
   pakietu. ADR objął też kolejność składania warunków i kształt odmowy, bo oderwane od pytania
   „gdzie to mieszka" nie dają się rozstrzygnąć.
2. **Nazwa bytu w kodzie** → rozstrzygnięcie w treści zadania (`AvailabilityBlock`). Anty-sygnał
   z `CLAUDE.md`: nazwa, odwracalna przemianowaniem klasy i tabeli.
3. **Czy zawieszać wolno wyłącznie cechy `flag`** → rozstrzygnięcie w treści zadania (tak).
   Nie ADR: zakres da się rozszerzyć bez migracji danych, a uzasadnienie („wyłączenie, nie
   nadpisanie") mieści się w jednym zdaniu.

Przy przeglądzie doszły dwa rozstrzygnięcia spoza listy: wymagalność klucza obcego (kaskada, jak
w 014 i 015) oraz **miejsce na niezmiennik o dostępności** — nowy plik `docs/conventions/dostepnosc.md`
zamiast kolejnej sekcji w pliku o panelu.

⚠️ Przy przeglądzie wyszła też rzecz, której zadanie nie przewidywało: nowy zasób Filamenta wymusza
dopisanie uprawnień do ręcznej listy w `tests/TestCase.php`, czyli **czwarty** wyzwalacz T3.
Dopisane do wymagań, żeby implementacja nie odkrywała tego przez 403 w teście.
