# ADR-004 — Wykonywanie zadań kolejkowanych na Cloud Run: `sync` zamiast osobnego workera

- **Status:** accepted
- **Data:** 2026-08-15
- **Zadanie:** [003 — Wykonywanie zadań kolejkowanych po wdrożeniu na Cloud Run](../tasks/implemented/003-wykonywanie-kolejki-na-cloud-run.md)

## Kontekst

Jedyny dziś realny konsument kolejki to import CSV w `CountryResource`
(`ImportAction::make()->importer(CountryImporter::class)`) — mechanizm importu Filamenta z
definicji dzieli plik na porcje i wysyła je jako zadania (`Filament\Actions\Imports\Jobs\ImportCsv`),
nie wykonuje importu w żądaniu. Domyślny sterownik to `QUEUE_CONNECTION=database`; lokalnie
konsumuje go kontener `queue` z `docker-compose.yml`. **Na Cloud Run nie ma procesu, który mógłby
konsumować kolejkę** — instancja obsługuje żądania HTTP i jest usypiana między nimi (staging
schodzi do zera). Skutek po wdrożeniu bez naprawy: administrator wgrywa plik, dostaje potwierdzenie
przyjęcia, a zadanie nigdy się nie wykonuje — cicha awaria bez błędu.

Decyzja wiąże **każdą przyszłą funkcję korzystającą z kolejki**, nie tylko import krajów — kolejny
deweloper dodający `ShouldQueue` do jakiejkolwiek klasy odziedziczy ten wybór bez świadomości, że
w ogóle go dokonano. Odwrócenie po fakcie (przejście z `sync` na osobnego workera albo odwrotnie)
wymaga zmian w konfiguracji wdrożenia i w infrastrukturze (`gcp-foundation`), nie tylko w tym
repozytorium.

