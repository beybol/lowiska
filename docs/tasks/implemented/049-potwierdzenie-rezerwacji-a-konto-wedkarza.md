# 049 — Potwierdzenie rezerwacji oddzielone od połączenia z kontem wędkarza

## Opis problemu

Gdy operator wpisze adres e-mail, a łowisko wymaga potwierdzeń, wędkarz dostaje mail z linkiem
„Potwierdź i połącz z kontem” (zadanie 043). Kliknięcie robi dziś trzy rzeczy naraz i bez logowania
(`AnglerLinking::confirm()`):

- potwierdza oczekujące rezerwacje wędkarza w łowisku;
- gdy konto o tym adresie istnieje, dowiązuje je do łowiska. Przy konflikcie telefonu może zapisać
  nowy numer w **profilu** tego konta;
- gdy konta nie ma, zakłada je po cichu: losowe hasło, `has_password = false`, adres zweryfikowany.

Skutki:

1. **Link działa jak klucz do cudzego konta.** Przekazany albo podejrzany mail wystarcza, żeby
   powiązać istniejące konto z łowiskiem i zmienić telefon w jego profilu.
2. **Wędkarz ma konto, o którym nie wie.** Nie umie się zalogować, a przy próbie rejestracji słyszy,
   że adres jest zajęty.
3. **Brak zgód.** Konto i relacja z łowiskiem powstają bez akceptacji regulaminów i polityk.

Autor zdecydował (ADR-024, Aktualizacja 2026-10-11): potwierdzenie rezerwacji zostaje jednym
kliknięciem bez konta, a połączenie z kontem staje się osobnym, dobrowolnym krokiem z logowaniem albo
rejestracją oraz zgodami.

## Wymagania

**Potwierdzenie rezerwacji (bez konta)**
- Strona pod linkiem z maila (`portal.reservation.confirm`) potwierdza oczekujące rezerwacje wędkarza
  w łowisku przyciskiem „Potwierdź rezerwację”. Nie wymaga logowania, nie zakłada konta i nie zmienia
  żadnego rekordu `users`. Źródło potwierdzenia: `ConfirmationSource::Link`.
- Po potwierdzeniu ta sama strona pokazuje zachętę do połączenia z kontem:
  - **konto o tym adresie istnieje:** „Zaloguj się, żeby widzieć historię rezerwacji”;
  - **konta nie ma:** „Załóż konto, żeby mieć historię rezerwacji”;
  - przycisk „Nie teraz”: rezerwacja zostaje potwierdzona, a relacja zostaje `pending` z ważnym tokenem
    do `link_expires_at` (R1). Panel pokazuje wtedy „dane od łowiska”.
  - ⚠️ Rozróżnienie „istnieje / nie istnieje” widzi wyłącznie posiadacz linku, nigdy operator.
    Tak jest już dziś (`accountFor()` wołane tylko na stronie po kliknięciu).

**Połączenie z istniejącym kontem**
- Wymaga zalogowania na konto o adresie **równym** adresowi z prośby (`link_email`), hasłem albo
  przez Google. Logowanie przechodzi zwykłą ścieżką, łącznie z kodem 2FA z maila, i wraca na stronę
  łączenia. Powrót zapewnia znacznik w sesji (R2): przed przejściem na logowanie albo rejestrację
  strona zapisuje w sesji, że trwa łączenie dla tego tokenu. Każda ścieżka logowania (hasło,
  Google, weryfikacja kodu 2FA) po sukcesie sprawdza ten znacznik i kieruje na stronę łączenia,
  a gdy go nie ma, zachowuje się jak dziś. W sesji nie zapisujemy tokenu jawnie, tylko jego hash.
- Zalogowany na konto o innym adresie: odmowa połączenia z propozycją wylogowania. Relacji nie wiążemy
  z kontem o innym adresie.
- Logowanie przez Google zwracające inny adres niż adres z prośby nie łączy relacji (ADR-019 nadal
  obowiązuje dla samego logowania).
