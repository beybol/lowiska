# ADR-017 — Dokumenty łowiska jako jedna tabela wersji, niezależna od cyklu życia łowiska

- **Status:** accepted
- **Data:** 2026-09-24
- **Zadanie:** [021 — Polityka zwrotu progowa, regulamin wersjonowany i wymagania wobec wędkarza](../tasks/implemented/021-polityka-zwrotu-i-regulamin-wersjonowany.md)

## Kontekst

Zadanie 021 wprowadza **dokumenty łowiska** — regulamin i politykę prywatności — jako wersjonowaną
treść z datą wejścia w życie, bez daty końca (D9, F10). Na tym kształcie oprą się trzy rzeczy spoza
zadania:

1. **Snapshot transakcji (G1, Z1).** Moduł rezerwacji ma zapisać przy zakupie, **którą wersję**
   regulaminu i polityki prywatności wędkarz zaakceptował. Transakcja wskaże więc wiersz wersji
   kluczem obcym — na zawsze.
2. **Regulamin platformy (TODO-1).** Wymagania (M8) mówią, że dojdzie jako **kolejny rodzaj
   dokumentu, bez nowej mechaniki** — ale to dokument **bez łowiska** (portal wobec wędkarza
   i wobec łowiska). Test Z4: dołożenie go nie może wymagać przepisania tego, co już sprzedane.
3. **Cykl życia łowiska.** Łowisko może zostać usunięte (dziś miękko). Wersja zaakceptowana przy
   zakupie musi przeżyć i to, i każdą operację na dokumencie, bo będą na nią wskazywać transakcje
   i spory.

Reguły, które kształt musi unieść: jedna obowiązująca wersja na rodzaj (najpóźniejsza data ≤ dziś
w strefie łowiska), zakaz dwóch wersji rodzaju z tą samą datą, nienaruszalność wersji od dnia
wejścia w życie, usuwanie wyłącznie wersji zaplanowanych.

Dlaczego to ADR, a nie rozstrzygnięcie w zadaniu:
- **Zasięg:** kształt wiąże przyszły model transakcji, regulamin platformy i każde miejsce, które
  zapyta o „dokument obowiązujący".
- **Koszt odwrócenia:** po pierwszej sprzedaży transakcje wskazują wiersze wersji. Zmiana kształtu
  oznacza migrację danych z przepięciem kluczy w sprzedanych rezerwacjach — dokładnie to, czego Z1
  zakazuje ruszać.
- **Uzasadnienie warte zapamiętania:** „dlaczego wersje nie kasują się z łowiskiem" i „dlaczego
  dokument ma nullowalne łowisko" nie wynikają z kodu zadania 021, tylko z modułów, które jeszcze
  nie istnieją.

## Alternatywy

### Opcja A — dokument + wersje, obie tabele niezależne od cyklu życia łowiska
Tabela **dokumentu**: właściciel (łowisko albo — w przyszłości — platforma, więc łowisko
nullowalne), rodzaj (enum), unikalność pary właściciel + rodzaj. Tabela **wersji**: dokument,
treść, data wejścia w życie, unikalność pary dokument + data. Obie z soft delete, **bez kaskady**
z łowiska; wersja nie ma operacji usunięcia fizycznego.

**Zalety:**
- Transakcja wskazuje **wersję**, a przez nią jednoznacznie rodzaj i właściciela — bez
  powielania tych danych w każdej wersji.
- Regulamin platformy to wiersz dokumentu z pustym łowiskiem — zero migracji istniejących danych.
- Reguły „jedna obowiązująca na rodzaj" i „różne daty w rodzaju" to zwykłe indeksy unikalne na
  poziomie dokumentu.
- Usunięcie łowiska nie narusza niczego, na co wskazują transakcje.

**Wady:**
- Dwie tabele i dwa modele zamiast jednego; zapytanie o obowiązującą wersję ma złączenie.
- Wiersz dokumentu bez treści może wyglądać na zbędny, dopóki istnieje tylko jeden właściciel.
- Ta sama pułapka MySQL (NULL ≠ NULL) dotyczy unikalności dokumentu platformy (łowisko puste +
  rodzaj). Tu jest jednak tania: dokumentów platformy jest kilka, zakłada je admin, a regułę da
  się pilnować w kodzie albo kolumną wyliczaną — i nie trzeba tego rozstrzygać w 021.

### Opcja B — jedna tabela wersji z polami właściciela i rodzaju
Każdy wiersz: łowisko (nullowalne), rodzaj, treść, data wejścia w życie. Unikalność: łowisko +
rodzaj + data.

**Zalety:**
- Jedna tabela, jeden model, najprostsze zapytanie.
- Wystarcza dla wszystkiego, co 021 buduje dziś.

