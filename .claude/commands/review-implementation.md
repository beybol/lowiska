---
description: Pełny pakiet testów + przegląd zmienionego kodu wg frameworka projektu (osobny agent na Opus) + testy mutacyjne Pesta i security-review uruchamiane sekwencyjnie — z potwierdzeniem przed i po każdym etapie.
argument-hint: "[commit-sha] [--security|--no-security]"
---

# /review-implementation — przegląd zmienionego kodu

Orchestrujesz **przegląd zmian w trzech krokach**: Krok 1 (pełny pakiet testów), Krok 2
(przegląd wg frameworka) i Krok 3 (testy mutacyjne + security-review, uruchamiane **sekwencyjnie**,
każde na osobnej zgodzie). Przed KAŻDYM etapem pytasz użytkownika, czy etap wykonać, czy pominąć.
Po każdym etapie, jeśli są uwagi, pytasz co z nimi zrobić. **Żaden etap nie jest twardym gate** —
całość jest doradcza i interaktywna.

Ta komenda jest **miejscem, w którym domyka się zakres testów odroczony w zadaniach**:
`/implement-task` uruchamia testy w zadeklarowanym tierze (T1/T2/T3 wg `CLAUDE.md`), więc po
serii drobnych zadań pełny pakiet zwykle nie poszedł ani razu. Stąd Krok 1.

Argumenty (`$ARGUMENTS`):
- opcjonalny `<commit-sha>` (pierwszy argument niebędący flagą) → pliki z tego commita,
  bez pytania o zakres; **brak → pytasz o zakres** (Krok 0),
- `--security` / `--no-security` → wymuś/wyłącz etap security (nadpisuje auto-heurystykę).

## Zasady nadrzędne
- **Nie commituj i nie przenoś zadań** — cykl życia zadań i git są własnością użytkownika.
- **Raport i tworzone zadania po polsku**; kod i komentarze po angielsku.
- **Testy uruchamiaj wyłącznie przez `./test.sh`** — nigdy gołym `php artisan test` (patrz
  `CLAUDE.md`, sekcja o słabym punkcie izolacji testów).
- Każde pytanie zadawaj przez `AskUserQuestion`.
- **Testy mutacyjne i security-review są od siebie niezależne co do ZGODY, ale nie co do
  wykonania** — zgodę na każdy zbierasz osobno (Krok 3A / Krok 3B), a uruchamiasz je **jeden po
  drugim, mutacje najpierw** (Krok 3C). ⚠️ **Nigdy równolegle**: oba przebiegi dotykają tej samej
  bazy testowej, a agent security ma powłokę i potrafi uruchomić coś opartego na `RefreshDatabase`
  w środku tamtego przebiegu. Objaw jest mylący — wygląda na hazard izolacji testów i **nie
  reprodukuje się** punktowo, bo przyczyną jest wyścig, nie kolejność.
- **Security-review zawsze na osobnym agencie** (`Agent` tool, `subagent_type: "general-purpose"`),
  nigdy inline przez orchestrator — patrz Krok 3B.

---

## Krok 0 — Ustal zakres i zmienione pliki

### Gdy podano `<sha>` — nie pytaj

Argument przesądza zakres: pliki z tego jednego commita. Przejdź od razu do „Filtr i zamknięcie".

### Gdy nie podano argumentu — zapytaj o zakres

1. **Rozpoznaj stan repo** (wszystko w jednej turze):
   ```bash
   git rev-parse --abbrev-ref HEAD
   git rev-parse --abbrev-ref '@{u}' 2>/dev/null || echo 'BRAK-UPSTREAMU'
   git log --oneline '@{u}..HEAD' 2>/dev/null | cat
   git status --porcelain
   ```
2. **Ustal, które opcje są wykonalne** — oferuj **tylko** niepuste:

   | Opcja | Zakres | Warunek |
   |---|---|---|
   | **A** | Wszystkie zmiany **niezacommitowane** (working tree + untracked) | są zmiany w `git status` |
   | **B** | **Jeden wybrany commit** spośród niewypchniętych | ≥ 1 commit w `@{u}..HEAD` |
   | **C** | Wszystkie zmiany **zacommitowane, niewypchnięte** | ≥ 1 commit w `@{u}..HEAD` |
   | **D** | **Niewypchnięte + niezacommitowane** razem | oba powyższe niepuste |

3. **Zero wykonalnych opcji** → powiedz, że nie ma czego przeglądać, i zakończ.
   **Dokładnie jedna** → użyj jej bez pytania, mówiąc wprost którą i dlaczego jedyną.
