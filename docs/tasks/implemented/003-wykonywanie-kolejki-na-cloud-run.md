# 003 — Wykonywanie zadań kolejkowanych po wdrożeniu na Cloud Run (importy Filamenta)

## Opis problemu

Aplikacja ma jedną realną zależność od kolejki: `CountryResource` udostępnia
`ImportAction::make()->importer(CountryImporter::class)`, a mechanizm importu Filamenta **nie
wykonuje importu w żądaniu** — dzieli plik na porcje i wysyła je do kolejki jako zadania
(`Filament\Actions\Imports\Jobs\ImportCsv`), a na koniec kolejkuje powiadomienie o wyniku.
Rekordy w tabelach `imports` / `failed_import_rows` (migracje `2025_07_22_1225*`) są śladem
dokładnie tego przepływu.

Domyślna konfiguracja to `QUEUE_CONNECTION=database`. Lokalnie kolejkę obsługuje osobny kontener
`queue` z Docker Compose, więc wszystko działa. Na Cloud Run **nie ma procesu, który mógłby ją
konsumować** — kontener obsługuje żądania HTTP i jest usypiany między nimi (staging zjeżdża do
zera instancji). Skutek po wdrożeniu:

- administrator wgrywa plik CSV, dostaje potwierdzenie przyjęcia importu,
- zadania trafiają do tabeli `jobs` i **nikt ich nigdy nie wykonuje**,
- nie pojawia się żaden błąd — import po prostu nigdy się nie kończy, a rekord w `imports`
  zostaje z zerowym postępem.

To jest cicha awaria funkcji administracyjnej, ujawniająca się dopiero przy pierwszym realnym
imporcie na wdrożonym środowisku. Kontenery `queue` i `scheduler` z `docker-compose.yml`
są elementem układu **lokalnego** — Cloud Run ich nie odtwarza.

⚠️ **Skorygowane po zadaniu 004:** `docker-compose.prod.yml` i `Dockerfile.prod` zostały usunięte
w zadaniu 004 — obraz produkcyjny buduje dziś cel `prod` jednego wieloetapowego `Dockerfile`
(FrankenPHP, ADR-002). Odniesienia do tych plików niżej w treści zadania są nieaktualne i poprawione.

## Wymagania

- Rozstrzygnąć i zaimplementować sposób wykonywania zadań kolejkowanych w środowiskach
  uruchamianych na Cloud Run (`staging`, `prod`). Dwa warianty do rozważenia — wybór należy do
  `/review-task`, patrz otwarte pytania:
  1. **`QUEUE_CONNECTION=sync` w środowiskach Cloud Run** — import wykonuje się w żądaniu.
     Bez dodatkowej infrastruktury i bez kosztu; ryzykiem jest limit czasu żądania na dużym pliku.
  2. **Osobny Cloud Run Job z `queue:work --stop-when-empty`**, wyzwalany cyklicznie przez Cloud
     Scheduler (mechanizm istnieje w warstwie infrastruktury — ADR-0012 w `gcp-foundation`).
     Więcej ruchomych części i osobny wpis w konfiguracji wdrożenia.
- Udokumentować wybrany wariant w `docs/operations/obraz-produkcyjny.md` wraz z granicą „tak jest
  lokalnie, tak jest na Cloud Run" — dziś ta różnica nie jest nigdzie zapisana i jest źródłem tej
  pomyłki. Krótki odsyłacz w `docs/operations/docker.md` (przy opisie lokalnej usługi `queue`)
  ma prowadzić czytelnika do pełnego opisu.
- Zapewnić, że wybrane rozwiązanie faktycznie domyka import: rekord w `imports` osiąga stan
  końcowy, a błędne wiersze lądują w `failed_import_rows`.
- Jeśli wybrany zostanie wariant 1: udokumentować **znany limit** (rozmiar pliku, po którym import
  przestaje mieścić się w limicie czasu żądania) zamiast udawać, że go nie ma.

## Kryteria akceptacji

- [x] Wariant jest wybrany ([ADR-004](../../adr/ADR-004-wykonywanie-kolejki-na-cloud-run.md), Opcja A
      — `sync`), zaimplementowany (dowód: test przechodzący pod tym sterownikiem) i opisany
      w `docs/operations/obraz-produkcyjny.md`, sekcja 8. Sama zmienna `QUEUE_CONNECTION=sync` dla
      Cloud Run żyje w konfiguracji wdrożenia (Secret Manager), nie w tym repozytorium — patrz
      sekcja 8, akapit „Ta zmienna żyje w konfiguracji wdrożenia".
