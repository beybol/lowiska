# Flare — śledzenie błędów i wydajności

Aplikacja raportuje wyjątki, ślady wykonania (trace) i — opcjonalnie — wpisy logu do
[Flare](https://flareapp.io). Integrację wnosi pakiet `spatie/laravel-flare` (3.x), podpięty
w [`bootstrap/app.php`](../../bootstrap/app.php) przez `Flare::handles($exceptions)`.

## Projekty we Flare

| Środowisko | Projekt | ID | Stage |
|---|---|---|---|
| lokalne | Łowiska | `93111` | development |

Aplikacja nie działa jeszcze produkcyjnie — gdy powstaną kolejne środowiska, każde dostaje **własny
projekt we Flare** (osobny klucz API) i **dopisujemy je do [`flare-skill.conf`](../../flare-skill.conf)**
w katalogu głównym repozytorium.

## Konfiguracja aplikacji

Klucz API i przełączniki żyją w `.env` (wzorzec w [`.env.example`](../../.env.example)):

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `FLARE_KEY` | *(puste)* | Klucz API projektu. **Pusty = raportowanie wyłączone w całości.** |
| `FLARE_REPORT` | `true` | Wysyłanie wyjątków. |
| `FLARE_TRACE` | `true` | Wysyłanie śladów wykonania (wydajność). |
| `FLARE_SAMPLER_RATE` | `1.0` lokalnie, `0.1` w kodzie | Udział żądań objętych śladem. Lokalnie 100%, bo ruchu jest tyle, ile sami wygenerujemy — na środowisku z realnym ruchem zejdź do wartości domyślnej. |
| `FLARE_LOG` | `false` | Wysyłanie wpisów logu jako osobnych zdarzeń. |

Klucz projektu bierze się z panelu Flare (projekt → *Settings* → *API tokens*) albo z
`flare list-projects --filter-name="Łowiska"`.

Pozostałe ustawienia — cenzura danych, limity śladu, grupowanie wyjątków — są w
[`config/flare.php`](../../config/flare.php). Plik trzyma się układu z pakietu, żeby kolejne
aktualizacje dawały czytelny diff; **świadome odstępstwa** od wartości domyślnych pakietu:

- `censor.client_ips`, `censor.cookies`, `censor.session` ustawione na `true` (pakiet daje `false`) —
  aplikacja obsługuje dane osobowe wędkarzy, więc adres IP, ciasteczka i zawartość sesji nie
  wychodzą poza aplikację.
- `censor.body_fields` rozszerzone o `current_password`, `token`, `two_factor_code`, `iban`
  i `bank_account_number` — pola, które ta aplikacja realnie przyjmuje.

### Logi

Kanał `flare` jest zdefiniowany w [`config/logging.php`](../../config/logging.php), ale **nie stoi
w domyślnym stosie**. Żeby logi trafiały do Flare, trzeba naraz:

1. dopisać kanał do stosu: `LOG_STACK=single,flare`,
2. ustawić `FLARE_LOG=true`,
3. mieć niepusty `FLARE_KEY`.

Próg poziomu logu ustawia `minimal_log_level` w `config/flare.php` (`null` = wszystko).

### Testy

[`phpunit.xml`](../../phpunit.xml) wymusza (`force="true"`) pusty `FLARE_KEY` oraz `FLARE_REPORT`,
`FLARE_TRACE` i `FLARE_LOG` na `false`. Pakiet testów **nie wysyła niczego do Flare**, nawet gdy
środowisko ma ustawiony klucz.

## Praca z Flare z poziomu Claude Code

W katalogu głównym leży [`flare-skill.conf`](../../flare-skill.conf) — plik ograniczający skill
`flare-project` do projektów tego repozytorium. Bez niego skill odmawia uruchomienia jakiejkolwiek
komendy (token CLI jest kontem, nie projektem, więc bez tego pliku łatwo trafić w cudzy projekt).

Format: sekcja `[projects]`, jedna linia na środowisko, `<id> = <etykieta> ; <nazwa> ; <stage>`.
**Pierwsza linia to środowisko podstawowe** — odpytywane najpierw i domyślne, gdy nie wskazano
innego.

Przykładowe komendy (`flare` CLI, uwierzytelnienie w `~/.flare/config.json`):

```bash
flare list-project-errors --project-id=93111 --filter-status=open --sort=-last_seen_at
```

```bash
flare get-monitoring-summary --project-id=93111 --filter-interval=24h
```

Sprawdzenie, czy aplikacja faktycznie dosięga Flare (wysyła testowy wyjątek):

```bash
docker compose exec app php artisan flare:test
```