**Wady:**
- Właściciel i rodzaj powielone w każdej wersji — zmiana czegokolwiek, co dotyczy dokumentu jako
  całości (np. nazwa własna dokumentu, flaga „wymagany przy zakupie"), dotyka wszystkich wersji,
  także tych wskazywanych przez transakcje.
- Unikalność z nullowalnym łowiskiem w MySQL nie działa dla regulaminu platformy (NULL ≠ NULL),
  więc reguła „różne daty w rodzaju" wymaga obejścia w kodzie.
- Trudniej wyrazić „dokument istnieje, ale nie ma jeszcze obowiązującej wersji" — stan, który
  panel ma pokazywać wprost.

### Opcja C — kaskadowe usuwanie z łowiskiem (dowolny z powyższych kształtów)
Klucz obcy do łowiska z `cascadeOnDelete()`.

**Zalety:**
- Brak osieroconych dokumentów po usunięciu łowiska.

**Wady:**
- Po pierwszej sprzedaży kaskada kasuje wersję wskazywaną przez transakcję — rozbija Z1.
- Dziś łowisko jest usuwane miękko, więc kaskada bazodanowa i tak by nie zadziałała; zostałaby
  jako pułapka na dzień, w którym ktoś wprowadzi usuwanie trwałe.

## Rekomendacja

**Opcja A.** Kosztuje jedną tabelę więcej dziś, a chroni dwie rzeczy, których nie da się odrobić
później: stabilne wskazanie wersji w transakcji (Z1) i regulamin platformy bez migracji (Z4).
Kaskada (opcja C) jest odrzucona niezależnie od kształtu — wersja zaakceptowana przy zakupie
musi przeżyć łowisko. To pokrywa się z rozstrzygnięciem autora zapisanym w zadaniu 021.

Konsekwencje dla implementacji:
- Unikalność: dokument (łowisko, rodzaj); wersja (dokument, data wejścia w życie).
- Brak `cascadeOnDelete()` z łowiska; wersja ma tylko soft delete i wyłącznie w stanie
  „zaplanowana".
- Pojęcie „wersja obowiązująca" liczy **jedna** metoda (na modelu dokumentu albo w usłudze),
  z „dziś" w strefie łowiska — nie zapisuje się go jako kolumny stanu.

## Decyzja

**Wariant opcji B — jedna tabela wersji, z łowiskiem wymaganym.** Decyzja autora z 24.09.2026.

**`documents`** — każdy wiersz jest wersją dokumentu łowiska:

| Kolumna | Znaczenie |
|---|---|
| `id` | `->id()` — **jedyny klucz główny** tabeli; wskazanie wersji, na które powoła się przyszła transakcja (G1, Z1) |
| `fishery_id` | **NOT NULL**, klucz obcy **bez** `cascadeOnDelete()` |
| `type` | enum rodzaju: regulamin, polityka prywatności |
| `title` | tytuł wersji |
| `effective_from` | data wejścia w życie; obowiązuje od północy w strefie łowiska |
| `content` | treść z edytora |
| `required_at_purchase` | flaga: wędkarz akceptuje dokument przy zakupie |
| `required_at_registration` | flaga: wędkarz akceptuje dokument przy rejestracji na łowisku — **znaczenie robocze**, do analizy przy części dla klientów (patrz niżej) |
| soft delete | wyłącznie dla wersji zaplanowanych |

- **Jedynym kluczem głównym obu tabel jest `id` (`->id()`)**; nie ma ani złożonego klucza
  głównego, ani indeksu unikalnego na kilku kolumnach (decyzja autora). Zakaz dwóch wersji jednego rodzaju z tą samą datą wejścia w życie pilnuje **reguła
  walidacji w `app/Rules/`**, która pomija wersje usunięte miękko — dzięki temu usunięcie
  zaplanowanej wersji zwalnia jej datę.
- **Nienaruszalne od dnia wejścia w życie:** `title`, `content`, `effective_from`, `type`.
  **Flagi wymagalności są edytowalne zawsze**, także w wersji obowiązującej (decyzja autora) —
  są ustawieniem sposobu użycia dokumentu, a nie jego treścią. Kopia wersji przenosi flagi.
  O tym, co jest wymagane, decydują flagi **wersji obowiązującej**. Snapshot transakcji musi
  więc zapisać, co wędkarz zaakceptował — z samej wersji tego nie odtworzy.
- **Znaczenie flagi „przy rejestracji na łowisku" jest robocze** — portal nie ma dziś pojęcia
  rejestracji na konkretnym łowisku (IDEA: jedno konto wędkarza, zakładane przy pierwszym zakupie).
  Flaga powstaje w 021, a jej znaczenie przeanalizujemy przy budowie części dla klientów (portal).
- Wersja obowiązująca jest liczona przez **jedną** metodę z „dziś" w strefie łowiska i nie jest
  zapisywana jako kolumna stanu.

**`document_templates`** — szablony z panelu admina: `id` (`->id()`, jedyny klucz główny), `type` (ten sam enum rodzaju), `name`, `content`.
Typ **porządkuje** szablony w adminie i **podpowiada**: przy nowej wersji lista pokazuje najpierw
szablony pasującego typu. Wybór dowolnego szablonu, także niepasującego, zostaje dozwolony.
Wersja dostaje **kopię** treści, bez klucza obcego do szablonu.

**Świadomy kompromis wobec rekomendacji (opcja A):**
- **Regulamin platformy (TODO-1) nie zmieści się w `documents` bez zmiany.** Będzie wymagał
  zmiany `fishery_id` na nullowalne (wtedy wraca pułapka unikalności z NULL, do rozwiązania
  kolumną wyliczaną albo w kodzie) albo osobnej tabeli. Obie drogi to migracje **bez przepisywania
  istniejących wierszy** i bez ruszania transakcji, więc koszt odłożenia jest mały.
- **Rodzaj, łowisko i flagi są powielone w każdej wersji.** Przy flagach to zaleta: zmiana
  wymagalności nie wymaga nowej wersji treści.
- **Brak kaskady zostaje niezależnie od tego, że łowiska są usuwane miękko** — chroni wersje
  wskazane przez transakcje na wypadek, gdyby kiedyś pojawiło się usuwanie trwałe.