- Na stronie łączenia wędkarz zaznacza **wymagane zgody łowiska**. Dopiero potem następuje
  dowiązanie (`linked`) i ewentualny wybór telefonu z ADR-024 pkt 5. Zapis telefonu w profilu
  (opcja „save”) jest możliwy wyłącznie w tym kroku, po zalogowaniu.

**Założenie nowego konta**
- Hasłem: formularz z imieniem i nazwiskiem (podpowiedziane z `given_name`), adresem stałym i równym
  adresowi z prośby (pole tylko do odczytu, nie przyjmowane od klienta), hasłem i powtórzeniem
  hasła. Konto ma `has_password = true` i `email_verified_at = teraz`, bez osobnego maila
  weryfikacyjnego.
- Przez Google: dozwolone, gdy Google zwraca adres równy adresowi z prośby. Inny adres oznacza odmowę
  połączenia.
- Przy zakładaniu konta wędkarz zaznacza **wymagane zgody Fisherya i wymagane zgody łowiska**.
- Po założeniu konta relacja przechodzi w `linked`, a wędkarz jest zalogowany.
- **`AnglerLinking::createAccount()` znika.** Nigdzie nie powstaje konto bez hasła ani bez dostawcy
  logowania.

**Zgody (mechanizm wg ADR-028)**
- „Zgody łowiska” i „zgody Fisherya” to obowiązujące wersje wymaganych dokumentów. Regulamin to
  akceptacja, a polityka prywatności to potwierdzenie zapoznania się (ADR-028, trzy rodzaje zapisów).
- W 049 powstaje (ADR-028 §4):
  - rejestr zdarzeń zgód;
  - dokumenty Fisherya w `documents`: zakres globalny, opcjonalny rynek pusty na start, warianty
    językowe z wariantem wiążącym, rodzaj zmiany, data ogłoszenia i data wejścia w życie;
  - strona wersji dokumentów Fisherya w panelu admina;
  - dokumenty łowiska dostają rodzaj zmiany i datę ogłoszenia, a `required_at_registration`
    oznacza „akceptowany przy dowiązaniu konta do łowiska”;
  - preferencje i zgody kanałów tylko w schemacie i usłudze odczytu, bez interfejsu.
- **Zwykła rejestracja** (`/register` oraz nowe konto przez Google poza linkiem) też wymaga
  zaznaczenia wymaganych zgód Fisherya (R3). Konto przez Google nie może powstać bez zgód, także
  wtedy, gdy nowy użytkownik kliknie przycisk Google na stronie logowania. Jeśli zgód nie zaznaczono
  przed przekierowaniem, po powrocie od dostawcy konto jeszcze nie powstaje: użytkownik trafia na
  ekran zgód, a dane od dostawcy czekają w sesji. Logowanie istniejącego konta przez Google zgód nie
  wymaga (nowe wersje: ADR-028).

**Token i ważność**
- Link pozostaje jednorazowy w znaczeniu ADR-024, ale potwierdzenie rezerwacji go **nie zużywa**.
  Wędkarz może połączyć konto później, do terminu `link_expires_at`. Dzięki temu przejście przez
  logowanie albo rejestrację nie spala linku. Połączenie konta zużywa token.
- Zmiana adresu relacji przy statusie oczekującym nadal unieważnia link (047, D2).

**Widok łowiska**
- Status rezerwacji „potwierdzona” nie oznacza już „powiązana z kontem”. W panelu (lista i formularz
  rezerwacji, okno szczegółów w kalendarzu) widać osobno stan wędkarza: „dane od łowiska” albo
  **„potwierdzone konto”**. To ta sama etykieta, której użyje podgląd wędkarzy z zadania 048.

**Mail**
- Przycisk w `ReservationRequestMail` brzmi „Potwierdź rezerwację”. Treść wspomina o możliwości
  założenia konta albo zalogowania się. „Zrezygnuj / to nie ja” bez zmian.

