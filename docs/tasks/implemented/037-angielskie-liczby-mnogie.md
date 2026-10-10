# 037 — Angielskie teksty z liczbą mnogą wychodzą po polsku

> Odkryte w zadaniu 034 (01.10.2026). Dotyczy portalu (PL/EN) i paneli z przełącznikiem języka.

## Opis problemu

Aplikacja nie ma pliku `lang/en.json` — angielskim tekstem jest sam klucz, a `lang/pl.json` niesie
tłumaczenia. Ustawienia: `locale = pl`, `fallback_locale = pl`.

Zwykłe `__('klucz')` działa w EN poprawnie: klucza nie ma w `en`, więc Laravel zwraca klucz, czyli angielski
tekst. **`trans_choice()` zachowuje się inaczej**: `Translator::choice()` wybiera język przez
`localeForChoice()`, który — gdy `en` nie ma klucza — bierze język **zapasowy**, czyli `pl`. Efekt:

```
app()->setLocale('en');
trans_choice('up to :count angler|up to :count anglers', 2, ['count' => 2]);  // „do 2 łowiących"
```

Na stronach EN (portal, panele po przełączeniu języka) wszystkie teksty z liczbą mnogą wychodzą więc po
polsku: „do 2 łowiących" na liście stanowisk, „min. 5 dób" w wyciągu zasad i w cenniku, liczba dób,
liczniki w panelach. W kodzie jest dziś ok. 18 takich kluczy (z `|` w `lang/pl.json`) w 14 plikach
(`PortalCalendar`, `PortalFisheryPage`, `FisheryRulesSummary`, `WeekdayNights`, widoki karty i listy łowisk,
zasoby i strony Filamenta).

W zadaniu 034 nowy tekst warunku dopłaty obchodzi problem dwoma zwykłymi kluczami (`PriceRule::conditionText()`);
to obejście, nie rozwiązanie.

## Wymagania

- **Język zapasowy aplikacji to angielski** (R1): `config/app.php` → `'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en')`.
  Wtedy `trans_choice()` dla klucza, którego nie ma w `en`, liczy liczbę mnogą z samego angielskiego klucza
  (reguły angielskie), a PL działa jak dziś, bo klucze są w `lang/pl.json`.
  - `.env.example` ma już `APP_FALLBACK_LOCALE=en` — bez zmian.
  - ⚠️ **Lokalny `.env` autora ma `APP_FALLBACK_LOCALE=pl`** i przebija konfigurację — implementacja nie
    edytuje `.env`, tylko mówi wprost, że trzeba tę linię zmienić albo usunąć.
  - `phpunit.xml` ustawia wyłącznie `APP_LOCALE`; sprawdzić, czy test zapasowego języka nie wymaga wymuszenia
    `APP_FALLBACK_LOCALE` w `phpunit.xml` (wtedy z `force="true"`, jak reszta tego pliku).
- Teksty z liczbą mnogą w wersji angielskiej wychodzą **po angielsku**, w poprawnej formie (1 angler / 2 anglers)
  — w portalu i w panelach. Wersja polska bez zmian (trzy formy: 1 / 2–4 / 5+).
- **Zabezpieczenie przed powrotem** — test: każdy klucz z `|` użyty w kodzie (`trans_choice`/`@choice`)
  w języku `en` daje angielską formę z klucza, a w `pl` — formę z `lang/pl.json`; oraz test, że
  `config('app.fallback_locale') === 'en'`.
- **Obejście z 034 usunięte** (R2): `PriceRule::conditionText()` wraca do `trans_choice()`
  (`with :count angler|with :count anglers`); klucze `with one angler` / `with :count anglers` znikają z `lang/pl.json`.
- Regułę dla przyszłego kodu zapisać w `docs/conventions/strona-publiczna.md` — **zastępuje** dzisiejszą uwagę
  o pułapce `trans_choice` (reguła unieważniona jest przepisywana, nie dopisywana obok).

## Kryteria akceptacji

- [ ] Strony portalu w EN pokazują angielskie teksty ze wszystkich dotychczasowych kluczy z liczbą mnogą
      (stanowiska „up to N anglers", wyciąg zasad „min./max. N nights", liczba dób, warunek dopłaty).
- [ ] Ekrany paneli po przełączeniu na EN nie mają polskich fragmentów z liczbą mnogą.
- [ ] Test zgodności kluczy z liczbą mnogą i test języka zapasowego zielone.
- [ ] Teksty polskie niezmienione — poza warunkiem dopłaty, który wraca do „przy 1 łowiącym" (R2).
- [ ] **Ręczna weryfikacja** strony łowiska w EN i jednego ekranu panelu w EN (po zmianie lokalnego `.env`).
- [ ] Pełny pakiet testów zielony (T3).

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zmiana `fallback_locale` jest globalna — dotyka każdego tłumaczenia w obu panelach,
  portalu, komunikatach walidacji i mailach, więc przechodzi na T3 (zapowiedziane w wersji zadania sprzed
  `/review-task`). Ewentualna zmiana `phpunit.xml` jest dodatkowo wyzwalaczem T3 z `CLAUDE.md`.

## Zakres wyłączeń

- Tłumaczenie pozostałych tekstów na nowe języki (poza PL i EN).
- Przebudowa mechanizmu tłumaczeń na klucze semantyczne zamiast angielskich zdań.
- Plik `lang/en.json` — niepotrzebny przy R1.
- Zmiana treści polskich tekstów (poza R2).
- Teksty operatora (jednojęzyczne, D8).

## Zmiany dokumentacji

- [ ] `docs/conventions/strona-publiczna.md` §5 — przepisać uwagę o pułapce `trans_choice` na regułę:
      język zapasowy to `en`, liczba mnoga przez `trans_choice()` z angielskim kluczem i formami w `pl.json`.
- [ ] `docs/conventions/panel-admina.md` — jedno zdanie o języku zapasowym, jeśli panel ma sekcję o tłumaczeniach;
      inaczej bez zmian.
- [ ] `CHANGELOG.md` — wpis w changelogu (sekcja „Poprawione").

## Ograniczenia techniczne

- Laravel 13, tłumaczenia JSON (`lang/pl.json`), brak `lang/en.json`; Laravel ma wbudowane tłumaczenia `en`
  (np. walidacji), na które przy R1 spadną brakujące klucze plikowe w PL.
- Przełącznik języka paneli (`filament-language-switch`) i portal (`SetPortalLocale`) ustawiają język niezależnie.
- Zwykłe klucze `__()` w PL i w EN zachowują się jak dziś (brak klucza = tekst klucza).

## Rozstrzygnięcia

- **R1. Język zapasowy `en` zamiast `lang/en.json` albo własnej funkcji** — decyzja autora z 01.10.2026. Jedna
  zmiana konfiguracji naprawia wszystkie obecne i przyszłe klucze z liczbą mnogą, bez drugiego pliku do
  utrzymania; spójne z konwencją „kluczem jest angielski tekst". Nie ADR: zasięg wykracza poza zadanie, ale
  odwrócenie to jedna linijka konfiguracji, więc nie spełnia warunku kosztu odwrócenia — uzasadnienie trafia
  do pliku konwencji.
- **R2. Obejście z 034 usunięte** — decyzja autora: jeden sposób zapisu liczby mnogiej w całym kodzie.
  Warunek dopłaty wraca do `trans_choice()`, więc „przy jednym łowiącym" staje się „przy 1 łowiącym"
  (testy `FisheryPricingTabTest` do poprawy).

## Powiązane ADR-y

- Brak — R1 rozstrzygnięte w treści zadania (patrz uzasadnienie).