- [x] Import listy krajów wykonuje się do końca w konfiguracji przewidzianej dla Cloud Run —
      `tests/Feature/CountryImporterTest.php`: `Import::completed_at` ustawione, wiersz nieprawidłowy
      trafia do `failed_import_rows`, wiersz prawidłowy tworzy rekord `Country`.
- [x] Konfiguracja lokalna (Docker Compose z kontenerem `queue`) działa bez zmian — nietknięta.
- [x] Zakres testów zadeklarowany niżej (T2) jest zielony — 3/3 (`CountryImporterTest`,
      `AdminPanelTest`).
- [x] Pełny pakiet testów jest **odroczony** na koniec sesji (`/review-implementation`, Krok 1).
      Premisa „wariant 2 dotyka `Dockerfile.prod`/entrypointu" była błędna nawet przed usunięciem
      tych plików w zadaniu 004 — Cloud Run Job nadpisuje **komendę** kontenera przy tym samym
      obrazie (`docker/prod-entrypoint.sh` kończy się `exec "$@"`), a definicja samego zadania
      i wyzwalacza Cloud Scheduler żyje w potoku wdrożeniowym/`gcp-foundation`, poza tym
      repozytorium. Tier zostaje T2 niezależnie od wyboru wariantu.

## Zakres testów