## Kryteria akceptacji
- [x] Potwierdzenie z linku zmienia oczekujące rezerwacje w potwierdzone, nie tworzy ani nie zmienia
      rekordu `users` i nie zużywa tokenu.
- [x] Bez zalogowania nie da się dowiązać istniejącego konta ani zapisać telefonu w jego profilu.
      Test POST bez sesji odrzuca próbę.
- [x] Zalogowany na konto o innym adresie dostaje odmowę i propozycję wylogowania, a relacja zostaje
      niepowiązana.
- [x] Zalogowany na właściwe konto po zaznaczeniu wymaganych zgód łowiska łączy relację (`linked`).
      Bez zgód połączenie nie przechodzi.
- [x] Rejestracja hasłem z linku zakłada konto z `has_password = true`, adresem z prośby (adresu
      z żądania nie bierze pod uwagę) i zweryfikowanym e-mailem. Wymaga zgód Fisherya i łowiska.
- [x] Rejestracja i logowanie przez Google z innym adresem niż adres z prośby nie łączą relacji.
- [x] W kodzie nie ma ścieżki zakładającej konto z `has_password = false` poza `SocialAuthController`.
- [x] Zgody zapisują się zgodnie z ADR-028. Da się odtworzyć, kto, kiedy, w jakim kontekście i którą
      wersję dokumentu zaakceptował.
- [x] Zwykła rejestracja (`/register`) bez wymaganych zgód Fisherya jest odrzucana. Nowe konto przez
      Google bez zgód nie powstaje, także z przycisku na stronie logowania.
- [x] Logowanie hasłem, przez Google i przez ekran kodu 2FA wraca na stronę łączenia, gdy w sesji jest
      znacznik łączenia (R2). Bez znacznika przekierowanie się nie zmienia.
- [x] „Nie teraz” zostawia relację `pending` z ważnym tokenem, więc link z maila pozwala połączyć konto
      później (R1).
- [x] Panel pokazuje stan wędkarza („dane od łowiska” / „potwierdzone konto”) niezależnie od statusu
      rezerwacji.
- [x] Teksty UI po angielsku, tłumaczenia w `lang/pl.json`. Motyw przebudowany po zmianach widoków
      (`docker compose exec -T app npm run build`).
- [x] `vendor/bin/pint` czysty, pełny pakiet testów zielony (T3).

## Zakres testów
- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zadanie zakłada konta `User` (rejestracja z linku, ścieżka Google) i wymaga migracji
  na zapis zgód. Obie rzeczy są na liście wyzwalaczy T3 w `CLAUDE.md`, więc tier nie podlega wyborowi.
  Punktowo w trakcie: `--filter="AnglerLinking|ReservationLink|SocialAuth|Registration|Authentication|TwoFactor|ReservationPanel|Document"`.

## Zakres wyłączeń
- Konta bez hasła założone przez obecny mechanizm: istnieją tylko lokalnie, bez migracji danych
  (decyzja autora).
- Regulamin i polityka prywatności Fisherya jako treść: tekst czeka na prawnika (TODO-1, TODO-3).
  Zadanie buduje jedynie miejsce na wersjonowany dokument platformy i zapis jego akceptacji.
- Zgody przy zakupie (etap 4). Mechanizm z ADR-028 je obsłuży, ale podpięcie należy do etapu 4.
- Akcja „przekaż dane do łowiska” w panelu wędkarza, działająca bez linku z maila (TODO-7).
- Interfejs zgód kanałów (tabela w profilu, pola przy dowiązaniu, link wypisu), ekran ponownej
  akceptacji po zalogowaniu, powiadomienia o zmianie dokumentu, blokada zakupu bez akceptacji nowej
  wersji regulaminu łowiska oraz aneksy rynkowe do regulaminu Fisherya. To kolejne zadania wg ADR-028.
- Kanał SMS, płatność online, profil wędkarza z historią rezerwacji (zachęta tylko ją zapowiada).
- Zmiana zachowania „Zrezygnuj” i „To nie ja”.

