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
imporcie na wdrożonym środowisku. Kontenery `queue` i `scheduler` z `docker-compose.prod.yml`
są elementem układu lokalnego — Cloud Run ich nie odtwarza.

## Wymagania

- Rozstrzygnąć i zaimplementować sposób wykonywania zadań kolejkowanych w środowiskach
  uruchamianych na Cloud Run (`staging`, `prod`). Dwa warianty do rozważenia — wybór należy do
  `/review-task`, patrz otwarte pytania:
  1. **`QUEUE_CONNECTION=sync` w środowiskach Cloud Run** — import wykonuje się w żądaniu.
     Bez dodatkowej infrastruktury i bez kosztu; ryzykiem jest limit czasu żądania na dużym pliku.
  2. **Osobny Cloud Run Job z `queue:work --stop-when-empty`**, wyzwalany cyklicznie przez Cloud
     Scheduler (mechanizm istnieje w warstwie infrastruktury — ADR-0012 w `gcp-foundation`).
     Więcej ruchomych części i osobny wpis w konfiguracji wdrożenia.
- Udokumentować wybrany wariant w `docs/operations/docker.md` wraz z granicą „tak jest lokalnie,
  tak jest na Cloud Run" — dziś ta różnica nie jest nigdzie zapisana i jest źródłem tej pomyłki.
- Zapewnić, że wybrane rozwiązanie faktycznie domyka import: rekord w `imports` osiąga stan
  końcowy, a błędne wiersze lądują w `failed_import_rows`.
- Jeśli wybrany zostanie wariant 1: udokumentować **znany limit** (rozmiar pliku, po którym import
  przestaje mieścić się w limicie czasu żądania) zamiast udawać, że go nie ma.

## Kryteria akceptacji

- [ ] Wariant jest wybrany, zaimplementowany i opisany w `docs/operations/docker.md`.
- [ ] Import listy krajów wykonuje się do końca w konfiguracji przewidzianej dla Cloud Run —
      pokryte testem przepływu importu.
- [ ] Konfiguracja lokalna (Docker Compose z kontenerem `queue`) działa bez zmian.
- [ ] Zakres testów zadeklarowany niżej (T2) jest zielony.
- [ ] Pełny pakiet testów jest **odroczony** na koniec sesji (`/review-implementation`, Krok 1) —
      **chyba że** `/review-task` wybierze wariant 2, który dotyka `Dockerfile.prod`/entrypointu;
      wtedy tier podnosi się do T3 obowiązkowo (lista wyzwalaczy w `CLAUDE.md`).

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

- [ ] `docs/conventions/` — bez nowego pliku; rozstrzygnięcie jest platformowe (sposób uruchomienia),
      nie jest niezmiennikiem powierzchni aplikacji
- [ ] `docs/operations/docker.md` — sekcja o różnicy między układem lokalnym (kontener `queue`)
      a Cloud Run oraz opis wybranego wariantu wraz z jego znanym limitem
- [ ] `README.md` — bez zmian
- [ ] `CLAUDE.md` — bez zmian
- [ ] `CHANGELOG.md` — wpis w changelogu

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
- 

## Powiązane ADR-y

<!-- Numery ADR-ów podjętych dla tego zadania (uzupełnia /review-task).
     Tylko decyzje spełniające trzyskładnikowe kryterium z CLAUDE.md —
     reszta idzie do „Rozstrzygnięcia" powyżej. -->
- 

## Otwarte pytania (dla `/review-task`)

- **Wariant 1 (`sync`) czy wariant 2 (Cloud Run Job z workerem)?** Rozstrzygnięcie wiąże każdą
  przyszłą funkcję używającą kolejki (nie tylko import krajów), a odwrócenie go po fakcie wymaga
  zmian w konfiguracji wdrożenia i w infrastrukturze — spełnia komplet kryteriów ADR z `CLAUDE.md`.
  Wstępna rekomendacja: wariant 1, dopóki jedynym konsumentem kolejki jest okazjonalny import
  słownika wykonywany przez administratora.
- Jaki jest realny górny rozmiar importowanego pliku krajów? Odpowiedź przesądza, czy limit czasu
  żądania w wariancie 1 jest problemem teoretycznym, czy praktycznym.
- Czy `docker-compose.prod.yml` (kontenery `queue`, `scheduler`) opisuje jeszcze jakieś realne
  środowisko produkcyjne, czy jest wyłącznie pozostałością po układzie lokalnym? Jeśli to drugie,
  osobnym zadaniem warto go uporządkować, żeby nie sugerował nieistniejącego wdrożenia.
