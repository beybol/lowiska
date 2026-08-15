# ADR-003 — Zaufanie do proxy: `at: '*'` wyłącznie z jawną maską nagłówków

- **Status:** accepted
- **Data:** 2026-08-15
- **Zadanie:** [002 — Zaufanie do proxy (`trustProxies`) dla wdrożenia za Cloud Run](../tasks/implemented/002-zaufanie-do-proxy-za-cloud-run.md)

## Kontekst

Aplikacja ma stanąć na **Cloud Run**, gdzie TLS kończy się na froncie Google, a do kontenera trafia
zwykły HTTP z nagłówkami `X-Forwarded-*`. Bez zaufanego proxy Laravel widzi połączenie jako `http`
i generuje adresy zasobów ze schematem `http://` na stronie serwowanej po `https://` — przeglądarka
blokuje je jako mixed content i panele Filamenta renderują się bez styli. Poprawka jest konieczna
**przed pierwszym wdrożeniem**.

Trzeba rozstrzygnąć **dwie rzeczy naraz**, i to jest sedno tego ADR-a: komu ufamy (`at:`)
**oraz** którym nagłówkom ufamy (`headers:`).

**Dlaczego to nie jest oczywiste.** Naturalny odruch — skopiowanie linijki z bliźniaczego projektu —
prowadzi do `trustProxies(at: '*')` bez argumentu `headers:`. Audyt bezpieczeństwa bliźniaczego projektu
(2026-08-15) wykazał, że taki zapis jest **podatnością o wadze High** (CWE-644):

- pominięty `headers:` oznacza **domyślną maskę Laravela**, a ta zawiera `X-Forwarded-Host`;
- przy `at: '*'` każdy klient jest zaufanym proxy, więc **dowolny klient dyktuje host**, z którego
  Laravel buduje adresy absolutne;
- linki weryfikacji e-maila i resetu hasła powstają **z hosta żądania**, nie z `APP_URL`, więc
  atakujący zamawia reset dla ofiary z podrobionym nagłówkiem, a ofiara dostaje autentyczny,
  podpisany DKIM mail z prawdziwej domeny, którego link oddaje token atakującemu;
- **podpis URL-a nie chroni** — sygnatura liczona jest z `$request->url()`, które czyta ten sam
  podrobiony nagłówek, więc waliduje się poprawnie.

**Stan Łowisk:** dziś **nie ma** w kodzie `trustProxies` ani `trustHosts`, więc Laravel nie ufa
żadnemu proxy i `X-Forwarded-Host` jest ignorowany (zweryfikowane empirycznie: żądanie z tym
nagłówkiem nie zmienia generowanych adresów). Podatność powstałaby **dopiero przy realizacji
zadania 002**, wykonanej naiwnie.

**Dlaczego to ADR, a nie rozstrzygnięcie w zadaniu.** Kod po naprawie wygląda na zbędnie
rozbudowany: `at: '*'` z trzema stałymi zamiast jednego argumentu. Bez zapisanego uzasadnienia
pierwszy „porządkujący" refaktor skróci go do gołego `at: '*'` i przywróci podatność — a testy
regresyjne wyjaśnią *co* się zepsuło, nie *dlaczego* tak było napisane.

## Alternatywy

### Opcja A — `at: '*'` z jawną maską `HEADER_X_FORWARDED_FOR | _PORT | _PROTO`

```php
$middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
    | Request::HEADER_X_FORWARDED_PORT
    | Request::HEADER_X_FORWARDED_PROTO);
```

**Zalety:**
- **Zachowuje jedyny powód, dla którego `trustProxies` jest tu potrzebne** — wykrywanie HTTPS za
  proxy (`X-Forwarded-Proto`) oraz prawdziwy adres klienta (`X-Forwarded-For`, istotny dla
  limitowania prób logowania i `spatie/laravel-activitylog`).
- **Odcina `X-Forwarded-Host` i `X-Forwarded-Prefix`** — czyli dokładnie te nagłówki, których
  zaufanie tworzy podatność, a których nie potrzebujemy do niczego.
- Działa na Cloud Run, gdzie adresy proxy **nie są stałą pulą**, więc lista zakresów IP i tak
  odpada.
- Rozwiązanie sprawdzone: dokładnie tą zmianą naprawiono bliźniaczy projekt, z testami zweryfikowanymi
  negatywnie.

