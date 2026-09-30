# 029 — Migracja na Tailwind 4 i motyw paneli

> **Etap 1, zadanie 2** ([roadmapa](../project/ROADMAPA.md)). Założone 30.09.2026 przy planowaniu
> implementacji makiet [portal-v3](../project/mockups/portal-v3/README.md) (§8, §10).
> **Pierwsze zadanie ścieżki portalu** — przed pierwszym widokiem portalu.

## Opis problemu

Projekt kompiluje dziś **Tailwinda 3** (`tailwindcss` ^3.1, `tailwind.config.js`, `postcss.config.js`),
a w `package.json` leży już `@tailwindcss/vite` ^4 — dwa potoki naraz. Motyw paneli Filamenta 5
(ADR-016, decyzja A) wymaga Tailwinda 4, a portal wędkarza ma mieć własne wejście stylów w tym samym
potoku Vite (ADR-020, decyzja A). Bez migracji każdy widok portalu powstawałby na wersji, którą
i tak trzeba porzucić — koszt przepisania rośnie z każdym szablonem.

To jedyne zadanie ścieżki, które dotyka paneli administratora i właściciela, dlatego idzie
osobno: regresja w panelach ma mieć jedną, oczywistą przyczynę.

## Wymagania

- **Cały projekt na Tailwind 4**, jeden potok Vite (`@tailwindcss/vite`) z osobnymi wejściami:
  - motyw paneli Filamenta (ADR-016) — **jeden wspólny plik** `resources/css/filament/theme.css`,
    podpięty `viteTheme()` w `AdminPanelProvider` i `OwnerPanelProvider`; `@source` obejmuje
    `app/Filament/**` i `resources/views/filament/**`;
  - istniejące widoki Breeze (`resources/css/app.css`) — z zachowaniem fontu Figtree
    (dziś w `tailwind.config.js`, po migracji w `@theme` tego wejścia) i `@plugin '@tailwindcss/forms'`;
  - **wejście portalu** `resources/css/portal.css` — na razie z samymi tokenami i fontami (niżej).
- Usunięcie Tailwinda 3: `tailwindcss` ^3, `autoprefixer`, `postcss` (o ile nic poza TW3 z nich nie
  korzysta), `tailwind.config.js`, `postcss.config.js`; dostosowanie `@tailwindcss/forms` do TW4.
- **Tokeny wyglądu v1 w `@theme` wejścia portalu, w przestrzeniach nazw Tailwinda 4** (Rozstrzygnięcie 2),
  wyłącznie paleta **Głębia** z `docs/project/design/fisherya-design.html`:
  - kolory `--color-b50…b950`, `--color-a100…a800`, `--color-ink`, `--color-ink2`, `--color-muted`,
    `--color-faint`, `--color-line`, `--color-line2`, `--color-surface` oraz stany `--color-ok`,
    `--color-ok-bg`, `--color-warn`, `--color-warn-bg`, `--color-err`, `--color-err-bg`;
  - promienie `--radius-sm` (8px), `--radius-md` (12px), `--radius-lg` (18px), `--radius-xl` (26px);
  - cienie `--shadow-1…3` (wartości `--sh-1…3` z makiety);
  - fonty `--font-sans` (Inter + stos systemowy z makiety), `--font-display` (Fraunces + stos szeryfowy).
  - Domyślna paleta Tailwinda **zostaje** (bez `--color-*: initial`).
- **Fonty Fraunces i Inter hostowane u nas** (Rozstrzygnięcie 3): pakiety
  `@fontsource-variable/inter` i `@fontsource-variable/fraunces` importowane w `portal.css`;
  żadnych odwołań do `fonts.googleapis.com`.
- Wejście portalu nie ładuje styli Filamenta ani Breeze i odwrotnie.
- **Siatka kalendarza 019** (`resources/views/filament/resources/fishery-resource/pages/manage-calendar.blade.php`)
  przepisana ze `style="…"` na klasy Tailwinda z motywu — dane i treść bez zmian (Rozstrzygnięcie 1).
- `Dockerfile`, etap `assets`: usunięcie `tailwind.config.js`/`postcss.config.js` z `COPY`,
  poprawka komentarza o skanowaniu `vendor` (dziś dotyczy paginacji Laravela, po migracji także
  `@import` motywu z `vendor/filament`); `vendor` w tym etapie zostaje.
- Panele admina i właściciela oraz widoki Breeze wyglądają **tak samo jak przed migracją**
  (albo zgodnie z ADR-016, jeśli motyw zmienia wygląd celowo).
- Obraz produkcyjny (`docker build --target prod`) buduje zasoby nowym potokiem.

## Kryteria akceptacji

- [ ] W `package.json` nie ma Tailwinda 3; `npm run build` buduje wszystkie wejścia bez ostrzeżeń.
- [ ] Wejście portalu zawiera tokeny v1 w `@theme` (przestrzenie `--color-*`, `--radius-*`,
      `--shadow-*`, `--font-*`) i nie zawiera styli paneli; zbudowany arkusz portalu nie odwołuje się
      do `fonts.googleapis.com`, a pliki fontów lądują w `public/build`.