4. **Zapytaj** (`AskUserQuestion`): „Jaki zakres przejrzeć?" — w opisie każdej opcji podaj
   **liczby** (ile plików, ile commitów), żeby wybór był świadomy. Rekomendacja: **D**, gdy
   są i commity, i brudne drzewo (najpełniejszy obraz przed wypchnięciem); **A** w pozostałych.
5. **Po wyborze B** — zapytaj o konkretny commit:
   - Commitów **≤ 4** → jedna pozycja na commit: `<krótki-sha> — <tytuł commita>`.
   - Commitów **> 4** → ⚠️ `AskUserQuestion` przyjmuje maksymalnie 4 opcje, więc **najpierw
     wypisz w odpowiedzi pełną, numerowaną listę wszystkich** niewypchniętych commitów
     (sha + tytuł), a w pytaniu zaproponuj **4 najnowsze**; pozostałe użytkownik wskaże,
     wklejając SHA w pole „Other".

**Brak upstreamu** (`BRAK-UPSTREAMU`): spróbuj `origin/<nazwa-brancha>`. Jeśli i tego nie ma,
opcje B/C/D odpadają — powiedz to wprost i zostaw samo A.

### Komendy per zakres

```bash
# A — niezacommitowane (working tree + untracked)
{ git diff --name-only --diff-filter=d HEAD; git ls-files --others --exclude-standard; }

# B — jeden commit
git diff-tree --no-commit-id --name-only -r --diff-filter=d <sha>

# C — zacommitowane, niewypchnięte
git diff --name-only --diff-filter=d '@{u}..HEAD'

# D — niewypchnięte + niezacommitowane
{ git diff --name-only --diff-filter=d '@{u}'; git ls-files --others --exclude-standard; }
```

⚠️ W opcji **D** jest `@{u}` **bez** `..HEAD` — to nie literówka: `git diff @{u}..HEAD`
porównuje dwa drzewa commitów i **pomija** zmiany niezacommitowane, a `git diff @{u}`
porównuje upstream z **drzewem roboczym**, więc łapie jedno i drugie. Pliki untracked
dokładasz osobno, bo `git diff` ich nie widzi.

### Filtr i zamknięcie

Każdy z powyższych zestawów przepuść przez ten sam filtr:
```bash
| sort -u | grep -vE '^(vendor/|node_modules/|storage/)' | grep -vE '(composer|package)\.lock$'
```
- Zestaw pusty → poinformuj, że nie ma czego przeglądać, i zakończ.
- Wypisz listę plików (ścieżki) i nazwij zakres: `uncommitted`, krótki `<sha>`, `unpushed`
  albo `unpushed+uncommitted`.
- **Zapamiętaj WYBRANY zakres — po każdej pętli „popraw" ustalaj listę PONOWNIE tym samym
  poleceniem, bez ponownego pytania o zakres.**
- ⚠️ **Wyjątek dla zakresów B i C:** poprawki z pętli „Popraw teraz" lądują jako zmiany
  **niezacommitowane**, czyli **poza** tymi zakresami — przy powrocie do Kroku 0 **dolicz
  do zestawu** wynik komendy A. Bez tego kolejne przejście pętli przeglądałoby stary stan
  i nigdy nie zobaczyłoby własnych poprawek.

---

## Krok 1 — Etap „Pełny pakiet testów"

Ten etap idzie **przed** przeglądem kodu celowo: przegląd na czerwonym pakiecie generuje uwagi
do kodu, który i tak trzeba ruszyć, a część uwag bywa echem realnej awarii.

1. **Zapytaj przed etapem** (`AskUserQuestion`): „Uruchomić **pełny pakiet testów**, czy pominąć?"
   → **Uruchom** / **Pomiń**. W opisie opcji podaj kontekst, który pomoże wybrać:
   - jeśli wiadomo (z rozmowy albo z sekcji `## Zakres testów` implementowanych zadań), że
     ostatnie zadania szły w tierze **T1/T2** → zaznacz, że **pełny pakiet nie był uruchamiany**,
     i postaw **Uruchom** jako rekomendację;
   - jeśli ostatnie zadanie miało tier **T3** i przeszło na zielono, albo użytkownik odpalił
     pakiet ręcznie → zaznacz to i uczciwie postaw **Pomiń** jako sensowną opcję.
   Pominąć → zapamiętaj `testy: pominięte (powód)`, przejdź do Kroku 2.
2. Uruchom:
   ```bash
   ./test.sh
   ```
