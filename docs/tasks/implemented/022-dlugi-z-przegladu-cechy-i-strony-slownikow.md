# 022 — Długi z przeglądu: los wartości cech i nieosiągalne strony słowników

> Numer **022**, nie 018: numery 018–021 są zarezerwowane dla planu z rozdziału 14.1
> [wymagań](../../project/etapy/00-konfiguracja-sprzedazy/WYMAGANIA-SPRZEDAZ-KROTKOTERMINOWA.md). To zadanie leży poza pakietem 014–021
> i poza jego punktami kontrolnymi.

## Opis problemu

Przegląd implementacji z 2026-09-20 (zakres `origin/dev..HEAD`, zadania 013–016 plus zmiany
układu paneli) zgłosił dwadzieścia sześć uwag. Większość naprawiono w tej samej sesji. Tu zostają
trzy, które wymagały decyzji, a nie poprawki.

Żadna nie jest luką bezpieczeństwa ani regresem: aplikacja działa, pełny pakiet jest zielony
(268 testów / 780 asercji), `composer audit --locked` czysty.

⚠️ **Dwie pierwotne pozycje tego zadania zostały OBALONE** przy `/review-task` i nie ma ich
w wymaganiach. Zostawiam ustalenia, bo opisują zachowanie Filamenta, na którym oparte są
dzisiejsze bramki:

| Hipoteza z przeglądu | Sonda | Wynik |
|---|---|---|
| Komunikat walidacji cech nie dociera do pola | obca opcja przez formularz stanowiska | Filament odrzuca **wcześniej**: błąd ląduje pod `data.position_attributes.<id>` z czytelną treścią |
| Nieznane ID cechy blokuje cały zapis | cecha skasowana przy otwartym formularzu | zapis przechodzi normalnie, zero błędów, zapisany tylko wiersz cechy istniejącej |

Powód wspólny: **Filament przelicza schemat przy zapisie**, więc pole usuniętej cechy już nie
istnieje, a jej klucz jest wycinany ze stanu, zanim dotrze do kodu zapisu.

### A. Osierocone wartości cech zostają w bazie — i tak ma być

`PositionAttribute` kasuje się **miękko**, a `position_attribute_values` kaskaduje wyłącznie przy
twardym usunięciu. Po usunięciu cechy ze słownika wiersze wartości zostają.
`PositionResource::getEloquentFormData()` je odfiltrowuje, więc formularz edycji stanowiska
działa. Brakuje **zapisanej reguły** — dziś `panel-admina.md` §5 o losie wartości milczy, więc
następna osoba może uznać osierocone wiersze za defekt i „posprzątać" je bez decyzji.

### B. `assertValid()` w `mutateFormDataBefore*` jest ścieżką nieosiągalną

`CreatePosition` i `EditPosition` wołają `PositionAttributeWriter::assertValid()` przed zapisem
rekordu. Sondy wyżej pokazują, że **nie da się go odpalić przez formularz**: wartość niezgodną
z typem Filament odrzuca własną walidacją pola, a klucza bez pola nie przepuszcza. Kosztuje
zapytanie do słownika przy każdym zapisie stanowiska i nie daje nic.

⚠️ **Bramka w samym writerze to co innego i zostaje nietknięta** — jest osiągalna z akcji
zbiorczej i pokryta trzema testami, które czerwienieją po jej zdjęciu (ADR-011).

### C. Cztery słowniki mają nieosiągalne strony

`ManageRecords` obsługuje tworzenie i edycję w modalu, więc zarejestrowana obok strona jest
martwa i jej `getRedirectUrl()` nigdy się nie wykona. Stan zastany — sprawdzony przez wszystkie
szesnaście zasobów, poza tymi czterema nie ma innych naruszeń:

| Zasób | Strona `index` | Martwe strony |
|---|---|---|
| `ConvenienceResource` | `ManageConveniences` | `create` |
| `FisheryTypeResource` | `ManageFisheryTypes` | `create` |
| `FishResource` | `ManageFish` | `create`, `edit` |
| `FishingMethodResource` | `ManageFishingMethods` | `create`, `edit` |

⚠️ **Wszystkie cztery testy tych słowników tworzą rekord WŁAŚNIE przez martwą stronę** i sprawdzają
jej przekierowanie (`ConvenienceResourceTest` i trzy bliźniacze, wzorzec z zadania 011). Usunięcie
stron bez przepisania testów zdjęłoby jedyne pokrycie „da się dodać rekord do tego słownika" —
i to po cichu, bo pakiet zostałby zielony.

## Wymagania

- **A:** zapisać w `docs/conventions/panel-admina.md` §5 regułę: wartości cechy **przeżywają**
  miękkie usunięcie cechy ze słownika, formularz je filtruje, `restore()` przywraca je razem
  z cechą. Bez zmian w kodzie.
- **B:** usunąć wywołania `PositionAttributeWriter::assertValid()` z `CreatePosition::mutateFormDataBeforeCreate()`
  i `EditPosition::mutateFormDataBeforeSave()` wraz z komentarzami. Sprostować wpis
  w `panel-admina.md` §5, który dziś opisuje je jako „dodatek poprawiający kolejność komunikatu".
- **C:** usunąć sześć nieosiągalnych klas stron i ich wpisy z `getPages()` w czterech zasobach;
  `ManageRecords` z modalem zostaje. **Przepisać cztery testy** tak, żeby tworzyły rekord przez
  akcję modalną na stronie `Manage*`, a nie przez usuniętą stronę. Zaktualizować listę naruszeń
  w `panel-admina.md` §4.

## Kryteria akceptacji

