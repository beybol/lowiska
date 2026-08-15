# 006 — Wymuszenie `APP_LOCALE=pl` w pakiecie testów (naprawa 3 czerwonych testów paneli)

## Opis problemu

Od zadania 004 (przejście testów na MySQL, `docs/tasks/implemented/004-*.md`) pełny pakiet testów
ujawnia trzy czerwone testy, które wcześniej nigdy nie docierały do bazy (blokował je oddzielny
problem środowiskowy) i nie były widziane jako czerwone:

- `Tests\Feature\AdminPanelTest > Admin panel is accessible.`
- `Tests\Feature\OwnerPanelTest > Owner panel is accessible.`
- `Tests\Feature\OwnerPanelTest > Admin has access to owner panel.`

Wszystkie trzy padają na tej samej asercji: `->assertSee(__('Panel'))`.

**Zdiagnozowana przyczyna.** `__('Panel')` w tych testach zawsze zwraca literalny ciąg `"Panel"`
(katalog tłumaczeń aplikacji, `lang/pl.json`, nie ma dla niego wpisu — Laravel zwraca wtedy klucz
bez zmian, niezależnie od locale). Testy oczekują więc dosłownie ciągu „Panel" na stronie.

Ale to, co faktycznie renderuje się jako tytuł Dashboardu, pochodzi **z pakietu Filamenta**, nie
z katalogu tłumaczeń aplikacji:

- `vendor/filament/filament/resources/lang/pl/pages/dashboard.php` → `'title' => 'Panel'`
- `vendor/filament/filament/resources/lang/en/pages/dashboard.php` → `'title' => 'Dashboard'`

`.env` ma `APP_LOCALE=en`, więc strona faktycznie renderuje „Dashboard" — asercja szuka „Panel"
i nie znajduje.

**Dowód, że pozostałe asercje w tych samych testach są bezpieczne w obu językach** (`Companies`,
`Fish`, `Countries`, `States`, `Fishery types`, `Fishing methods`, `Users`, `Fisheries`,
`Conveniences`): wszystkie mają wpisy w `lang/pl.json`, a zasoby Filamenta budują etykiety nawigacji
przez ten sam `__()` (np. `CompanyResource::getNavigationLabel()`), więc test i renderowana strona
zawsze przechodzą przez ten sam katalog tłumaczeń pod tym samym locale — zgadzają się niezależnie
od tego, jakie locale akurat obowiązuje. Problem dotyczy **wyłącznie** tytułu Dashboardu, bo to
jedyny ciąg pochodzący z tłumaczeń pakietu, nie aplikacji.

**Dowód, że to nie regres zadania 004:** ani `phpunit.xml`, ani `docker-compose.yml` nigdy nie
ustawiały `APP_LOCALE`; `.env` miał `en` już wcześniej. Przy ręcznym uruchomieniu z
`APP_LOCALE=pl` wszystkich **20** testów paneli (`AdminPanelTest` + `OwnerPanelTest`) przechodzi.

Zadanie odłożone świadomie w `docs/tasks/implemented/004-*.md` (sekcja „Korekty po implementacji",
punkt 5) i w zadaniu 002 — oba miały inny zakres i nie powinny mieszać w sobie niezwiązanej naprawy.

## Wymagania

- Wymusić `APP_LOCALE=pl` w `phpunit.xml` przez `<env name="APP_LOCALE" value="pl" force="true"/>`
  — **decyzja podjęta przy `/create-task`**, zweryfikowana empirycznie (patrz „Opis problemu").
  ⚠️ `force="true"` jest obowiązkowe z tego samego powodu co reszta zmiennych `DB_*` w tym pliku
  (ADR-001): bez niego `APP_LOCALE=en` z `.env` przebije deklarację testową w kontenerze.
- Dopisać komentarz przy tej linii wyjaśniający **dlaczego akurat `pl`, nie `en`** — żeby
  przyszły „porządkujący" refaktor nie zamienił go bez zrozumienia przyczyny (dokładnie ten sam
  wzorzec ryzyka co w ADR-003 dla `trustProxies`).
- Zweryfikować, że pełny pakiet testów jest zielony po zmianie.

## Kryteria akceptacji

- [x] `phpunit.xml` deklaruje `APP_LOCALE=pl` z `force="true"`.
- [x] `AdminPanelTest > Admin panel is accessible.` — zielony.
- [x] `OwnerPanelTest > Owner panel is accessible.` — zielony.
- [x] `OwnerPanelTest > Admin has access to owner panel.` — zielony.
- [x] Pełny pakiet testów (`docker compose exec app php artisan test`) jest zielony w całości —
      **56/56, 191 asercji.** Pierwszy w pełni zielony przebieg w historii projektu.
- [x] Żaden z testów zielonych przed tą zmianą nie stał się czerwony — 56 to dokładnie 53 sprzed
      zmiany (zadanie 002) plus 3 naprawione tym zadaniem, zero nowych regresji.

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zmiana dotyka `phpunit.xml`, który jest na **liście obowiązkowych wyzwalaczy
  T3** w `CLAUDE.md`. Nie jest to zresztą przedmiotem wyboru z innego powodu: samo zadanie istnieje
  po to, by domknąć pełny pakiet do zieleni, więc pełny przebieg jest jedynym sensownym dowodem
  ukończenia.

## Zakres wyłączeń

