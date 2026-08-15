# 011 — Redirect po utworzeniu rekordu Filament — lista zamiast edycji

## Opis problemu

Filament domyślnie po zapisaniu nowego rekordu (`CreateRecord::getRedirectUrl()`) przenosi
użytkownika do widoku edycji dopiero co utworzonego rekordu. Dla większości zasobów w tym
projekcie to zachowanie jest niepotrzebnym krokiem pośrednim — użytkownik dodaje rekord i
oczekuje powrotu na listę, nie kolejnego formularza. Zachowanie ma się zmienić na przekierowanie
do listy (`index`), ale **nie wszędzie automatycznie** — kilka zasobów ma dziś celowo inną,
nieoczywistą ścieżkę po utworzeniu (wizard, redirect już nadpisany), którą trzeba uszanować.

## Wymagania

- Dla wszystkich zasobów panelu admina objętych tym zadaniem (lista niżej) — po zapisaniu
  nowego rekordu przekierowanie ma prowadzić do listy zasobu (`index`), nie do widoku edycji.
- W panelu właściciela zmiana obejmuje **wyłącznie** tworzenie usług dodatkowych
  (`AdditionalServiceResource`) i stanowisk (`PositionResource`) — pozostałe zasoby panelu
  właściciela (`CompanyResource`, `FisheryResource`, `LongTermPermitResource`) **nie są** objęte
  tym zadaniem.
