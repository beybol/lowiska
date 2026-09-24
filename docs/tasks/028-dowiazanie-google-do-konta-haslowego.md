# 028 — Dowiązanie logowania Google do istniejącego konta hasłowego

> **Pochodzenie:** zgłoszenie autora 24.09.2026. Wzorzec: projekt **WorkSnap**, ADR-016
> „Strategia dowiązywania konta Google do istniejącego konta" (opcja A — auto-linkage
> z rozróżnieniem stanu weryfikacji), `tests/Feature/GoogleOAuthTest.php`.

## Opis problemu

Konto założone hasłem na adres, który użytkownik ma też w Google, **nie daje się potem otworzyć
logowaniem Google** — mimo że adres jest ten sam. `SocialAuthController::callback()` świadomie
odmawia: „An account with this address already exists. Sign in with your password first."

To zachowanie jest dziś **regułą bezpieczeństwa**, a nie przeoczeniem
(`docs/conventions/autoryzacja.md` §6, security-review 2026-09-20): „na adres należący do konta
hasłowego nie loguje cicho, tylko odsyła do logowania hasłem" — ochrona przed przejęciem konta
przez kogoś, kto ustawi u dostawcy cudzy adres. Obrona ma jednak dziurę w produkcie: **nie istnieje
żaden ekran, na którym właściciel konta mógłby „połączyć je świadomie"**, więc odmowa jest ślepym
zaułkiem.

WorkSnap rozwiązał ten sam problem automatycznym dowiązaniem, bezpiecznym dzięki dwóm warunkom:
dostawca **potwierdza** adres, a panel wymaga **zweryfikowanego** adresu lokalnego
(`MustVerifyEmail`) — konto niezweryfikowane jest martwe dla każdego poza prawowitym właścicielem
skrzynki. W Łowiskach oba warunki są spełnione: `User implements MustVerifyEmail`, a oba panele
mają `->emailVerification()`.

**Stan w kodzie (rozpoznanie 24.09.2026):**
- `users.provider` + `users.provider_id` (unikalna para, poza `$fillable` — zapis przez
  `forceFill`); **jedno** powiązanie z dostawcą na konto.
- `users.password` jest **NOT NULL** — konta z Google dostają losowe hasło (`bcrypt(str()->random(16))`).
- Socialite, Google: `userinfo` v3 zwraca `email_verified` (sterownik mapuje też `verified_email`).
  Dzisiejszy warunek odrzuca tylko jawne `false` — **brak klucza przepuszcza** (Facebook go nie zwraca).
- 2FA obowiązuje także na ścieżce społecznościowej (`autoryzacja.md` §6).
- **Logowanie społecznościowe nie ma dziś ANI JEDNEGO testu.**
- Lista kont w `/admin` (`UserResource`) nie mówi, czy konto loguje się hasłem, czy przez dostawcę.

## Wymagania

### 1. Dowiązanie Google do istniejącego konta (wzorem WorkSnap ADR-016, opcja A)

Kolejność rozpoznania w `SocialAuthController::callback()`:

1. **Dostawca musi JAWNIE potwierdzić adres** (`email_verified === true`) — dla ścieżki dowiązania
   brak klucza **nie** wystarcza. Inaczej: odmowa, bez tworzenia i bez łączenia.
2. **Znany dostawca + znane ID** → logowanie jak dziś.
3. **Adres należy do konta bez dostawcy** (dopasowanie po adresie bez rozróżniania wielkości liter,
   bez normalizacji kropek i aliasów):
   - konto **zweryfikowane** (`email_verified_at` niepuste) → zapisz `provider`/`provider_id`
     (`forceFill`), zaloguj; **hasło zostaje** — konto staje się **hybrydowe** (hasło i Google);
   - konto **niezweryfikowane** → zapisz dostawcę, ustaw `email_verified_at`, **zastąp hasło
     losowym** (kolumna jest NOT NULL), oznacz konto jako **bez znanego hasła** (pkt 2) i pokaż
     **na ekranie 2FA** komunikat, że konto przejęło logowanie Google, a hasło można ustawić przez
     „Nie pamiętasz hasła?". Uzasadnienie jak w WorkSnap: takie konto było
     nieosiągalne dla swojego twórcy (panel wymaga weryfikacji), więc przejmuje je prawowity
     właściciel skrzynki.
4. **Adres powiązany z INNYM dostawcą** → odmowa jak dziś (jedno powiązanie na konto).
5. **Brak dopasowania** → nowe konto jak dziś (rola `owner` wyłącznie przy zakładaniu konta).