- **Nie** zmieniamy domyślnego `APP_LOCALE` aplikacji (`.env`, `config/app.php`) — `en` zostaje
  locale'em domyślnym produkcji i środowiska deweloperskiego. Zmiana dotyczy **wyłącznie** locale
  używanego przez pakiet testów.
- **Nie** wprowadzamy `.env.testing` ani żadnego nowego pliku konfiguracyjnego — `phpunit.xml`
  z `force="true"` jest już ustalonym, jedynym miejscem wymuszania środowiska testowego
  (ADR-001, rozstrzygnięcie zadania 004: „nie tworzymy `.env.testing`").
- **Nie** poprawiamy testów, które asertują ciągi z katalogu tłumaczeń aplikacji (`Companies`,
  `Fish` itd.) — są już bezpieczne w obu językach, patrz dowód w „Opisie problemu".
- **Nie** wprowadzamy konwencji „testy nie mogą asertować tekstu zależnego od locale" jako reguły
  ogólnej — to inny, szerszy temat; to zadanie naprawia konkretny, zdiagnozowany przypadek.

## Zmiany dokumentacji

- [x] `docs/tasks/implemented/004-przebudowa-lokalnego-srodowiska-w-kontenerach.md` — sekcja
      „Korekty po implementacji", punkt 5: dopisano, że problem naprawiony tym zadaniem, wynik
      przekreślony zamiast usunięty.
- [x] `docs/tasks/implemented/002-zaufanie-do-proxy-za-cloud-run.md` — kryterium „pełny pakiet
      zielony" zaktualizowane: zastrzeżenie odziedziczone z 004 opisane jako zniknięte.
- [ ] `README.md` — bez zmian
- [ ] `CLAUDE.md` — bez zmian (to nie jest niezmiennik workflow, tylko jednorazowa naprawa stanu)
- [ ] ~~`CHANGELOG.md` — wpis w changelogu~~ **Skorygowane w implementacji: pominięte.** Zmiana
      dotyczy wyłącznie locale pakietu testów — `APP_LOCALE` aplikacji zostaje `en`, zero skutku
      widocznego dla użytkownika. Ten sam precedens co w zadaniu 004 dla zmian czysto
      deweloperskich.

## Ograniczenia techniczne

- Laravel 12, Filament 3.3, Pest 3 — bez zmian wersji.
- Pakiet testów biegnie na MySQL-u w schemacie `lowiska_test` (ADR-001); ta zmiana nie dotyka
  bazy ani żadnej z pięciu warstw izolacji, wyłącznie locale aplikacji w kontekście testowym.
- Aplikacja jest dwujęzyczna (PL/EN) od założenia — ta zmiana **nie** jest wyborem, który język
  jest „ważniejszy"; jest wyłącznie dopasowaniem locale testów do jedynego miejsca w kodzie
  (tytuł Dashboardu z pakietu Filamenta), które różni się między nimi w sposób niepokryty
  własnym katalogiem tłumaczeń.

## Rozstrzygnięcia

<!-- Punktowe decyzje ustalone przy /review-task: wygląd, copy, próg, nazwa, umiejscowienie.
     Wiążą implementację tak samo jak decyzje z ADR-ów, ale nie mają zasięgu poza zadaniem.
     Format: decyzja + jednozdaniowe uzasadnienie. -->
- **Naprawa przez `APP_LOCALE=pl` w `phpunit.xml`, nie przez poprawienie asercji w dwóch plikach
  testów.** Ustalone przy `/create-task`, zweryfikowane empirycznie (20/20 testów paneli zielonych
  przy tym locale). Powód: własne tłumaczenia aplikacji (`Companies`, `Fish`, `Countries`…) idą przez
  ten sam katalog po obu stronach (test i strona) i są bezpieczne w każdym locale — jedyny realny
  problem to tytuł Dashboardu z pakietu Filamenta, różny dla `pl`/`en`. Poprawianie dwóch plików
  testowych rozwiązałoby ten sam problem węziej, ale bez korzyści: i tak trzeba było wiedzieć,
  że przyczyna leży w locale, a `phpunit.xml` już dziś jest jedynym miejscem wymuszania środowiska
  testowego (ADR-001) — drugie takie miejsce (logika w samych testach) rozjeżdżałoby się z tym
  wzorcem.
- **Komentarz przy `APP_LOCALE=pl` jest obowiązkowy w implementacji** — z tego samego powodu co
  komentarz przy `trustProxies` w ADR-003: bez zapisanej przyczyny linia wygląda na przypadkową
  i ryzykuje „poprawienie" na `en` (zgodne z `.env`) bez zrozumienia konsekwencji.

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- **Brak.** Decyzja o locale testów jest odwracalna jedną linijką (`value="pl"` → `value="en"`
  w `phpunit.xml`) — wprost anty-sygnał z `CLAUDE.md` („wszystko, co odwracasz jedną linijką").
  Nie ustanawia też ogólnej reguły wiążącej przyszły kod (świadomie wykluczonej w „Zakresie
  wyłączeń": nie wprowadzamy konwencji „testy nie mogą asertować tekstu zależnego od locale").
  Kontrast z ADR-001 (silnik bazy) jest tu pouczający: tamta decyzja niosła ryzyko regresji
  na 25 migracjach i zmieniała semantykę całego pakietu; ta dotyczy jednego, już zdiagnozowanego
  ciągu znaków.