- Zasada ma zostać zapisana w `CLAUDE.md` (sekcja „Konwencje kodu") jako obowiązująca konwencja
  dla standardowych CRUD-ów: nowy zasób Filamenta domyślnie przekierowuje po utworzeniu na listę,
  chyba że treść zadania świadomie ustali inaczej.

### Ważny fakt architektoniczny ustalony podczas wywiadu

`AdditionalServiceResource` i `PositionResource` **nie mają osobnych klas per panel** —
`OwnerPanelProvider` rejestruje bezpośrednio te same klasy z `app/Filament/Resources/`, które
widzi panel admina (podobnie jak `CompanyResource`, `FisheryResource`, `LongTermPermitResource`).
Nie istnieje mechanizm „inny redirect w adminie, inny w ownerze" bez jawnego rozgałęzienia
(`Helper::isOwnerPanel()`, wzorem `FisheryResource\Pages\CreateFishery`). Konsekwencje:

- Zmiana redirectu dla `AdditionalServiceResource` i `PositionResource` **automatycznie** obejmie
  oba panele — to jest spójne z wymaganiem (owner ma się zmienić, admin i tak dostaje ewidencję
  „zmień").
- `CompanyResource` i `FisheryResource` **są tą samą klasą** w obu panelach, mają już dziś
  celową, warunkową nawigację po utworzeniu w trybie wizard (`wizard=true` — kreator
  zakładania łowiska: Company → verify-company → Fishery) oraz odrębną, niewymuszoną ścieżkę
  poza wizardem. Ponieważ owner ma pozostać bez zmian dla tych dwóch zasobów, a nie ma osobnej
  klasy admina — **`CompanyResource` i `FisheryResource` są wyłączone z tego zadania w obu
  panelach**. Decyzja podjęta z użytkownikiem podczas `/create-task` (patrz „Rozstrzygnięcia").

### Ewidencja zasobów panelu admina (13 zasobów w `app/Filament/Resources/`)

| Zasób | Stan dziś | Decyzja tego zadania |
|---|---|---|
| `AdditionalServiceResource` | domyślny redirect do edycji | **zmień → index** (wymagane też przez panel ownera) |
| `CompanyResource` | wizard ma własną nawigację; poza wizardem domyślny redirect | **wyłączone z zadania** (współdzielona klasa z ownerem, patrz wyżej) |
| `ConvenienceResource` | domyślny redirect do edycji, brak wizarda/relacji wymagających edycji tuż po dodaniu | **zmień → index** |
| `CountryResource` | jw. | **zmień → index** |
| `CurrencyResource` | jw. | **zmień → index** |
| `FisheryResource` | wizard ma własną nawigację; poza wizardem domyślny redirect | **wyłączone z zadania** (współdzielona klasa z ownerem, patrz wyżej) |
| `FisheryTypeResource` | domyślny redirect do edycji | **zmień → index** |
| `FishingMethodResource` | jw. | **zmień → index** |
| `FishResource` | jw. | **zmień → index** |
| `LongTermPermitResource` | **już dziś** nadpisuje `getRedirectUrl()` → index (`fishery` w query) | **bez zmian** — już zgodny z docelowym zachowaniem |
| `PositionResource` | domyślny redirect do edycji | **zmień → index** (wymagane też przez panel ownera) |
| `StateResource` | domyślny redirect do edycji | **zmień → index** |
| `UserResource` | role przypisywane bezpośrednio w formularzu tworzenia (`CheckboxList` na relacji `roles`), brak potrzeby edycji tuż po dodaniu | **zmień → index** |

Żaden z ośmiu „prostych" zasobów (Convenience, Country, Currency, FisheryType, FishingMethod,
Fish, State, User) nie ma dziś logiki sugerującej potrzebę pozostania na edycji po utworzeniu
(brak wizardów, brak relation managerów wymagających uzupełnienia zaraz po zapisie) — stąd
propozycja blankietowej zmiany na wszystkich ośmiu. Jeśli w trakcie implementacji okaże się to
nietrafne dla któregoś z nich, zatrzymać się i zapytać, zgodnie z poleceniem użytkownika.

## Kryteria akceptacji

- [ ] Dla ośmiu prostych zasobów panelu admina (Convenience, Country, Currency, FisheryType,
      FishingMethod, Fish, State, User) — utworzenie rekordu przenosi na listę zasobu.
- [ ] Dla `AdditionalServiceResource` i `PositionResource` — utworzenie rekordu przenosi na
      listę zasobu, **w obu panelach** (admin i owner), z zachowaniem query stringa `fishery`
      analogicznie do istniejącego wzorca w `LongTermPermitResource` (breadcrumbs/filtr listy
      po łowisku).
- [ ] `CompanyResource` i `FisheryResource` — brak zmian zachowania (wyłączone z zadania).
- [ ] `LongTermPermitResource` — brak zmian (już zgodny).
- [ ] `CLAUDE.md`, sekcja „Konwencje kodu", zawiera nową regułę: standardowy CRUD Filamenta
      przekierowuje po utworzeniu rekordu na listę, nie na edycję; odstępstwo wymaga świadomej
      decyzji zapisanej w treści zadania.
- [ ] T1 uruchomiony i zielony (patrz „Zakres testów"); pełny pakiet **odroczony** na koniec
      sesji (`/review-implementation`, Krok 1).

## Zakres testów

- **Tier:** T1 — punktowy
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="AdditionalServiceResourceTest|PositionResourceTest|ConvenienceResourceTest|CountryResourceTest|CurrencyResourceTest|FisheryTypeResourceTest|FishingMethodResourceTest|FishResourceTest|StateResourceTest|UserResourceTest"`
  (dostosować nazwy filtrów do faktycznie utworzonych/zmienionych klas testowych — zadanie
  dodaje test na redirect dla każdego z dziesięciu zmienianych zasobów; jeśli takie klasy testowe
  nie istnieją, utworzyć je zamiast rozszerzać istniejące testy o inny cel).
- **Uzasadnienie:** Zmiana dotyka wyłącznie klas stron `Create*` (nadpisanie/dodanie
  `getRedirectUrl()`) — logika lokalna do każdej strony, bez wspólnej bazowej klasy ani
  zmiany kontraktu współdzielonego przez inne warstwy. Żaden z wyzwalaczy T3 z `CLAUDE.md`
  (provider panelu, `User`/polityki/Shield, migracje, `phpunit.xml`, `composer.json`,
  `Dockerfile`, `docker-compose.yml`) nie jest dotknięty. T2 nie jest potrzebny, bo żadna inna
  klasa nie woła bezpośrednio `getRedirectUrl()` tych stron — to punkt wejścia samego Filamenta
  po submit formularza, nie kontrakt wywoływany z kodu projektu.

## Zakres wyłączeń

- `CompanyResource` i `FisheryResource` — patrz uzasadnienie wyżej („Ważny fakt
  architektoniczny").
- Redirect po **edycji** (`EditRecord::getRedirectUrl()`) — poza zakresem, zadanie dotyczy
  wyłącznie tworzenia.
- Redirect po usunięciu/duplikacji rekordu — poza zakresem.
- Zmiana zachowania `getCreateAnotherFormAction()` („Zapisz i utwórz kolejny") — ten przycisk ma
  z definicji zostać na formularzu tworzenia; zadanie dotyczy wyłącznie ścieżki „Zapisz"
  (`getCreateFormAction()`).

## Zmiany dokumentacji

- [ ] `docs/conventions/panel-admina.md` — dopisać sekcję o konwencji redirectu po utworzeniu
      (odsyłacz do reguły w `CLAUDE.md` + lista wyjątków: Company/Fishery wyłączone, LongTermPermit
      już zgodny) i notatkę o współdzielonych klasach zasobów między panelami.
- [ ] `README.md` — bez zmian (nie dotyczy).
- [ ] `CLAUDE.md` — nowa reguła w sekcji „Konwencje kodu": standardowy CRUD przekierowuje po
      utworzeniu na listę, nie na edycję.
- [ ] `CHANGELOG.md` — wpis w changelogu.

## Ograniczenia techniczne

- Implementacja przez nadpisanie `getRedirectUrl(): string` na klasach `Create*` — wzorem już
  istniejącego `LongTermPermitResource\Pages\CreateLongTermPermit::getRedirectUrl()`, nie przez
  zmianę zachowania bazowego `CreateRecord` ani konfigurację globalną panelu (Filament nie
  udostępnia takiego przełącznika na poziomie `Panel`).
- Zachować istniejący wzorzec przekazywania `fishery` w query string dla zasobów zagnieżdżonych
  pod łowiskiem (`AdditionalServiceResource`, `PositionResource`, `LongTermPermitResource`).

## Rozstrzygnięcia

- **`CompanyResource` i `FisheryResource` wyłączone z zadania w obu panelach** — bo to jedna
  klasa współdzielona między adminem a ownerem, owner ma jawnie pozostać bez zmian dla tych
  dwóch zasobów, a wprowadzenie rozgałęzienia `Helper::isOwnerPanel()` tylko po to, by admin
  zachowywał się inaczej niż owner, uznano za nieproporcjonalny koszt względem tego zadania.
  Decyzja użytkownika podjęta podczas `/create-task` (opcja „Wyłącz z zadania").

## Powiązane ADR-y

-
