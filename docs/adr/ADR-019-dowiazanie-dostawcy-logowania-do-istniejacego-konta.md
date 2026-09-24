# ADR-019 — Dowiązanie logowania dostawcy do istniejącego konta po potwierdzonym adresie

- **Status:** accepted
- **Data:** 2026-09-24
- **Zadanie:** [028 — Dowiązanie logowania Google do istniejącego konta hasłowego](../tasks/028-dowiazanie-google-do-konta-haslowego.md)

## Kontekst

Do jednego adresu e-mail prowadzą w Łowiskach **dwie drogi**: rejestracja hasłem (Breeze
i rejestracja w panelach) oraz logowanie przez dostawcę (`SocialAuthController`, Google). Kolizja
jest nieunikniona: ktoś zakłada konto hasłem, a potem klika „Zaloguj przez Google" tym samym adresem.

Dziś kontroler **odmawia** („An account with this address already exists. Sign in with your password
first."). To reguła z security-review 2026-09-20, zapisana w `autoryzacja.md` §6: dowiązanie po samym
adresie to klasyczny scenariusz przejęcia konta — wystarczyłoby u dostawcy ustawić adres cudzego
konta. Reguła zakłada jednak, że właściciel „połączy konto świadomie" — a **ekranu łączenia nie ma**,
więc odmowa jest ślepym zaułkiem dla prawowitego właściciela.

Warunki, na których można oprzeć bezpieczniejszą odpowiedź:

- **Dostawca potwierdza adres.** Google (`userinfo` v3) zwraca `email_verified`; potwierdzenie
  oznacza, że właściciel konta Google odebrał kod wysłany na ten adres.
- **Lokalny adres jest weryfikowany.** `User implements MustVerifyEmail`, a oba panele mają
  `->emailVerification()` — konto niezweryfikowane jest **martwe** dla swojego twórcy (link
  weryfikacyjny trafił do prawdziwego właściciela skrzynki). Klasyczne pre-hijacking (napastnik
  rejestruje cudzy adres i czeka) jest więc zablokowane już na poziomie weryfikacji.
- **2FA na ścieżce społecznościowej wysyła kod na adres KONTA** (`autoryzacja.md` §6). Nawet konto
  Google z potwierdzonym adresem, którym napastnik nadal dysponuje (np. konto Google założone na
  firmowy adres pracownika, który odszedł i nie ma już skrzynki), nie przejdzie drugiego kroku bez
  dostępu do tej skrzynki.
- Jedno powiązanie na konto — `users.provider` + `users.provider_id`, unikalna para.

Ten sam problem rozwiązał projekt WorkSnap (ADR-016 tamtego repozytorium, opcja A).

## Alternatywy

### Opcja A — Automatyczne dowiązanie przy potwierdzonym adresie, z rozróżnieniem stanu weryfikacji konta

Warunek wstępny: dostawca **jawnie** potwierdza adres (`email_verified === true`; brak klucza nie
wystarcza). Przy trafieniu w konto bez dostawcy:
- konto **zweryfikowane** → zapisz dostawcę, hasło zostaje (konto hybrydowe);
- konto **niezweryfikowane** → zapisz dostawcę, ustaw weryfikację, zastąp hasło losowym, komunikat
  o ustawieniu hasła przez reset.

Adres z innym dostawcą → odmowa (jedno powiązanie na konto). Brak dopasowania → nowe konto.

**Zalety:**
- Zamyka ślepy zaułek bez ekranu łączenia kont.
- Konto zweryfikowane staje się hybrydowe — utrata dostępu do Google nie blokuje konta.
- Przypadek niezweryfikowany rozwiązuje się naturalnie: martwe konto przejmuje właściciel skrzynki.
- Sprawdzony wzorzec (WorkSnap), bez nowego pakietu.

**Wady:**
- Bezpieczeństwo przechodzi z „nigdy nie łączymy po adresie" na **trzy warunki naraz**: potwierdzenie
  u dostawcy, weryfikacja lokalna i 2FA na adres konta. Osłabienie któregokolwiek (np. 2FA przez
  aplikację zamiast e-maila albo wyłączenie weryfikacji w panelu) otwiera przejęcie konta.
- Osoba, która ustawiła hasło i nie zweryfikowała adresu, traci to hasło przy pierwszym logowaniu
  przez dostawcę (rzadkie, odwracalne resetem).

### Opcja B — Odmowa zostaje, dowiązanie wyłącznie z zalogowanej sesji (ekran w profilu)

**Zalety:**
- Najostrzejsza intencja: powiązanie powstaje tylko z konta, na które ktoś już się zalogował.

**Wady:**
- Wymaga ekranu łączenia i odłączania dostawców w profilu — nowy zakres UI i testów.
- Konto założone przez Google, które wypadło z sesji, dostaje komunikat „zaloguj się hasłem", którego
  nigdy nie miało.
- Przypadek konta niezweryfikowanego (martwe konto blokujące prawowitego właściciela) i tak wymaga
  wyjątku.

### Opcja C — Bezwarunkowe dowiązanie po adresie

**Wady:**
- Ufa adresowi z tokenu bez potwierdzenia — jedynej informacji, która czyni adres tożsamością.
  Wykluczona.

## Rekomendacja

**Opcja A.** Realizuje cel produktowy bez nowego ekranu, a ryzyko, dla którego security-review
wprowadził odmowę, domyka zestaw trzech warunków, które w Łowiskach już obowiązują (weryfikacja adresu
w obu panelach, 2FA na adres konta) plus jeden dokładany (jawne potwierdzenie adresu u dostawcy).
Opcja B przenosi problem na ekran, którego nie ma, i i tak potrzebuje wyjątku; opcja C jest wykluczona.

## Decyzja

**Wybrano Opcję A** (decyzja autora, 24.09.2026, przy `/review-task 028`).

- **Te same zasady obejmują konta `is_admin`** (decyzja autora przy `/create-task`) — ochronę daje
  2FA na adres konta.
- ⚠️ **Warunki A są niezmiennikiem, nie szczegółem implementacji.** Zmiana kanału 2FA (aplikacja,
  SMS), wyłączenie 2FA na ścieżce dostawcy albo zdjęcie `->emailVerification()` z któregoś panelu
  **wymaga ponownej oceny tej decyzji** — bez niej dowiązanie po adresie staje się dokładnie tym
  przejęciem konta, przed którym chronił security-review 2026-09-20.
- Reguła trafia do `autoryzacja.md` §6 w miejsce dotychczasowej odmowy (przepisana, nie dopisana obok).
