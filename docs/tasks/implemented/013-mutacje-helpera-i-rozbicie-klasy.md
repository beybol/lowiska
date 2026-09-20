# 013 — Testy mutacyjne `Helper` i rozbicie klasy na dziedziny

## Opis problemu

`app/Helpers/Helper.php` urósł do **701 linii i 27 metod statycznych** (stan przy przeglądzie;
zadanie zakładało ~620 — klasa w międzyczasie jeszcze urosła), a od zadania
012 trzyma **niezmiennik bezpieczeństwa** panelu właściciela: `assertFisheryAccessOrAbort()`,
`forceVerifiedFishery()`, `scopeToOwnedFisheries()`, `findFishery()`. To dokładnie ta kategoria
kodu, dla której testy mutacyjne mają sens — ocalały mutant w bramce dostępu to realna luka,
a nie kosmetyka.

Problem: **mutacji tej klasy nie da się dziś uruchomić do końca.** Zmierzone w sesji zadania 012:

- Pełny pakiet testów jako baseline dla silnika mutacji: **~2,5 min**.
- Dwie próby przebiegu (`vendor/bin/pest --mutate --covered-only --class="App\Helpers\Helper"`,
  raz bez filtra, raz zawężony do nowego `HelperFisheryAccessTest`) **przekroczyły 10 minut
  i zostały przerwane bez wyniku**.
- Dla porównania `App\Rules\IbanValidation` — klasa pokryta szybkim testem `tests/Unit` —
  przeszła w **~8 min**, 131 mutantów, **MSI 100%**, zero ocalałych.

Przyczyna jest strukturalna, nie sprzętowa: `Helper` jest pokryty testami `Feature`, które
bootują panele Filamenta, więc koszt jednego mutanta to kilkadziesiąt sekund, a mutantów są
setki. Zadanie 012 dołożyło `tests/Feature/HelperFisheryAccessTest.php` (dziś **20 przypadków,
13,3 s** — zmierzone przy przeglądzie; zadanie notowało 17 i ~27 s)
wołający metody bramkujące bezpośrednio — to skróciło cykl, ale nie na tyle, żeby zmieścić
całą klasę w rozsądnym oknie.

## Wymagania

1. **Rozbić `Helper` wzdłuż granicy dziedzinowej**, na klasy w `app/Services/`.
   Podział wszystkich 27 metod — ustalony przy przeglądzie, nie do zgadywania:

   | Klasa w `app/Services/` | Metody |
   |---|---|
   | `FisheryAccess` | `findFishery`, `assertFisheryAccessOrAbort`, `scopeToOwnedFisheries`, `forceVerifiedFishery`, `isOwnerPanel`, `isAdminPanel` |
   | `FisheryNavigation` | `getFisheryTitle`, `fisheryBreadcrumbs`, `fisheryHubUrl`, `fisherySectionUrl`, `getListHeaderActionsForFishery`, `getEditFormActionsForFishery`, `getBackToFisheryManagementAction` |
   | `SharedFormComponents` | `getFisheryFields`, `getPriceInput`, `getRichEditorOptions`, `setAddress`, `getSocialAuthActions`, `fetchDataFromCSO` |
   | `DictionaryOptions` | `getSortedCountries`, `getCountryPrefixes`, `sortStates`, `sortedCompanies` |
   | `AdditionalServiceSync` | `syncAdditionalServices`, `extractAdditionalServices` |
   | `OwnerRoleProvisioner` | `addOwnerRole` |
   | — **do usunięcia** | `getFisheryManagementEditFormActions` |

   ⚠️ **`isOwnerPanel` i `isAdminPanel` idą RAZEM z bramkami**, mimo że pierwsza jest wołana
   także przez formularze. `scopeToOwnedFisheries()` jest fail-closed (`! isAdminPanel()`),
   a `autoryzacja.md` §4 wymaga, żeby obie połowy tego niezmiennika reagowały identycznie
   na nierozpoznany panel. Rozdzielone między klasy zaczną się rozjeżdżać.

   ⚠️ **`fetchDataFromCSO` NIE idzie do `CSOService`**, choć nazwa kusi. Metoda przyjmuje
   `Get`/`Set` Filamenta i ustawia stan formularza — to klej formularza, nie integracja.
   `CSOService` ma zostać wolny od zależności od Filamenta (`integracje.md`).

   ⚠️ **`getFisheryManagementEditFormActions()` jest martwa i zepsuta** — metoda **statyczna
   używająca `$this`**, więc wywołana rzuciłaby błąd. Nikt jej nie woła (zweryfikowane
   grepem przy przeglądzie), dlatego znika, a nie jest przenoszona.

