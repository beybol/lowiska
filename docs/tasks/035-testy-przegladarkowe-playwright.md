# 035 — Testy przeglądarkowe (Playwright) dla portalu

> **Etap 2, zadanie 4** ([roadmapa](../project/ROADMAPA.md)). Założone 30.09.2026.
> Zadania 031–034 weryfikują wygląd i działanie na telefonach **ręcznie**; to zadanie zamienia tę
> weryfikację na automatyczną.

## Opis problemu

Portal jest pierwszą częścią aplikacji, której poprawność zależy od przeglądarki: układ na 390 px,
zakładki bez przeładowania, przełączniki kalendarza, przełącznik języka, przyklejone elementy.
Testy funkcjonalne Pesta sprawdzają HTML z serwera, nie to, co widzi wędkarz. Ręczna weryfikacja
z zadań 031–034 nie powtórzy się przy każdej zmianie.

## Wymagania

- **Infrastruktura:** Playwright z przeglądarką (Chromium, ❓ WebKit dla Safari) w środowisku
  kontenerowym; uruchamianie jedną komendą; baza i izolacja zgodne z pięcioma warstwami z `CLAUDE.md`
  (testy przeglądarkowe **nie mogą** wskazywać na bazę roboczą `lowiska`).
- **Narzędzie** — ❓ `pestphp/pest-plugin-browser` (Playwright pod spodem, testy w Peście) vs
  Playwright Test w Node vs Laravel Dusk — kandydat na ADR (wiąże wszystkie przyszłe testy UI,
  obraz dockera i CI).
- **Scenariusze dla ekranów 031–034**, każdy na desktopie i 390 px:
  - strona główna: lista alfabetyczna, tylko opublikowane, przejście na łowisko;
  - przełącznik języka na każdej stronie → odpowiednik w drugim języku;
  - adresy łowiska: `/l/{slug}`, zły segment województwa → 301;
  - strona łowiska: zakładki (kotwica, bez przeładowania), box z telefonem, pasek „Zadzwoń" na telefonie;
  - kalendarz: tydzień ‹ ›, łowiący, grupa, cecha, rozbicie; wersja mobilna — wybór doby;
  - brak poziomego przewijania strony na 390 px.
- **CI:** ❓ czy testy przeglądarkowe wchodzą do `deploy.yml` (i jako bramka), czy tylko lokalnie
  i w `/review-implementation`.

## Kryteria akceptacji

- [ ] Jedna komenda uruchamia testy przeglądarkowe; opisana w `docs/operations/docker.md`.
- [ ] Scenariusze z wymagań zielone na danych Klasztornego i Łopienna.
- [ ] Testy przeglądarkowe nie łamią żadnej z pięciu warstw izolacji bazy.
- [ ] Pełny pakiet testów zielony (T3).

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zmiana dotyka `composer.json` / `package.json`, `Dockerfile` lub
  `docker-compose.yml` i potencjalnie `tests/Pest.php` — wyzwalacze T3.

## Zakres wyłączeń

- Testy przeglądarkowe paneli Filamenta.
- Testy wizualne (porównywanie zrzutów) — ❓ na później.

## Zmiany dokumentacji

- [ ] `docs/operations/docker.md` — uruchamianie testów przeglądarkowych.
- [ ] `CLAUDE.md` — komendy i tiery testów, jeśli testy przeglądarkowe dostają własny tier albo
      wchodzą do T3.
- [ ] `docs/adr/` — ADR wyboru narzędzia (jeśli `/review-task` potwierdzi kryterium).
- [ ] `CHANGELOG.md` — bez wpisu.

## Ograniczenia techniczne

- Chromium jest w obrazie? ❓ sprawdzić obraz deweloperski; rozmiar obrazu produkcyjnego bez zmian
  (przeglądarka tylko w etapie deweloperskim / osobnej usłudze).
- `CLAUDE.md` — bezpieczeństwo bazy danych, pięć warstw izolacji.

## Rozstrzygnięcia

## Powiązane ADR-y

- ❓ ADR wyboru narzędzia testów przeglądarkowych — do oceny w `/review-task`.