**Wady:**
- Wygląda na przekombinowane, dopóki nie zna się historii — stąd ten ADR i wymóg komentarza w kodzie.
- `at: '*'` nadal znaczy „ufaj każdemu, kto się połączy", więc bezpieczeństwo opiera się na
  założeniu o platformie (patrz „Ryzyko" niżej).

### Opcja B — `at: '*'` bez maski (domyślne nagłówki)

**Zalety:**
- Najkrótszy zapis, identyczny z tym, co dziś stoi w obu bliźniaczych projektach (przed naprawą).
- Nie trzeba importować `Illuminate\Http\Request` ani rozumieć masek bitowych.

**Wady:**
- **Jest to podatność High opisana wyżej** — potwierdzona empirycznie w bliźniaczym projekcie,
  z gotową ścieżką do przejęcia konta przez reset hasła.
- Podpis URL-a nie stanowi zabezpieczenia, więc nie ma tu drugiej warstwy, która by to łagodziła.
- Odrzucona bez dalszej dyskusji — wymieniona wyłącznie po to, żeby nie wróciła jako „uproszczenie".

### Opcja C — lista adresów proxy zamiast `'*'`

**Zalety:**
- Najwęższe zaufanie: nagłówki honorowane tylko od znanych adresów.
- Przy stałym, znanym proxy byłoby to rozwiązanie podręcznikowe.

**Wady:**
- **Niewykonalne na Cloud Run** — front Google nie ma stałej, dokumentowanej puli adresów
  do wpisania; lista byłaby zgadywaniem, które psuje się po cichu przy zmianie infrastruktury.
- Nie rozwiązuje właściwego problemu: nawet z listą adresów **domyślna maska nadal ufałaby
  `X-Forwarded-Host`**, więc podatność zniknęłaby dopiero wtedy, gdyby proxy było jedynym
  możliwym źródłem ruchu. To pokazuje, że **maska, a nie zakres adresów, jest tu realnym
  zabezpieczeniem**.

### Opcja D — Opcja A + `trustHosts(at: [...])` jako druga warstwa

**Zalety:**
- Obrona w głąb: nawet gdyby maska została kiedyś cofnięta, lista dozwolonych hostów zatrzymałaby
  zatrucie.

**Wady:**
- **Wywraca środowisko lokalne i pakiet testów** — hosty `localhost:11000`, `lowiska-app:8000`
  i te używane przez PHPUnit nie są hostami produkcyjnymi; lista musiałaby zależeć od środowiska.
- Rozważone i **świadomie odrzucone w audytowanym projekcie** z tego samego powodu; maska zamyka podatność
  samodzielnie.
- Dokłada konfigurację zależną od środowiska tam, gdzie dziś jej nie ma.

## Rekomendacja

**Opcja A — `at: '*'` z jawną maską**, przy czym oba elementy są **jedną decyzją, nie dwiema**.

1. **`at: '*'` jest wymuszone przez platformę.** Cloud Run nie daje stałej puli adresów proxy
   (Opcja C), a zaufanie oparte jest na tym, że do kontenera **nie da się dostać z pominięciem
   frontu Google**. To założenie o infrastrukturze, nie o kodzie — i dlatego musi być zapisane.
2. **Maska jest tym, co czyni `at: '*'` bezpiecznym.** Bez niej `'*'` znaczy „każdy klient dyktuje
   host". Rozdzielenie tych dwóch elementów w dokumentacji jest dokładnie tym, jak powstała
   podatność w audytowanym projekcie: komentarz w kodzie uzasadniał tylko `X-Forwarded-Proto`, a kod ufał
   całej domyślnej masce.
3. **`trustHosts` nie jest potrzebny** (Opcja D) i kosztowałby konfigurację zależną od środowiska;
   wracamy do niego tylko, jeśli pojawi się powód niezależny od tej podatności.

**Niezmiennik do utrwalenia:** *w tym projekcie `trustProxies` nigdy nie jest wywoływane bez
argumentu `headers:`.* Jeśli kiedyś dojdzie kolejny nagłówek, dopisuje się go do maski jawnie —
nie przez powrót do wartości domyślnej.

**Ryzyko rezydualne, świadomie przyjęte:** przy `at: '*'` bezpieczeństwo `X-Forwarded-For`
(a więc poprawność adresu klienta w logach i limiterze) opiera się na tym, że ruch do kontenera
przechodzi wyłącznie przez front Google. Gdyby aplikacja trafiła kiedyś na platformę bez tej
gwarancji — na przykład za własny reverse proxy dostępny publicznie — decyzję trzeba przejrzeć
ponownie, bo klient mógłby wtedy podszyć się pod dowolny adres IP.

## Decyzja
Decyzja: A

Uzasadnienie: zadziałało dobrze w innym projekcie