2. **Test bramek zostaje w `tests/Feature`** i woła metody bezpośrednio, bez renderowania stron.
   ⚠️ Wcześniejszy zapis „docelowo do `tests/Unit`" jest **wycofany** — patrz „Rozstrzygnięcia".

3. **Uruchomić mutacje na `FisheryAccess` w budżecie 20 minut.** Zmieściły się → MSI i lista
   ocalałych mutantów (zabitych albo opisanych ze świadomej decyzji) lądują w tym zadaniu.
   Nie zmieściły się → w tym zadaniu ląduje **zmierzony powód**, a mutacje przechodzą do osobnego.
   ⚠️ Cel „zero ocalałych" obowiązuje **tylko wtedy, gdy przebieg się zakończy** — inaczej zadanie
   utknęłoby na narzędziu, a nie na kodzie.

4. **Przenieść wszystkie wywołania i usunąć `Helper` wraz z katalogiem `app/Helpers/`.**
   Fasada delegująca jest wykluczona — patrz „Rozstrzygnięcia".

## Kryteria akceptacji

- [x] `Helper` i katalog `app/Helpers/` **nie istnieją**; wszystkie wywołania przeniesione.
- [x] `getFisheryManagementEditFormActions()` usunięta, nie przeniesiona.
- [x] Klasa bramek (`FisheryAccess`) ma test wołający ją bezpośrednio, bez renderowania stron.
- [x] Mutacje: `docker compose exec app vendor/bin/pest --mutate --covered-only --class="App\Services\FisheryAccess"`
      uruchomione z **budżetem 20 minut**. Zmieściły się → MSI i ocalałe mutanty opisane w tym
      zadaniu. Nie zmieściły się → zapisany **zmierzony powód** (liczba mutantów, liczba testów
      pokrywających linie) i zadanie zamyka się bez MSI, a mutacje idą do osobnego zadania.
      ⚠️ To nie jest furtka: zapis powodu jest **obowiązkowy**, samo „nie wyszło" nie wystarcza.
- [x] Pełny pakiet testów zielony (patrz `## Zakres testów`).
- [x] Konwencje wskazują nowe nazwy klas: `autoryzacja.md` §4, `panel-wlasciciela.md` §2,
      `panel-admina.md` §4, `integracje.md`.

## Wyniki mutacji (implementacja, 2026-09-20)

**Przebieg się zakończył — to jest główny wynik tego zadania.** Przed rozbiciem ta sama klasa
nie domykała się w 10+ minutach i była przerywana bez rezultatu.

```
docker compose exec app vendor/bin/pest --mutate --covered-only --class="App\Services\FisheryAccess"
```

| Przebieg | Czas | Mutanty | Ocalałe | MSI |
|---|---|---|---|---|
| po rozbiciu klasy | 950 s | 41 | 15 | **63,41 %** |
| po dołożeniu dwóch testów pamięci podręcznej | 797 s | 41 | 11 | **73,17 %** |

Budżet 20 minut dotrzymany w każdym przebiegu (najdłuższy: 985 s).

### Co ocalałe mutanty ujawniły — i co z tego wynikło

Cztery zabite mutanty wskazywały **realną lukę**, nie kosmetykę: budowa klucza pamięci
podręcznej w `findFishery()` nie była pokryta żadnym testem. To dokładnie ten scenariusz,
przed którym ostrzega komentarz w kodzie — bez ID użytkownika w kluczu drugi użytkownik
dostaje z cache'u wpis pierwszego i **bramka przepuszcza go na cudze łowisko**, a pakiet
świeci na zielono. Dołożone testy:

- `the fishery cache never serves one owner the record of another`,
- `the fishery cache never serves one fishery in place of another`,
- `the unscoped variant is deliberate and does not share a cache entry with the scoped one`.

