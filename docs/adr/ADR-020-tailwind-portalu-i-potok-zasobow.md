# ADR-020 — Wersja Tailwinda dla portalu wędkarza i jeden potok zasobów front-endu

- **Status:** accepted
- **Data:** 2026-09-24
- **Zadanie:** [027 — Specyfikacja portalu wędkarza: makiety v2 uzgodnione z konfiguracją łowiska](../tasks/027-specyfikacja-portalu-wedkarza-makiety-v2.md)

## Kontekst

Etap 1 roadmapy buduje szkielet publicznego portalu wędkarza. Makiety v1 (`fisherya-design.html`,
decyzja 6) zakładają, że portal ma **własny Tailwind** — własny wygląd, niezależny od paneli
Filamenta. Zanim powstanie pierwszy widok portalu, trzeba ustalić, na jakiej wersji Tailwinda i w jakim
potoku zasobów ten wygląd będzie kompilowany.

**Stan dziś jest rozjechany:**
- `package.json` ma jednocześnie `tailwindcss` **^3** i `@tailwindcss/vite` **^4**.
- Strona publiczna Breeze (logowanie, rejestracja, profil) kompiluje się **Tailwindem 3** przez PostCSS:
  `tailwind.config.js`, `postcss.config.js`, `@tailwind base/components/utilities` w
  `resources/css/app.css`, jedno wejście Vite (`app.css`, `app.js`).
- **Motyw Filamenta 5 wymaga Tailwinda 4** (`@import 'tailwindcss' source(none)`). ADR-016 przyjął
  rejestrację własnego motywu w obu panelach, ale zadanie 019 zatrzymało się właśnie na tym
  rozjeździe („Stan po implementacji”: migracja Tailwinda do osobnego zadania).

Decyzja wiąże każdą przyszłą stronę portalu, istniejącą stronę publiczną i motyw paneli. Po zbudowaniu
kilkunastu widoków portalu zmiana wersji oznacza przepisanie klas i konfiguracji we wszystkich naraz.

Dlaczego ADR, a nie rozstrzygnięcie w zadaniu:
- **Zasięg:** portal (etapy 1–4), strona publiczna Breeze i motyw paneli z ADR-016.
- **Koszt odwrócenia:** migracja wersji po zbudowaniu portalu to przepisanie wielu szablonów, a nie
  jedna linijka.
- **Uzasadnienie warte zapamiętania:** „dlaczego jeden potok z kilkoma wejściami” i „dlaczego nie od
  razu osobny portal” nie wynikają z kodu.

## Alternatywy

### Opcja A — cały projekt na Tailwind 4, jeden potok Vite z osobnymi wejściami
Jedno zadanie migracyjne przed etapem 1: Tailwind 4 przez `@tailwindcss/vite`, usunięcie Tailwinda 3,
`tailwind.config.js` i `postcss.config.js`, przepisanie `app.css` Breeze. Osobne wejścia Vite: arkusz
portalu (własne tokeny z v1: kolory, typografia), arkusz strony publicznej Breeze oraz motyw Filamenta
(ADR-016). Każde wejście ma własne `@source`, więc klasy portalu nie trafiają do panelu i odwrotnie.

**Zalety:**
- Jedna wersja w całym projekcie; znika konflikt w `package.json`.
- Odblokowuje motyw paneli z ADR-016 i dokończenie widoku z 019 w tym samym ruchu.
- „Własny Tailwind” portalu to osobne wejście z własnymi tokenami, a nie osobny potok do utrzymania.
- Tokeny z v1 (CSS custom properties) mapują się wprost na `@theme` Tailwinda 4.

**Wady:**
- Migracja istniejących widoków strony publicznej (ok. 36 szablonów Blade poza Filamentem, w większości drobne komponenty Breeze) przed pierwszym widokiem portalu.
- Tailwind 4 zmienia część nazw klas i domyślnych wartości — potrzebny przegląd wizualny stron Breeze.

### Opcja B — portal na Tailwind 4 jako osobne wejście, Breeze zostaje na Tailwind 3
Portal startuje od razu na Tailwindzie 4 (`@tailwindcss/vite`), a strona publiczna Breeze zostaje na
Tailwindzie 3 przez PostCSS do czasu osobnej migracji.

**Zalety:**
- Portal rusza bez ruszania istniejących stron.

**Wady:**
- Dwie wersje Tailwinda w jednym `package.json` i dwa mechanizmy kompilacji (PostCSS i wtyczka Vite)
  działające obok siebie — dokładnie ten rozjazd, który zatrzymał 019, utrwalony na dłużej.
- Strona publiczna i portal to dla wędkarza jedna witryna, a wyglądałyby według dwóch zestawów zasad.
- Motyw paneli dalej zablokowany albo wymaga trzeciego wariantu konfiguracji.

### Opcja C — portal na Tailwind 3, razem ze stroną publiczną
Portal dokłada się do istniejącego potoku Tailwinda 3.

**Zalety:**
- Zero migracji teraz.

**Wady:**
- Motyw Filamenta 5 (Tailwind 4) zostaje nierozwiązany, a panel i portal rozjeżdżają się wersjami.
- Cały portal powstaje na wersji, którą i tak trzeba będzie porzucić — migracja przesunięta na moment,
  w którym jest najdroższa.

## Rekomendacja

**Opcja A.** Migracja teraz dotyczy ok. 36 szablonów strony publicznej, w większości drobnych komponentów Breeze; po etapie 1 dotyczyłaby także
całego portalu. Ten sam ruch odblokowuje motyw paneli z ADR-016, więc jedno zadanie migracyjne zamyka
dwa zaległe tematy. Osobne wejścia Vite dają portalowi „własny Tailwind” z v1 bez drugiego potoku.

Konsekwencje dla roadmapy: **zadanie migracji Tailwinda na 4 staje się pierwszym zadaniem
implementacyjnym etapu 1**, przed pierwszym widokiem portalu.

## Decyzja
Decyzja: A