- **Te same zasady dla kont `is_admin`** (decyzja autora, 24.09.2026) — 2FA dalej obowiązuje po
  zalogowaniu Google, a dostawca musi potwierdzić adres.
- **Dowiązanie trafia do dziennika zmian** — `provider`/`provider_id` nie są w `$fillable`, więc
  `LogsActivity` ich nie widzi; wpis jawny (`dziennik-zmian.md` §4) zawiera **wyłącznie nazwę
  dostawcy** (`provider: null → google`), **bez `provider_id`** — identyfikator konta Google to dana
  osobowa, a do audytu wystarcza fakt i moment dowiązania.
- 2FA, odnowienie identyfikatora sesji i przekierowania po zalogowaniu — **bez zmian**.

### 2. Czy konto ma hasło znane użytkownikowi

- Nowa kolumna **`users.has_password`** (boolean, NOT NULL, domyślnie `true`) — czy użytkownik zna
  hasło do konta. Dziś tego nie wiadomo: konto założone przez Google ma hasło **losowe**,
  nieodróżnialne od prawdziwego.
- **Jeden dom reguły — hak `saving` modelu `User`:** zmiana `password` bez jawnego ustawienia
  `has_password` oznacza hasło ustawione przez człowieka → `has_password = true`. Obejmuje to reset
  hasła, zmianę w profilu i ustawienie hasła przez administratora bez dopisywania czegokolwiek w tych
  miejscach. `SocialAuthController` ustawia losowe hasło razem z jawnym `has_password = false`.
- `has_password` jest **poza `$fillable`** — zapis przez `forceFill` albo hak, jak `provider`.
- **Migracja danych:** istniejące konta z dostawcą → `has_password = false` (dziś jedyną drogą do
  dostawcy było założenie konta przez Google, więc hasło jest losowe); pozostałe → `true`.

### 3. Sposób logowania na liście kont w `/admin`