### Jedenaście ocalałych — uzasadnienie

⚠️ **`ConcatRemoveLeft` (linia 64) jest ZABITY, mimo że raport go nie odnotowuje.**
Zweryfikowane wprost: mutacja naniesiona ręcznie na plik powoduje, że test
`the fishery cache never serves one fishery in place of another` czerwienieje. Powtórzenie
przebiegu po wyczyszczeniu `/tmp` kontenera dało identyczny wynik, więc nie jest to cache —
to rozbieżność silnika Pesta w doborze testów pokrywających linię. **Nie wyciągaj z tej
pozycji wniosku, że klucz jest niepokryty.**

Pozostałe dziesięć to **mutanty równoważne** — zmieniają kod, nie zmieniając zachowania:

| Linia | Mutant | Dlaczego nie da się go zabić |
|---|---|---|
| 64 | `ConcatSwitchSides` ×2 | klucz jest nieprzezroczysty; liczy się wyłącznie jego **unikalność**, nie kolejność członów |
| 64 | `EmptyStringToNotEmpty` | wariant niezawężony dostaje inny sufiks, ale nadal **różny** od zawężonego — rozróżnialność zachowana |
| 72 | `RemoveEarlyReturn` | pominięcie odczytu z pamięci podręcznej daje ten sam wynik, tylko wolniej |
| 52 | `RemoveEarlyReturn` | bez wczesnego wyjścia `(int) 'abc'` daje `0`, zapytanie nic nie znajduje i metoda i tak zwraca `null` |
| 55, 104 | `RemoveIntegerCast` ×2 | deklaracja typu zwracanego `: int` i konkatenacja w kluczu dają ten sam efekt bez rzutowania |
| 101 | `RemoveFunctionCall` | usunięty `abort(404)` przepuszcza wykonanie do **drugiego** `abort(404)` niżej |
| 176, 181 | `RemoveNullSafeOperator` ×2 | `getCurrentOrDefaultPanel()` nigdy nie zwraca `null` w teście; zabicie wymagałoby symulacji stanu, który nie występuje |

⚠️ **Wniosek na przyszłość:** MSI tej klasy nie da się podnieść powyżej ~75 % bez testowania
stanów, które nie występują. Nie traktuj tej liczby jako celu — wartością było **odkrycie luki
w kluczu pamięci podręcznej**, a nie sam wskaźnik.

## Zakres testów

**Tier:** T3 — pełny pakiet.

**Uruchamiamy:** `docker compose exec app php artisan test`

**Uzasadnienie:** zadanie rusza `Helper`, czyli klasę wołaną z obu paneli, z polityk i ze stron
zasobów; dotyka też pośrednio ról i uprawnień Shielda (`addOwnerRole`). To trafia w listę
wyzwalaczy T3 z `CLAUDE.md` — tier nie jest tu przedmiotem wyboru.

## Zakres wyłączeń

- **Nie** obejmuje mutacji pozostałych klas (zasoby i strony Filamenta to szkielet deklaratywny
  o niskiej wartości mutacyjnej — patrz kryterium w komendzie `/review-implementation`).
- **Nie** obejmuje dodania PCOV/Xdebuga — PCOV **jest** w obrazie deweloperskim (zweryfikowane
  w sesji 012; notatka w komendzie `/review-implementation` mówiąca, że go nie ma, jest
  nieaktualna i wymaga poprawki przy okazji).
- Nie zmienia zachowania aplikacji — to refaktor plus testy.

## Zmiany dokumentacji

- `docs/conventions/autoryzacja.md` §4 — nazwy klas po rozbiciu.
- `docs/conventions/panel-wlasciciela.md` §2 — jw. ⚠️ Sekcja została **przepisana w zadaniu 016**
  (sub-nawigacja zamiast zakładek RelationManagerów) — aktualizuj jej dzisiejszą treść, nie tę,
  którą zapamiętało zadanie 013.
- `docs/conventions/panel-admina.md` §4 — przykład kodu woła `Helper::fisherySectionUrl()`.
- `docs/conventions/integracje.md` — nagłówek wskazuje dziś `app/Helpers/**`; po rozbiciu
  doprecyzować, która klasa jest integracją, a która nawigacją.
