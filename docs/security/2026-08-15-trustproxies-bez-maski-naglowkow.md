# `trustProxies(at: '*')` bez maski nagłówków — wzorzec przejęty z bliźniaczych projektów

**Status:** ✅ zapobieżone przed wprowadzeniem — Łowiska nigdy nie miały tego kodu w repozytorium.
**Data:** 2026-08-15 · **Źródło:** audyt bezpieczeństwa WorkSnapa (osobne repozytorium), przeniesiony
tutaj przy `/review-task 002`, zanim zadanie 002 zostało zaimplementowane.
**Waga, gdyby wystąpiło:** High (CWE-644, Improper Neutralization of HTTP Headers).

## Mechanizm

`trustProxies(at: '*')` wywołane **bez argumentu `headers:`** przyjmuje domyślną maskę Laravela,
a ta zawiera `X-Forwarded-Host`. Przy `at: '*'` każdy klient jest zaufanym proxy, więc **dowolny
klient dyktuje host**, z którego Laravel buduje adresy absolutne — w tym podpisane linki resetu
hasła i weryfikacji e-maila. Podpis URL-a nie chroni: sygnatura liczona jest z `$request->url()`,
które czyta ten sam podrobiony nagłówek.

Potwierdzone empirycznie w WorkSnapie: żądanie z `X-Forwarded-Host: evil.tld` wyrenderowało
w stronie `http://evil.tld/icon-512.png`. Pełna analiza (osobne repozytorium):
`WorkSnap/docs/security/2026-08-15-zatrucie-hosta-i-obejscie-2fa-na-admin.md`.

## Dlaczego to trafiło do Łowisk

Zadanie [002](../tasks/implemented/002-zaufanie-do-proxy-za-cloud-run.md) miało wprowadzić `trustProxies`
przed wdrożeniem na Cloud Run. Pierwotna treść zadania proponowała dokładnie ten podatny wariant
(`trustProxies(at: '*')` bez maski) — naturalny odruch skopiowania linijki z bliźniaczego projektu,
zanim ten sam projekt zdążył ją tam naprawić. Poprawione przy `/review-task 002`, **przed**
implementacją — Łowiska nigdy nie miały podatnego kodu w commitowanej historii.

## Naprawa (wdrożona w zadaniu 002)

```php
$middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
    | Request::HEADER_X_FORWARDED_PORT
    | Request::HEADER_X_FORWARDED_PROTO);
```

Maska zachowuje wykrywanie HTTPS za proxy i prawdziwy adres klienta, odcina `X-Forwarded-Host`
i `X-Forwarded-Prefix`. Uzasadnienie i odrzucone alternatywy:
[ADR-003](../adr/ADR-003-zaufanie-do-proxy-i-maska-naglowkow.md).

## Weryfikacja

Test: `tests/Feature/TrustedProxyHeadersTest.php`, cztery przypadki (schemat z `X-Forwarded-Proto`,
adres klienta z `X-Forwarded-For`, odporność adresów absolutnych i podpisanych na
`X-Forwarded-Host`).

**Sprawdzone negatywnie** (2026-08-15, w ramach `/implement-task 002`): po tymczasowym dopisaniu
`Request::HEADER_X_FORWARDED_HOST` do maski w `bootstrap/app.php`:

- `X-Forwarded-Host nie zmienia adresów absolutnych` — **padł**, `url('/')` zwróciło `http://evil.tld`.
- `X-Forwarded-Host nie zmienia adresów podpisanych` — **padł**, link weryfikacji e-maila zawierał
  `http://evil.tld/verify-email/…`.
- `X-Forwarded-Proto: https czyni żądanie bezpiecznym…` — **pozostał zielony**, co potwierdza, że
  testy rozróżniają nagłówek, który ma być honorowany, od tego, który ma być odcięty.

Maska przywrócona do stanu z ADR-003 natychmiast po weryfikacji; `diff` potwierdził identyczność
z wersją sprzed testu.

## Wniosek

**Domyślna maska `trustProxies` jest szersza, niż sugeruje powód jej włączenia.** Jeśli włączasz
zaufanie do proxy dla jednego nagłówka (tu: `X-Forwarded-Proto`), wypisz ten jeden nagłówek — nigdy
nie polegaj na wartości domyślnej. Ten sam wniosek z audytu WorkSnapa; tutaj potwierdzony drugi raz,
zanim zdążył się zmaterializować.