- `UserResource` dostaje **kolumnę „Logowanie"** z trzema etykietami:
  - **„Hasło"** — konto bez dostawcy;
  - **„Google"** — konto z dostawcą i bez znanego hasła (założone przez Google albo przejęte jako
    niezweryfikowane);
  - **„Google + hasło"** — konto dowiązane, które zachowało hasło, albo konto z Google, któremu
    użytkownik ustawił hasło.
  Nazwa dostawcy w etykiecie pochodzi z `provider` (dziś w praktyce „Google").
- **Filtr po sposobie logowania** — te same trzy wartości.

## Kryteria akceptacji

- [ ] Konto hasłowe **zweryfikowane** + logowanie Google tym samym adresem (Google potwierdza adres)
      → zalogowanie przez 2FA, konto ma `provider=google`, hasło działa dalej, brak duplikatu konta.
- [ ] Konto hasłowe **niezweryfikowane** + logowanie Google → konto zweryfikowane, dostawca zapisany,
      stare hasło przestaje działać, komunikat o ustawieniu hasła.
- [ ] Brak potwierdzenia adresu przez dostawcę (`false` albo brak klucza) przy adresie istniejącego
      konta → odmowa, konto nietknięte.
- [ ] Adres powiązany z innym dostawcą → odmowa jak dziś.
- [ ] Nowy adres → nowe konto z rolą `owner` jak dziś; ponowne logowanie tym samym dostawcą → to
      samo konto, bez ponownego nadania roli.
- [ ] Dopasowanie po adresie nie rozróżnia wielkości liter (`Jan@Example.com` = `jan@example.com`).
- [ ] Konto `is_admin` dowiązuje się na tych samych zasadach, a po zalogowaniu wymagane jest 2FA.
- [ ] Dowiązanie zostawia wpis w dzienniku zmian z nazwą dostawcy, bez `provider_id`.
- [ ] Komunikat po przejęciu konta niezweryfikowanego widać na ekranie 2FA.
- [ ] `has_password`: konto z Google → `false`; reset albo zmiana hasła → `true`; dowiązanie konta
      zweryfikowanego → bez zmiany (`true`); migracja ustawia `false` istniejącym kontom z dostawcą.
- [ ] Lista kont w `/admin`: kolumna „Logowanie" (Hasło / Google / Google + hasło) i filtr po tych
      trzech wartościach.
- [ ] `autoryzacja.md` §6 przepisany: dowiązanie zamiast odmowy, warunki bezpieczeństwa wprost.
- [ ] Zielony pełny pakiet testów (T3).

## Zakres testów

- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** trzy etykiety kolumny „Logowanie" (rozstrzygnięcie z `/review-task`) wymagają
  kolumny `users.has_password` i haka w modelu `User` — **migracja i `User` to wyzwalacze T3**
  z `CLAUDE.md`, więc tier nie jest przedmiotem wyboru. Przy `/create-task` zatwierdzono T2 przy
  założeniu „bez migracji i bez zmian w `User`", które przestało obowiązywać. Nowa klasa testów
  logowania społecznościowego (np. `SocialAuthTest`, `Socialite::fake()` wzorem WorkSnap
  `GoogleOAuthTest`) wchodzi w skład pakietu.

## Zakres wyłączeń

- **Ekran łączenia i odłączania dostawców w profilu** — dowiązanie następuje przy logowaniu.
- **Wiele dostawców na jednym koncie** (Google i Facebook naraz) — model ma jedną parę
  `provider`/`provider_id`; zmiana to osobne zadanie (tabela powiązań jak w WorkSnap).
- **Facebook jako ścieżka dowiązania** — nie zwraca potwierdzenia adresu, więc z wymagania 1.1
  dowiązania nie przejdzie; zakładanie nowych kont przez Facebook bez zmian.
- **Pakiet `dutchcodingcompany/filament-socialite`** użyty w WorkSnap — zostajemy przy własnym
  kontrolerze; przenosimy regułę, nie mechanizm.
- **Nullowalne hasło** dla kont Google — kolumna zostaje NOT NULL (hasło losowe); to, czy hasło
  jest znane, mówi `has_password`.

## Zmiany dokumentacji

- [ ] `docs/conventions/autoryzacja.md` §6 — **przepisać** regułę „na adres konta hasłowego nie
      loguje cicho": dowiązanie przy potwierdzonym adresie, rozróżnienie konta zweryfikowanego
      i niezweryfikowanego, dlaczego to bezpieczne (`MustVerifyEmail` + `emailVerification()` obu
      paneli), jedno powiązanie na konto; ⚠️ warunki ADR-019 jako niezmiennik (kanał 2FA na adres
      konta, weryfikacja w obu panelach); `has_password` poza `$fillable`.
- [ ] `docs/conventions/panel-admina.md` — kolumna i filtr sposobu logowania w `UserResource`.
- [ ] `MANUAL.md` — logowanie przez Google na konto założone wcześniej hasłem (część I) i kolumna
      „Logowanie" na liście kont (część II).
- [ ] `CHANGELOG.md` — wpis przez skill `changelog`.

## Ograniczenia techniczne

- Laravel 13, Filament 5, `laravel/socialite` (w testach `Socialite::fake()`), PHP 8.4.
- `provider`/`provider_id` zostają **poza `$fillable`** — zapis wyłącznie przez `forceFill`
  (`autoryzacja.md` §6).
- Komunikaty dla użytkownika po angielsku jako klucze, tłumaczenia w `lang/pl.json`.
- ⚠️ **Ochrona przed kontem Google na cudzy adres opiera się na 2FA wysyłanym na adres konta**
  (ADR-019): nie zmieniaj kanału 2FA ani nie wyłączaj go na ścieżce społecznościowej w ramach tego
  zadania.
- Powierzchnie do przeczytania przed edycją: `autoryzacja.md`, `panel-admina.md`,
  `strona-publiczna.md` (⛏️ jeszcze nie istnieje — trasy `auth/{provider}` i widoki Breeze),
  `dziennik-zmian.md`.

## Rozstrzygnięcia

Ustalone przy `/review-task`, 24.09.2026:

- **Kierunek: automatyczne dowiązanie zamiast odmowy** — ADR-019 (decyzja autora podjęta przy
  przeglądzie). Reguła z security-review 2026-09-20 zostaje zastąpiona, nie obejściem.
- **Trzy etykiety kolumny „Logowanie": „Hasło" / „Google" / „Google + hasło"** (decyzja autora).
  Wymaga to kolumny `users.has_password` i haka w `User` — stąd tier T3 i nowa pozycja wymagań 2.
- **Wpis w dzienniku zmian bez `provider_id`**, wyłącznie nazwa dostawcy. Identyfikator konta
  Google to dana osobowa, a do audytu wystarcza fakt i moment dowiązania.
- **Komunikat po przejęciu konta niezweryfikowanego — na ekranie 2FA.** To pierwszy ekran po
  callbacku, więc użytkownik zobaczy go na pewno.
- **Konta `is_admin` na tych samych zasadach** (decyzja autora przy `/create-task`) — ochronę daje
  2FA na adres konta, zapisane w ADR-019.

## Powiązane ADR-y

- [ADR-019 — Dowiązanie logowania dostawcy do istniejącego konta po potwierdzonym adresie](../adr/ADR-019-dowiazanie-dostawcy-logowania-do-istniejacego-konta.md) —
  przyjęty 24.09.2026.