- `.claude/commands/review-implementation.md` — sprostować notatkę o braku sterownika pokrycia.
  ⚠️ Zweryfikowane przy przeglądzie: `php -m` w obrazie deweloperskim pokazuje **pcov**, więc
  notatka „obraz nie ma ani PCOV, ani Xdebuga" jest nieprawdziwa i blokuje mutacje w innych
  zadaniach.
- ⚠️ **ADR-006 zostaje NIETKNIĘTY**, choć wymienia `Helper::`. Przemianowanie klasy nie unieważnia
  decyzji, którą ten ADR zapisał; ADR-y opisują stan z chwili decyzji i zmienia się je wyłącznie
  sekcją „Aktualizacja". Nie przepisuj go przy okazji refaktoru.

## Ograniczenia techniczne

- Pest 5 (wbudowany silnik mutacji; Infection **nie** wchodzi w grę — nie mapuje mutantów na
  testy Pesta, bo Pest opakowuje klasy testowe we własną przestrzeń nazw).
- Testy biegną na MySQL-u w schemacie `lowiska_test`; obowiązuje pięć warstw izolacji z `CLAUDE.md`.

## Rozstrzygnięcia

- **Pełne przeniesienie: `Helper` znika, fasady nie ma.** Fasada delegująca zostawiłaby dwa
  wejścia do tej samej logiki — „drugi literał tej samej stałej", przed którym ostrzega
  `CLAUDE.md` — a migracji wywołań nikt by już potem nie dopisał. Ryzyko jest niższe, niż
  wygląda: zmiana jest mechaniczna, a 245 testów pokrywa oba panele.

- **Nowe klasy mieszkają w `app/Services/`**, zgodnie z regułą „logika obliczeniowa
  i walidacyjna ma jeden dom" z `CLAUDE.md`. Tam już są `FishingDayCalendar`,
  `PositionAvailability`, `PositionAttributeWriter` — bramki dostępu są tą samą kategorią.
  Katalog `app/Helpers/` znika razem z klasą.

- **Test bramek zostaje w `tests/Feature`.** ⚠️ `tests/Pest.php` wiąże `Tests\TestCase`
  i `RefreshDatabase` **wyłącznie z katalogiem `Feature`**; test w `Unit` dostałby goły
  `PHPUnit\TestCase` i **ominąłby bramkę bazy z `createApplication()`** — trzecią z pięciu
  warstw izolacji z `CLAUDE.md`. Bramki dostępu i tak odpytują bazę, więc testem jednostkowym
  nie będą. Zmierzony koszt (20 przypadków, 13,3 s) nie jest problemem.

- **Mutacje mają budżet 20 minut, a nie status warunku koniecznego.** Zadanie samo notuje, że
  przebieg zawężony do `HelperFisheryAccessTest` też przekroczył 10 minut — samo rozbicie klasy
  może więc nie wystarczyć, bo `--covered-only` uruchamia **wszystkie** testy pokrywające
  mutowaną linię, a bramki wołane są z każdego testu panelu właściciela. Refaktor ma wartość
  niezależnie od tego, czy silnik dowiezie; brak wyniku musi być **zmierzony i opisany**.

- **`getFisheryManagementEditFormActions()` jest usuwana.** Metoda statyczna używająca `$this`,
  bez ani jednego wywołania — wywołana rzuciłaby `Error`. To zadanie jest właściwym miejscem
  na jej usunięcie.

## Powiązane ADR-y

**Żaden ADR nie powstaje.** Rozbicie klasy na dziedziny spełnia dwa warunki z trzech —
zasięg (wiąże każde przyszłe „gdzie to dopisać") i uzasadnienie warte zapamiętania — ale
**nie spełnia trzeciego**: odwrócenie to przeniesienie metod między plikami, bez migracji
danych i bez łamania niezmiennika. Powód rozbicia jest już zapisany w „Opisie problemu"
(pomiary mutacji), a wynikowy niezmiennik — gdzie co mieszka — trafia do plików konwencji.

⚠️ [ADR-006](../../adr/ADR-006-natywne-komponenty-filamenta-zamiast-recznych-przeplywow.md)
wymienia `Helper::`, ale **nie jest tym zadaniem zmieniany** — patrz „Zmiany dokumentacji".