- [ ] `panel-admina.md` §5 opisuje los wartości cechy po miękkim usunięciu cechy ze słownika.
- [ ] `panel-admina.md` §5 nie twierdzi już, że `assertValid()` w stronach poprawia kolejność
      komunikatu; opisuje, gdzie bramka faktycznie stoi.
- [ ] `grep -rn "assertValid" app/Filament/` nie zwraca nic; `grep -rn "assertValid" app/Services/`
      nadal zwraca writer.
- [ ] Testy cech przechodzą bez zmian w ich treści — usunięcie wywołań z formularza niczego nie
      psuje (to jest dowód, że ścieżka była nieosiągalna).
- [ ] Żaden z szesnastu zasobów w `app/Filament/Resources/` nie rejestruje strony `create`/`edit`
      obok indeksu opartego na `ManageRecords`.
- [ ] Każdy z czterech słowników ma test tworzący rekord **przez ścieżkę osiągalną z interfejsu**
      (akcja modalna na `Manage*`), a nie przez usuniętą stronę.
- [ ] Lista naruszeń w `panel-admina.md` §4 jest pusta.

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:**
  `docker compose exec app php artisan test --filter="ConvenienceResource|FisheryTypeResource|FishResource|FishingMethodResource|PositionResource|PositionAttribute|BulkAttributeAction|CrossTenantRelation"`
- **Uzasadnienie:** zadanie rusza strony czterech zasobów słownikowych oraz dwie strony
  `PositionResource`, a te drugie są wołane przez testy cech i akcji zbiorczej — same testy klas
  zmienionych nie wystarczą. **Nie trafia w żaden wyzwalacz T3**: bez migracji, bez polityk, bez
  providerów paneli, bez `tests/TestCase.php`, bez `composer.json`. Pełny pakiet **odroczony** na
  koniec sesji (`/review-implementation`, Krok 1).

## Zakres wyłączeń

- **Nie dotyka `PositionAttributeWriter::assertValid()`** — bramka w writerze zostaje. Usuwamy
  wyłącznie jej wywołania ze stron formularza.
- **Nie dotyka `PositionAvailability` ani `FishingDayCalendar`** — memoizacja z przeglądu jest
  zamknięta i pokryta testem stałej liczby zapytań.
- **Nie kasuje osieroconych wartości cech i nie dokłada migracji sprzątającej** — rozstrzygnięcie
  A mówi wprost, że mają zostać.
- **Nie zmienia `PositionAttributeResource`** — on już jest na `ListRecords` z pełnymi stronami,
  i to świadomie (repeater opcji).
- **Nie rusza `getCurrentPanel()` kontra `getCurrentOrDefaultPanel()`** — to, że zawężenia nie
  działają poza kontekstem panelu, jest opisane w `autoryzacja.md` §4 i jest osobną decyzją
  o zasięgu na kolejki i komendy konsolowe.

## Zmiany dokumentacji

- [ ] `docs/conventions/panel-admina.md` — §5: los wartości po usunięciu cechy **oraz** sprostowanie
      opisu `assertValid()`; §4: wyczyszczenie listy zasobów z nieosiągalnymi stronami
- [ ] `README.md` — nie dotyczy
- [ ] `CLAUDE.md` — nie dotyczy (reguły powierzchniowe idą do konwencji)
- [ ] `CHANGELOG.md` — **nie dotyczy**; wszystkie trzy punkty są wewnętrzne i niewidoczne dla
      użytkownika końcowego (skill `changelog` wprost wyklucza refaktoryzacje bez wpływu na UX)

## Ograniczenia techniczne

- Laravel 13, Filament 5, Pest 5; testy w kontenerze na MySQL-u w schemacie `lowiska_test`.
- Usunięcie stron **nie może zmienić uprawnień Shielda** — `shield:generate` wyprowadza je
  z zarejestrowanych **zasobów**, nie stron, więc listy uprawnień w `tests/TestCase.php` zostają
  bez zmian. Gdyby okazało się inaczej, tier rośnie do T3 i trzeba to zgłosić, a nie dopisać po cichu.
- Testy modalne pisze się przez `callAction('create', data: [...])` na komponencie strony
  `Manage*`, nie przez `Livewire::test(CreateX::class)`.

## Rozstrzygnięcia

- **A — wartości cechy PRZEŻYWAJĄ miękkie usunięcie cechy; nic nie kasujemy.** Miękkie usunięcie
  ma sens wyłącznie dlatego, że da się je cofnąć, a kasowanie wartości odbierałoby `restore()`
  sens. Formularz już je filtruje, więc nie ma czego naprawiać w kodzie — brakuje zapisanej reguły.
- **B — wywołania `assertValid()` znikają ze stron formularza.** Sonda wykazała, że ścieżka jest
  nieosiągalna (Filament odrzuca wcześniej i wycina klucze bez pól), a koszt to jedno zapytanie
  przy każdym zapisie stanowiska. Bramka w writerze zostaje, bo tam jest osiągalna.
- **C — cztery słowniki zostają przy modalach; kasujemy martwe strony.** Ich formularze mają po
  dwa–trzy pola i żadnych repeaterów, więc argument, który przeniósł `PositionAttributeResource`
  na pełne strony, tutaj nie obowiązuje. Zero zmian widocznych dla operatora, najmniejszy diff.

## Powiązane ADR-y

- [ADR-011](../../adr/ADR-011-ksztalt-wartosci-cech-stanowiska.md) — kształt wartości cech
  stanowiska; punkty A i B poruszają się w jego granicach i go nie odwracają. Punkt B **nie**
  osłabia wymagania „reguła wołana z każdego miejsca zapisu": miejsca zapisu to writer, a ten
  bramkę zachowuje.