## Zmiany dokumentacji
- [x] `docs/conventions/rezerwacje.md` — §3: przepływ potwierdzenia i łączenia, zgody, stan wędkarza
      w panelu.
- [x] `docs/conventions/autoryzacja.md` — §6: konto powstaje wyłącznie z udziałem wędkarza (hasło albo
      dostawca); usunąć opis kont zakładanych z linku bez hasła.
- [x] `docs/conventions/strona-publiczna.md` — strony pod tokenem: logowanie i rejestracja z powrotem
      na stronę łączenia.
- [x] `docs/conventions/panel-admina.md` — strona wersji dokumentów platformy (ADR-028).
- [x] `MANUAL.md` — §1 (rejestracja ze zgodami), §15 (rezerwacje: co widzi wędkarz po kliknięciu
      i co widzi łowisko), rozdział admina o dokumentach platformy.
- [x] `docs/project/DECYZJE-I-TODO-BIZNESOWE.md` — TODO-6: zdanie „konto powstaje po kliknięciu linku
      (bez hasła)” przestaje być prawdą; przepisać na „konto zakłada wędkarz sam (hasło albo Google)”.
- [x] ADR-024 — Aktualizacja z 2026-10-11 już zapisana. ADR-028 — status `accepted` po decyzji
      autora.
- [x] `CHANGELOG.md` — wpis w changelogu.

## Ograniczenia techniczne
- Laravel 13, Breeze (logowanie, rejestracja, 2FA kodem z maila), Socialite (Google), portal wędkarza
  na Tailwindzie (ADR-020) i interakcjach z ADR-022.
- `provider`, `provider_id` i `has_password` poza `$fillable` (`autoryzacja.md` §6). Adres przy
  rejestracji z linku nie pochodzi z żądania.
- Stan zmienia wyłącznie POST z CSRF. GET pokazuje stronę (skanery linków).
- Limit prób na IP (`ReservationLinkController::ATTEMPTS_PER_MINUTE`) obejmuje też nowe akcje.


## Rozstrzygnięcia
- **R1 — stan relacji po „Nie teraz”:** relacja zostaje `pending` z ważnym tokenem do `link_expires_at`,
  bez nowego stanu w enumie. Link z maila pozwala połączyć konto później, a panel pokazuje „dane od
  łowiska”. Po wygaśnięciu linku wędkarz przekaże dane łowisku z własnego panelu (TODO-7, poza
  zadaniem). Decyzja autora 2026-10-11.
- **R2 — powrót po logowaniu:** znacznik łączenia w sesji (hash tokenu), sprawdzany przez wszystkie
  ścieżki logowania: hasło, Google i weryfikację kodu 2FA. Nie przez `url.intended`, którego dziś nie
  respektują ani Google, ani ekran 2FA. Jedno miejsce decyzji, bez zmiany przekierowań poza tym
  przepływem.
- **R3 — zgody przy zwykłej rejestracji:** w zakresie zadania. `/register` i nowe konto przez Google
  wymagają zgód Fisherya, żeby od pierwszego dnia każde konto miało zapis akceptacji. Decyzja autora.
- Pytania o kształt zapisu zgód, miejsce dokumentów Fisherya, znaczenie `required_at_registration`
  i akceptację nowych wersji przeniesiono do **ADR-028** (decyzja autora wymagana przed implementacją).

## Powiązane ADR-y
- [ADR-028](../../adr/ADR-028-zapis-zgod-i-dokumenty-platformy.md) — rejestr zgód i akceptacji,
  dokumenty portalu, rynek z kraju łowiska (**sekcja Decyzja do wypełnienia przez autora**).
- [ADR-024](../../adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md) — Aktualizacja
  2026-10-11 (decyzja autora, podstawa zadania).
- [ADR-017](../../adr/ADR-017-dokumenty-i-wersje-dokumentow.md) — dokumenty i flagi wymagalności.
- [ADR-019](../../adr/ADR-019-dowiazanie-dostawcy-logowania-do-istniejacego-konta.md) — dowiązanie Google
  do konta.