- **Tier:** T2 — zależności
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="CountryImporter|CountryResource|AdminPanelTest"`
- **Uzasadnienie:** zmiana dotyka sposobu wykonywania zadań kolejkowanych, czyli kontraktu
  używanego przez każdą akcję importu, a nie pojedynczej metody. T1 pokryłby samą klasę importera
  i przeoczył to, co faktycznie jest tu przedmiotem zadania — czy import **domyka się** w ścieżce
  panelu. Uwaga: `phpunit.xml` ustawia `QUEUE_CONNECTION=sync` dla całego pakietu, więc testy
  z natury biegną w wariancie synchronicznym — test ma weryfikować dokończenie importu, a nie
  udowadniać istnienie workera.

## Zakres wyłączeń

- **Nie** wprowadzamy Redis ani zewnętrznego brokera (`gcp-foundation`, ADR 0008 — runtime jest
  celowo minimalny kosztowo; sterownik `database` zostaje).
- **Nie** budujemy harmonogramu zadań cyklicznych (`schedule:run`) — `routes/console.php` nie
  rejestruje dziś żadnej komendy poza stockowym `inspire`, więc nie ma czego uruchamiać.
- **Nie** przepisujemy `CountryImporter` ani nie zmieniamy zakresu importowanych danych.
- **Nie** usuwamy kontenerów `queue`/`scheduler` z konfiguracji lokalnej.
- **Nie** obejmujemy tym zadaniem wysyłki maili (2FA, weryfikacja adresu, reset hasła) — te
  powiadomienia nie implementują `ShouldQueue`, więc idą synchronicznie niezależnie od wyboru.

## Zmiany dokumentacji

- [x] `docs/conventions/` — bez nowego pliku; rozstrzygnięcie jest platformowe (sposób uruchomienia),
      nie jest niezmiennikiem powierzchni aplikacji
- [x] `docs/operations/obraz-produkcyjny.md` — nowa sekcja 8: różnica między układem lokalnym
      (kontener `queue`) a Cloud Run, wybrany wariant (ADR-004) i jego znany limit
- [x] `docs/operations/docker.md` — jedno zdanie przy opisie usług z odsyłaczem do sekcji 8
      w `obraz-produkcyjny.md`
- [x] `README.md` — bez zmian
- [x] `CLAUDE.md` — bez zmian
- [x] `CHANGELOG.md` — wpis w sekcji „Poprawione"

## Ograniczenia techniczne

- Laravel 12 + Filament 3.3 (mechanizm importu Filamenta jest z definicji kolejkowany — nie da się
  go „wyłączyć", można jedynie zmienić sterownik kolejki albo dostarczyć konsumenta).
- Cloud Run: brak procesów w tle, instancja usypiana między żądaniami, staging schodzi do zera
  instancji; limit czasu żądania jest twardą granicą wariantu 1.
- Baza to współdzielony Cloud SQL (MySQL 8) — sterownik `database` jest dostępny w obu wariantach.
- Testy uruchamiane **wyłącznie** przez `docker compose exec app php artisan test`.

## Rozstrzygnięcia

<!-- Punktowe decyzje ustalone przy /review-task: wygląd, copy, próg, nazwa, umiejscowienie.
     Wiążą implementację tak samo jak decyzje z ADR-ów, ale nie mają zasięgu poza zadaniem.
     Format: decyzja + jednozdaniowe uzasadnienie. -->
- **Realny rozmiar importu krajów to mały, skończony słownik (~200 rekordów)** — świat ma
  ~195–250 uznawanych państw/terytoriów, to naturalny sufit tego importu, nie rosnący z czasem.
  Limit czasu żądania w wariancie `sync` jest tu **teoretyczny, nie praktyczny** — potwierdzone
  przez autora. Dokumentacja (patrz „Zmiany dokumentacji") ma to stwierdzić wprost, zamiast
  zostawiać czytelnika z gołym „limit istnieje".
- **Docelowy plik dokumentacji operacyjnej to `docs/operations/obraz-produkcyjny.md`, nie
  `docker.md`.** Po zadaniu 004 `docker.md` opisuje wyłącznie środowisko lokalne; zachowanie na
  Cloud Run i w obrazie produkcyjnym mieszka w `obraz-produkcyjny.md`. Pierwotna treść zadania
  wskazywała `docker.md`, bo powstała przed tą reorganizacją.
- **Wariant 2 nie dotyka `Dockerfile`/entrypointu w tym repozytorium**, niezależnie od wyboru.
  Cloud Run Job nadpisuje komendę uruchamianą w tym samym obrazie; `docker/prod-entrypoint.sh`
  kończy się `exec "$@"`, więc przezroczyście przepuszcza dowolne polecenie. Definicja samego
  zadania i wyzwalacza Cloud Scheduler to potok wdrożeniowy — poza zakresem tego repozytorium
  (ten sam wzorzec wyłączenia co w zadaniu 004). Tier zostaje **T2 niezależnie od wybranego
  wariantu** — pierwotna warunkowa premisa o T3 była błędna nawet przed usunięciem
  `Dockerfile.prod` w zadaniu 004.

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- [**ADR-004 — Wykonywanie zadań kolejkowanych na Cloud Run: `sync` zamiast osobnego
  workera**](../../adr/ADR-004-wykonywanie-kolejki-na-cloud-run.md) — **status: proposed, sekcja
  „Decyzja" do wypełnienia przez autora.** Rekomendacja: Wariant A (`sync`). Wszystkie trzy warunki
  kryterium ADR spełnione niezależnie zweryfikowane (nie tylko przyjęte z treści zadania): zasięg
  wykracza poza import krajów (wiąże każdą przyszłą funkcję z `ShouldQueue`), koszt odwrócenia jest
  wysoki (zmiany w konfiguracji wdrożenia i infrastrukturze `gcp-foundation`), a uzasadnienie —
  konflikt z ADR-0008 fundamentu („no always-on workers") — jest czymś, co za rok trzeba będzie
  wytłumaczyć, nie da się odtworzyć z samego kodu.

## Otwarte pytania — zamknięte przy `/review-task` (2026-08-15)

- ~~**Wariant 1 (`sync`) czy wariant 2 (Cloud Run Job z workerem)?**~~ →
  **[ADR-004](../../adr/ADR-004-wykonywanie-kolejki-na-cloud-run.md)**, rekomendacja: wariant A.
- ~~**Jaki jest realny górny rozmiar importowanego pliku krajów?**~~ → **Mały, skończony słownik
  (~200 rekordów)**, potwierdzone przez autora — patrz „Rozstrzygnięcia".
- ~~**Czy `docker-compose.prod.yml` opisuje jeszcze realne środowisko?**~~ → **Pytanie nieaktualne:
  plik został usunięty w zadaniu 004** (razem z `Dockerfile.prod`); obraz produkcyjny to dziś cel
  `prod` jednego `Dockerfile` (FrankenPHP, ADR-002). Nie ma już czego porządkować osobnym zadaniem.