3. **Zielono** → powiedz to (liczba testów/asercji) i przejdź do Kroku 2.
4. **Czerwono** → pokaż failujące testy i **zapytaj**: „Pakiet jest czerwony — co dalej?"
   - **Napraw teraz** (rekomendacja) → napraw przyczynę, uruchom pakiet ponownie, dopiero
     potem Krok 2.
   - **Kontynuuj mimo to** → przejdź do Kroku 2, ale **zapamiętaj czerwony stan** i powtórz go
     w podsumowaniu (Krok 4); przekaż też agentowi przeglądu informację, które testy padają.
   - **Przerwij** → zakończ komendę, zostawiając naprawę użytkownikowi.
5. ⚠️ **Przy powrocie w pętli** (po „Popraw teraz" w Kroku 2 lub 3) pytaj **ponownie**, a
   domyślną rekomendacją jest wtedy **Uruchom**: poprawki nanoszone w tej komendzie nie mają
   zadeklarowanego tieru i mogły ruszyć coś poza przeglądanym zestawem.

---

## Krok 2 — Etap „Przegląd wg frameworka" (agent na Opus)

1. **Zapytaj przed etapem** (`AskUserQuestion`): „Uruchomić etap **Przegląd wg frameworka**
   na tych N plikach, czy pominąć?" → **Uruchom** / **Pomiń**. Pominąć → Krok 3.
2. Wywołaj **Agent** tool z `subagent_type: "review-implementation"` (to wymusza Opus przez
   frontmatter agenta — niezależnie od modelu sesji), przekazując w prompcie: listę
   plików, zakres i — jeśli wiadomo — numer powiązanego zadania. Uruchom **synchronicznie**
   (`run_in_background: false`) — potrzebujesz wyniku, zanim zapytasz użytkownika.
3. Agent zwraca uwagi (jest read-only). Jeśli **brak uwag** → powiedz to i przejdź do Kroku 3.
4. Jeśli są uwagi → pokaż je i **zapytaj** (`AskUserQuestion`): „Co z uwagami z przeglądu?"
   - **Popraw teraz** → nanieś poprawki (Edit/Write), uruchom **testy dotkniętych plików**
     (`./test.sh --filter="…"`) i `vendor/bin/pint`, po czym **wróć na początek Kroku 0**
     (ponowna pętla: re-selekcja plików → Krok 1 → Krok 2, aż agent nie zgłosi uwag albo
     użytkownik wybierze inne wyjście). Pełny pakiet zostaw Krokowi 1 tej pętli — nie odpalaj
     go tutaj drugi raz.
   - **Nowe zadanie** → utwórz `docs/tasks/NNN-*.md` (kolejny wolny numer) wg
     `docs/tasks/_template.md`, wpisując uwagi jako opis problemu i wymagania, i dobierając
     **zakres testów** wg `CLAUDE.md`; **nie** implementuj. Przejdź do Kroku 3.
   - **Raport do pliku** → dopisz uwagi do `docs/reviews/<RRRR-MM-DD>-<zakres>.md` (sekcja
     „Etap 2 — Przegląd wg frameworka"). Przejdź do Kroku 3.
   - **Zignoruj** → nie podejmuj żadnej akcji, nic nie zapisuj; przejdź do Kroku 3.

---

## Krok 3 — Etap „Testy mutacyjne + Security-review" (sekwencyjnie)

Zbierasz zgodę na **każdy** z dwóch pod-etapów osobno (3A, 3B) — **bez ich uruchamiania**.
Dopiero gdy oba pytania mają odpowiedź, wykonujesz zatwierdzone pod-etapy (3C), po kolei.

### Krok 3A — Zgoda i zakres: testy mutacyjne (Pest)

Projekt używa **wbudowanego silnika mutacji Pesta**, nie Infection — Infection nie potrafi mapować
mutantów na testy Pesta (Pest opakowuje klasy testowe we własną przestrzeń nazw), więc nie warto go
tu wprowadzać.

1. **Zapytaj przed etapem**: „Uruchomić **testy mutacyjne** na zmienionych klasach, czy pominąć?"
   → **Uruchom** / **Pomiń**. Pominąć → zapamiętaj `mutacje: pominięte`, przejdź do Kroku 3B.
2. **Miękki gate — sprawdź warunki, bez przerywania całości:**
   - **Sterownik pokrycia:** `docker compose exec -T app php -m | grep -iE 'pcov|xdebug'`.
     ⚠️ **Obraz deweloperski nie ma dziś ani PCOV, ani Xdebuga** — bez nich silnik mutacji nie
     ruszy. Brak sterownika → poinformuj o tym wprost, zapamiętaj
     `mutacje: pominięte (brak sterownika pokrycia)` i zaproponuj **osobne zadanie** dodające PCOV
     do `Dockerfile.dev`. Nie instaluj niczego w locie.
   - Zawęź zestaw do plików źródłowych: `grep -E '^app/.*\.php$'`. Brak takich → „nic do
     mutowania", zapamiętaj `mutacje: pominięte`.
3. **Oszacuj koszt zgrubnie, bez uruchamiania:** liczba mutantów rośnie z rozmiarem i rozgałęzieniem
   klasy, a koszt jednego mutanta to czas testów **pokrywających** tę klasę. Klasa pokryta szybkimi
   testami `tests/Unit` (reguły, walidatory, usługi) → grosze za mutanta; klasa pokryta testami
   `tests/Feature`, które bootują panele Filamenta → drogo. Podaj przedział per plik i sumę,
   zaznaczając, że to rząd wielkości, nie pomiar.
4. **Rekomendacja per plik** — kryterium to **wartość mutacji, nie sam czas**:
   - **Kluczowa → TAK, nawet przy długim czasie:** klasy **liczące i walidujące**
     (`app/Rules/`, `app/Services/`, `app/Helpers/`) oraz **bramkujące** (`app/Policies/`, role
     i uprawnienia). Ocalały mutant to realna luka w regule domenowej albo w dostępie.
   - **Mechaniczna → raczej NIE:** deklaratywny szkielet — zasoby i strony Filamenta, providery,
     proste modele bez logiki. Niska wartość mutacji, a pokrycie idzie wolnymi testami Feature.
   - **Pośrednia → oceń wg dotkniętej zmiany:** jeśli diff rusza regułę, traktuj jak kluczową;
     jeśli tylko szkielet albo render — jak mechaniczną.
5. **Bramka 10 min:** suma ≤ 10 min → nie pytaj dodatkowo. Suma > 10 min → **wymagana jawna zgoda**
   (`AskUserQuestion`) z tabelą `plik · estymata · kategoria · rekomendacja`: **Uruchom całość** /
   **Tylko klasy kluczowe** (domyślna rekomendacja) / **Pomiń**. Bez wyraźnego wyboru **nie
   uruchamiaj** długiego przebiegu.
6. Zapamiętaj finalny stan i **nie uruchamiaj jeszcze niczego** — komenda odpala się dopiero
   w Kroku 3C. Przejdź do Kroku 3B.

### Krok 3B — Zgoda i zakres: Security-review

1. **Auto-heurystyka** (jeśli nie podano `--security`/`--no-security`): etap jest *sugerowany*,
   gdy którykolwiek zmieniony plik:
   - leży w `app/Policies/`, `app/Providers/Filament/`, `app/Http/Middleware/`, `routes/`, lub
   - jego treść zawiera: `Gate::`, `Hash::`, `encrypt`/`decrypt`, `password`, `token`, `whereRaw`,
     `Storage::`, upload plików, `Socialite`, role i uprawnienia Shielda, kolumny wrażliwe w migracji.
   `--security` wymusza etap, `--no-security` go wyłącza (pomija bez pytania, przejdź do 3C).
2. **Zapytaj przed etapem**: „Uruchomić **security-review** na zmienionych plikach, czy
   pominąć?" — w opisie zaznacz, czy heurystyka go sugeruje (i dlaczego), czy nie. Pominąć →
   zapamiętaj `security: pominięte`, przejdź do Kroku 3C.
3. **Model agenta: zawsze Opus — nie pytaj o niego.** To model przeznaczony do pracy z tematyką
   bezpieczeństwa, spójny z Krokiem 2. **Fable się do tego nie nadaje** — jego klasyfikatory celują
   w treści cybersecurity, więc nawet defensywny przegląd kodu może skończyć się odmową. Nie oferuj
   go jako opcji.
4. Zapamiętaj `security: uruchom` i **nie uruchamiaj jeszcze agenta** — robi to dopiero Krok 3C,
   PO zakończeniu mutacji.

### Krok 3C — Uruchomienie (SEKWENCYJNIE, nie równolegle)

⚠️ **Te dwa pod-etapy MUSZĄ iść jeden po drugim.** Oba dotykają bazy testowej, a agent security ma
powłokę i potrafi uruchomić coś opartego na `RefreshDatabase` w środku przebiegu mutacji — co wywala
go na brakującej tabeli. Objaw myli: wygląda jak hazard izolacji testów i nie reprodukuje się
punktowo, bo przyczyną jest wyścig.

1. Jeśli **oba** pod-etapy mają `pominięte` → poinformuj, że Krok 3 pominięty w całości,
   przejdź do Kroku 4.
2. Uruchom zatwierdzone pod-etapy **po kolei, każdy w osobnej turze**, w tej kolejności:
   1. **Testy mutacyjne** — `Bash` (poczekaj na wynik, ZANIM ruszysz security):
      ```bash
      docker compose exec -T app vendor/bin/pest --mutate --covered-only --class="App\\Rules\\NazwaKlasy"
      ```
      **Bez progu `--min`** — nie stawiamy bramki; raportujemy wynik.
      ⚠️ Długi przebieg przekroczy domyślny timeout `Bash` (600 s) i poleci w tło — to w porządku,
      odczytaj wynik, gdy przyjdzie powiadomienie. **Nie uruchamiaj w tym czasie niczego innego,
      co dotyka bazy testowej** (w tym `./test.sh`).
   2. **Security-review** — `Agent` tool, **zawsze jako osobny agent** (nigdy inline przez
      orchestrator): `subagent_type: "general-purpose"`, `model: "opus"`, `run_in_background: false`.
      W prompcie agenta podaj listę zmienionych plików, zakres oraz instrukcję: wywołaj skill
      **`security-review`** na tych plikach i **podążaj za referencjami** z nich (wołający,
      powiązane klasy) — to realizuje „pliki współzależne" bez osobnego grafu zależności; agent
      jest **read-only**, zwraca uwagi, nie edytuje kodu. Dopisz też, żeby **nie uruchamiał
      testów ani migracji** — to ta sama baza, z której korzysta Krok 1.
   - Jeśli zatwierdzony jest **tylko jeden** pod-etap — uruchom sam ten jeden.
3. Mając wyniki, rozpatrz uwagi **osobno dla każdego pod-etapu**, w kolejności mutacje → security:
   - **Mutacje** — uwagi = **ocalałe mutanty** i wskaźnik MSI. Brak ocalałych → powiedz to.
     Są → **zapytaj**: „Co z ocalałymi mutantami?"
     - **Popraw teraz** → dopisz lub uzupełnij testy tak, by mutanty ginęły.
     - **Nowe zadanie** → `docs/tasks/NNN-*.md` (luki w testach jako opis problemu).
     - **Raport do pliku** → `docs/reviews/<RRRR-MM-DD>-<zakres>.md`, sekcja „Etap 3A — Mutacje".
     - **Zignoruj** → nie podejmuj żadnej akcji, nic nie zapisuj.
   - **Security** — uwagi → **zapytaj**: „Co z uwagami bezpieczeństwa?"
     - **Popraw teraz** → nanieś poprawki.
     - **Nowe zadanie** → `docs/tasks/NNN-*.md`.
     - **Raport do pliku** → `docs/reviews/<RRRR-MM-DD>-<zakres>.md`, sekcja „Etap 3B — Security".
     - **Zignoruj** → nie podejmuj żadnej akcji, nic nie zapisuj.
4. Jeśli **którykolwiek** z dwóch pod-etapów dostał „Popraw teraz" → nanieś **wszystkie**
   takie poprawki razem (Edit/Write/nowe testy), uruchom **testy dotkniętych plików**
   (`./test.sh --filter="…"`) i `vendor/bin/pint`, po czym **wróć na początek Kroku 0** (pętla:
   re-selekcja plików + Krok 1 → Krok 2 → Krok 3 od nowa, aż żaden etap nie zgłosi uwag albo
   użytkownik wybierze inne wyjście). W przeciwnym razie przejdź do Kroku 4.

---

## Krok 4 — Podsumowanie
Zwięźle wypisz: które etapy wykonano/pominięto, ile uwag w każdym i jak je rozdysponowano
(poprawione / nowe zadanie `NNN` / zapisane do `docs/reviews/<plik>`). Jeśli powstał plik
raportu — podaj do niego ścieżkę.

⚠️ **Stan pełnego pakietu podaj zawsze i jednoznacznie** — zielony (z liczbą testów), czerwony
(z listą failujących) albo pominięty (z powodem). To jedyne miejsce w całym workflow, w którym
odroczone tiery T1/T2 z poszczególnych zadań są domykane; „nie wiadomo" jest tu najgorszą
możliwą odpowiedzią.