**Ograniczenie kosztowe platformy jest tu rozstrzygające**, nie tylko techniczne: `gcp-foundation`
ADR-0008 („Cost-minimal runtime") ustala wprost: **„Cloud Run scale-to-zero for all app services —
no always-on workers"** oraz **„No Redis / Memorystore (constant cost). Async uses
`queue=database`"**. Ciężkie obciążenia kolejkowe są tam świadomie odłożone jako „an explicit
per-project decision, out of the starting scope". Wzorzec cyklicznego wyzwalania istnieje już
w warstwie infrastruktury — `gcp-foundation` ADR-0012 ustala **Cloud Scheduler → Cloud Run Job**
(nie endpoint HTTP) jako wzorzec dla zadań cyklicznych, konsumowany dziś przez WorkSnapa (komendy
porządkujące) i planowany dla PunktówSzczepień (`IssueInvoices`/`FetchKsefNumbers`).

**Realny rozmiar danych, który tę decyzję waży:** import krajów ma naturalny sufit ~195–250
rekordów (liczba uznawanych państw/terytoriów na świecie) — potwierdzone przez autora zadania.
To nie jest zbiór rosnący z czasem.

## Alternatywy

### Opcja A — `QUEUE_CONNECTION=sync` w środowiskach Cloud Run

Import wykonuje się w żądaniu, bez kolejkowania. Zero dodatkowej infrastruktury.

**Zalety:**
- **Zero kosztu i zero ruchomych części** — zgodne z ADR-0008 „no always-on workers" wprost,
  bez wyjątku i bez dodatkowej usługi do utrzymania.
- **Zero nowej powierzchni do zabezpieczenia** — brak nowego zadania Cloud Run, brak nowego wpisu
  w Cloud Schedulerze, brak nowych uprawnień IAM.
- Prostota: jedna zmienna środowiskowa różniąca konfigurację lokalną od Cloud Run, bez zmian w
  kodzie aplikacji ani w obrazie.
- **Limit czasu żądania jest tu teoretyczny, nie praktyczny** — sufit importu (~200 rekordów)
  jest o rzędy wielkości mniejszy niż to, co mieściłoby się w limicie czasu żądania Cloud Run.

**Wady:**
- Import blokuje żądanie HTTP na czas przetwarzania pliku — przy znacznie większym imporcie
  (dziś niehipotetycznym, ale możliwym w przyszłości) użytkownik czekałby na odpowiedź dłużej niż
  wygodnie, a przy dostatecznie dużym pliku żądanie przekroczyłoby limit czasu.
- `sync` różni się od zachowania lokalnego (`database` + kontener `queue`) — środowiska nie są
  identyczne, co jest odstępstwem wymagającym udokumentowania (patrz zadanie 003, wymaganie
  o granicy „tak jest lokalnie, tak jest na Cloud Run").
- Nie skaluje się „za darmo" na kolejnego konsumenta kolejki — gdyby pojawiła się funkcja z
  realnie dużym albo długotrwałym zadaniem, tę decyzję trzeba będzie podjąć ponownie dla niej.

### Opcja B — Osobny Cloud Run Job z `queue:work --stop-when-empty`, wyzwalany cyklicznie przez Cloud Scheduler

Ten sam obraz co usługa `app`, inna komenda uruchamiana przez Cloud Run Jobs API; Cloud Scheduler
wyzwala go w ustalonym takcie (wzorzec z ADR-0012).

**Zalety:**
- **Zachowanie identyczne z lokalnym** — kolejka jest realnie konsumowana asynchronicznie w obu
  środowiskach, bez rozjazdu do udokumentowania.
- **Skaluje się na przyszłych konsumentów kolejki** bez ponownego podejmowania tej decyzji —
  każda kolejna funkcja z `ShouldQueue` po prostu korzysta z istniejącego workera.
- Wzorzec (Cloud Scheduler → Cloud Run Job) jest już ustalony i sprawdzony w działaniu w
  `gcp-foundation`/WorkSnapie — nie jest to nowy, niesprawdzony mechanizm.
- Brak limitu czasu żądania HTTP — zadania mogą trwać dowolnie długo w ramach limitu samego Joba.

**Wady:**
- **Sprzeczne wprost z ADR-0008** („no always-on workers"), chyba że traktowane jako wyjątek
  wymagający własnego uzasadnienia kosztowego — Cloud Run Job uruchamiany cyklicznie generuje
  koszt proporcjonalny do częstotliwości cyklu, niezależnie od tego, czy w kolejce cokolwiek leży.
- **Więcej ruchomych części**: definicja Joba, wpis w Cloud Schedulerze, uprawnienia IAM do
  wywołania Jobs API — każdy z tych elementów żyje w potoku wdrożeniowym/`gcp-foundation`, poza
  tym repozytorium, więc zadanie 003 samo w sobie nie mogłoby tego domknąć.
- **Nieproporcjonalne do dzisiejszej skali problemu**: budowa całego mechanizmu cyklicznego
  wyzwalania dla jednego, okazjonalnego, administratorskiego importu słownika (~200 rekordów)
  wykonywanego rzadko.
- Cykl Cloud Schedulera wprowadza opóźnienie między wgraniem pliku a wykonaniem importu (czas do
  najbliższego uruchomienia Joba) — dla dzisiejszego przypadku użycia to regres względem `sync`,
  który kończy import od razu.

## Rekomendacja

**Opcja A — `QUEUE_CONNECTION=sync` w środowiskach Cloud Run.**

Trzy argumenty przesądzają:

1. **Zgodność z fundamentem platformy jest tu twarda, nie stylistyczna.** ADR-0008 ustala „no
   always-on workers" i „queue=database" jako świadomy wybór kosztowy tego projektu (kredyt
   próbny ~1126 zł, wygasający 2026-10-01). Opcja B wymagałaby wyjątku od tej zasady — a jedyne
   uzasadnienie tego wyjątku byłoby „tak jest wygodniej", nie „tak jest taniej" ani „tak jest
   konieczne".
2. **Koszt problemu, który rozwiązujemy, jest dziś zerowy.** Zweryfikowany rozmiar importu
   (~200 rekordów, sufit naturalny, nie rosnący) sprawia, że limit czasu żądania jest ryzykiem
   teoretycznym. Budowa Joba i Schedulera pod ryzyko, które nie występuje, to koszt bez korzyści.
3. **Odwrócenie w przyszłości jest tanie, jeśli pojawi się realny powód.** Gdyby powstała funkcja
   z rzeczywiście dużym albo długotrwałym zadaniem kolejkowym, wzorzec z ADR-0012 (Cloud
   Scheduler → Cloud Run Job) już istnieje i jest sprawdzony w WorkSnapie — nie trzeba go
   wymyślać od nowa, tylko zastosować w momencie, gdy koszt uzasadnienia się pojawi.

**Warunek wykonania tej rekomendacji:** dokumentacja (`docs/operations/obraz-produkcyjny.md`) ma
wprost stwierdzić, że limit jest dziś teoretyczny **i dlaczego** (rozmiar importu), a nie tylko że
istnieje — inaczej przyszły czytelnik nie odróżni świadomie zaakceptowanego ryzyka od przeoczenia.

**Kiedy wracać do tej decyzji:** jeśli powstanie funkcja z zadaniem kolejkowym, które (a) nie ma
naturalnego, małego sufitu rozmiaru albo (b) musi trwać dłużej niż rozsądny czas odpowiedzi HTTP —
wtedy Opcja B przestaje być „nieproporcjonalna" i staje się właściwym wyborem dla **tej** funkcji,
niekoniecznie dla importu krajów.

## Decyzja
Decyzja: A

Uzasadnienie: na tą chwilę nie ma żadnych długich zadań, które by wymagały osobnego runnera.