- [ ] Oba panele ładują wspólny `resources/css/filament/theme.css` przez `viteTheme()`.
- [ ] Siatka kalendarza 019 nie zawiera `style="…"` (poza wartościami wyliczanymi w locie, jeśli
      jakieś są — wtedy uzasadnione w podsumowaniu) i wygląda jak przed zmianą w obu panelach.
- [ ] Ręczny przegląd obu paneli (lista, formularz, kalendarz podglądowy 019, strony ustawień
      łowiska) i widoków Breeze (logowanie, rejestracja) — bez regresji wyglądu; lista obejrzanych
      ekranów w podsumowaniu zadania.
- [ ] `docker build --target prod -t lowiska:prod .` przechodzi.
- [ ] Pełny pakiet testów zielony (T3).

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zmiana dotyka providerów paneli (`AdminPanelProvider`, `OwnerPanelProvider` —
  podpięcie motywu) i `Dockerfile` (etap `assets` kopiuje dziś `tailwind.config.js`
  i `postcss.config.js`, więc musi się zmienić) — wyzwalacze T3 z `CLAUDE.md`; tier przesądzony.

## Zakres wyłączeń

- Jakikolwiek widok portalu (zadanie 031).
- Zmiany wyglądu paneli poza tym, co wynika z ADR-016 (siatka 019 zmienia zapis, nie wygląd).
- Pozostałe widoki ze `style="…"` (np. `preview-document-template.blade.php`) — bez zmian.
- Palety alternatywne z `fisherya-design.html` (poza Głębią) i przełącznik motywów.
- Testy przeglądarkowe (zadanie 035).

## Zmiany dokumentacji

- [ ] `docs/operations/docker.md` / `obraz-produkcyjny.md` — potok zasobów, jeśli zmienia się budowanie.
- [ ] `docs/conventions/panel-wlasciciela.md` — **obowiązkowo**: przepisać §1 (zdanie „żaden panel
      nie rejestruje własnego motywu") na granicę z ADR-016 A (klasy Tailwinda wyłącznie w widoku,
      którego nie da się złożyć ze standardowych komponentów, po uzasadnieniu w zadaniu; wspólny plik
      motywu) oraz usunąć z §8 („Ekran podglądowy obok stron ustawień") uwagę o „stanie TYMCZASOWYM" siatki. Reguła unieważniona jest
      przepisywana, nie dopisywana obok.
- [ ] `docs/conventions/panel-admina.md` — odsyłacz do tej samej granicy (motyw wspólny dla obu paneli).
- [ ] Tokeny portalu i hostowanie fontów — niezmiennik dla przyszłych widoków portalu; plik
      `docs/conventions/strona-publiczna.md` zakłada zadanie 031, więc tu wystarczy komentarz
      w nagłówku `portal.css` + wpis w podsumowaniu zadania dla 031.
- [ ] `docs/adr/ADR-020`, `ADR-016` — sekcja „Aktualizacja", jeśli implementacja odbiega od rekomendacji.
- [ ] `CHANGELOG.md` — bez wpisu, chyba że zmienia się wygląd paneli.

## Ograniczenia techniczne

- Filament 5, Laravel 13, Vite 6, Node w kontenerze `vite` (port 8173).
- `vite.config.js` ma dopracowaną konfigurację odpytywania plików na Windows — zachować.
- ADR-020 (decyzja A), ADR-016 (decyzja A) wiążą.

## Rozstrzygnięcia

1. **Przepisanie siatki kalendarza 019 na klasy wchodzi do 029.** Zamyka dług z 019 w tym samym
   ruchu, który go odblokowuje. Żeby regresja nadal miała jedną przyczynę, implementacja idzie
   dwoma krokami z przeglądem pomiędzy: (a) migracja + pusty motyw → przegląd paneli; (b) dopiero
   potem przepisanie siatki → przegląd samej siatki.
2. **Tokeny w przestrzeniach nazw Tailwinda 4** (`--color-b500`, `--radius-md`, `--shadow-2`,
   `--font-display`…), tylko paleta Głębia, domyślna paleta Tailwinda zostaje — tylko tak tokeny
   dają klasy (`bg-b500`, `font-display`); surowe nazwy z makiety nie generują narzędzi.
3. **Fonty Fraunces i Inter hostowane u nas** przez `@fontsource-variable/*` — strona publiczna nie
   wysyła adresu IP wędkarza do Google (RODO) i nie zależy od zewnętrznego CDN.
4. **Jeden wspólny plik motywu dla obu paneli** (`resources/css/filament/theme.css`) — siatka 019
   żyje w obu panelach, a dwa identyczne pliki rozjechałyby się.

## Powiązane ADR-y

- [ADR-020](../adr/ADR-020-tailwind-portalu-i-potok-zasobow.md) — Decyzja: A.
- [ADR-016](../adr/ADR-016-wlasny-motyw-panelu.md) — Decyzja: A.
