# ADR-024 — Wędkarz jako tożsamość globalna i relacja z łowiskiem

- **Status:** accepted
- **Data:** 2026-10-08
- **Zadanie:** [039 — Rdzeń rezerwacji](../tasks/implemented/039-rdzen-rezerwacji.md), także [041](../tasks/implemented/041-rezerwacja-przez-operatora.md) i [043](../tasks/implemented/043-wedkarz-powiazanie-i-potwierdzenie.md) — ADR poprzedził je, bo model danych wędkarza wiąże rezerwacje, kalendarz operacyjny i zakup z etapu 4

## Kontekst

Etap 3 daje operatorowi rezerwowanie ręczne dla wędkarzy, najczęściej po telefonie. Wędkarz jest
klientem Fisherya (platforma administruje jego danymi) i klientem każdego łowiska, w którym kupuje
(łowisko staje się administratorem danych, które dostało). Powstaje pytanie, jak w systemie
wielu tenantów utrzymywać rekord wspólny między tenantami:

- nie wolno dublować wędkarza (`users.email` jest unikalny), a operator nie może widzieć danych
  wędkarzy, z którymi jego łowisko nie ma relacji;
- zmiana adresu lub telefonu ma być widoczna we wszystkich łowiskach wędkarza, a profil wędkarza
  ma pokazywać jeden zestaw danych;
- operator przyjmuje rezerwację telefoniczną **przed** jakimkolwiek potwierdzeniem przez wędkarza,
  często znając tylko telefon;
- ktoś może podać adres e-mail osoby trzeciej (literówka albo celowo).

Dlaczego to ADR: **zasięg** — model wiąże rezerwacje, kalendarz operacyjny, zakup (etap 4), RODO
i wygląd profilu; **koszt odwrócenia** — zmiana oznacza migrację rezerwacji i danych osobowych;
**uzasadnienie** — odpowiedź na „dlaczego rezerwacja nie ma `user_id`" nie wynika z kodu.

## Alternatywy

### Opcja A — `users` tylko do uwierzytelniania, dane osobowe w bazie każdego łowiska
**Zalety:**
- Pełna izolacja łowisk, prosta autoryzacja.

**Wady:**
- Rozjazd danych: zmiana telefonu w jednym łowisku nie jest globalna.
- Profil wędkarza nie ma jednego, prawdziwego zestawu danych.

### Opcja B — jeden globalny rekord wędkarza widoczny dla każdego operatora
**Zalety:**
- Brak duplikatów i rozjazdów, proste wyszukiwanie.

**Wady:**
- Operator widzi dane osób bez relacji z jego łowiskiem; wyszukiwarka ujawnia przynależność do portalu.

### Opcja C — tożsamość globalna + rekord relacji łowisko ↔ wędkarz *(wybrana)*
**Zalety:**
- Jedno źródło prawdy o danych bieżących (`users`), dostęp łowiska wynika z relacji, nie z kopii.
- Rezerwacja może powstać bez konta wędkarza (relacja niepowiązana), co odpowiada rezerwacji telefonicznej.
- Ten sam rekord relacji obsłuży etap 4 (zakup tworzy relację z wypełnionym `user_id`).

**Wady:**
- Warstwa pośrednia: rezerwacja nie wskazuje wprost na `users`.
- Złożoność nadpisania telefonu per łowisko i zgód przy łączeniu.

## Rekomendacja

Opcja C.

## Decyzja

Decyzja: C — uzgodniona z autorem 08.10.2026. Ustalenia wiążące:

1. **Tabela `fishery_anglers`** (relacja łowisko ↔ wędkarz): `fishery_id`, `user_id` (nullable), status
   (`unlinked`, `pending`, `linked`, `revoked`), dane podane przez operatora (`given_name`,
   `given_email`, `given_phone`), `phone_override`, notatki łowiska, token powiązania (hash) i terminy.
   `unique(fishery_id, user_id)` dla niepustych oraz `unique(fishery_id, znormalizowany given_email)`.
   Rezerwacja wskazuje na `fishery_anglers`, nigdy bezpośrednio na `users`; `reservations.fishery_id`
   musi zgadzać się z `fishery_anglers.fishery_id`.
2. **Widoczność:** łowisko widzi wędkarza wyłącznie przez swoje rekordy relacji; podpowiedzi
   wyłącznie z własnych relacji, bez globalnej wyszukiwarki. Dane efektywne: `unlinked` — `given_*`;
   `linked` — `users.*` z nadpisaniem telefonu przez `phone_override`; `revoked` — tylko zamrożony
   `contact_snapshot` rezerwacji.
3. **Powiązanie:** operator zawsze widzi ten sam komunikat („wysłano prośbę o potwierdzenie") —
   system nie ujawnia, czy adres istnieje w portalu. Konto powstaje dopiero po kliknięciu w mailu
   (e-mail zweryfikowany, bez hasła); jeśli konto istnieje, następuje tylko dowiązanie. Zgoda na
   powiązanie jest niezależna od ważności rezerwacji. Operator nigdy nie zapisuje do `users`;
   zmiany danych zgłasza jako `contact_change_proposals` do zatwierdzenia przez wędkarza.
4. **Tożsamością jest zweryfikowany e-mail.** Telefon nie służy do automatycznego łączenia kont;
   wolno go użyć jako podpowiedzi w obrębie relacji łowiska. Rezerwacja z samym telefonem jest
   obsługiwana i pozostaje relacją niepowiązaną.
5. **Rozbieżny telefon przy akceptacji:** wędkarz wybiera: (a) numer z profilu, (b) nowy numer i
   zapis w profilu, (c) nowy numer tylko dla tego łowiska (`phone_override`). Nadpisanie dotyczy
   **wyłącznie telefonu**; e-mail jest tożsamością i nie ma nadpisania. Profil pokazuje wyjątki
   per łowisko oraz akcję „ujednolić".
6. **Dane historyczne** (kontakt, cena, polityka zwrotu, dokumenty) są zamrażane w rezerwacji
   (`contact_snapshot`, `terms_snapshot`, zasada Z1) i nie zmieniają się po zmianie profilu.
7. **Potwierdzenie rezerwacji ręcznej** — ustawienie łowiska „wymagaj potwierdzenia rezerwacji
   ręcznej" (od etapu 3) z oknem w godzinach; niepotwierdzona rezerwacja po upływie okna wygasa
   (`reservations.confirm_by`, nullable; null = bez wygasania). Domyślnie wyłączone. Operator może
   oznaczyć rezerwację jako potwierdzoną (telefonicznie, zapłacona na miejscu). Ten punkt **zmienia
   dotychczasowe założenie etapu 3**, że rezerwacja operatora jest zawsze trwała i ważna bez
   potwierdzenia; w fazie pośredniej (link do płatności, po etapie 4) płatność jest ostatecznym
   potwierdzeniem.
8. **Mail po rezerwacji** niesie dwa działania: „Potwierdź i połącz z kontem" oraz „Zrezygnuj / to nie ja"
   (token zarządzania rezerwacji, `manage_token_hash`, działa bez konta). Wymagane zabezpieczenia:
   limit oczekujących próśb na jeden adres w całym portalu i limit wysyłek na łowisko. Kanał SMS —
   poza zakresem.
9. **Do prawnika (TODO-3):** kwalifikacja ról (odrębni administratorzy czy współadministrowanie),
   podstawa przechowywania danych z `contact_snapshot` po `revoked`, treść maila z prośbą o zgodę.

## Aktualizacja (2026-10-11)

Uzupełnienie pkt 7 (decyzja autora przy zadaniu 047, uwaga przeglądu pkt 4): **`confirm_by = min(teraz + okno,
początek pobytu)`**. Niepotwierdzona rezerwacja wygasa najpóźniej w chwili rozpoczęcia pobytu — rezerwacja
założona na dziś z oknem 48 h nie może wygasnąć w trakcie trwającego pobytu, a wędkarz stojący już nad wodą nie
traci stanowiska przez upływ terminu. Wygaszanie dodatkowo pomija pobyty rozpoczęte z terminem sprzed tej reguły.
Uzasadnienie pkt 7 (wygasanie zwalnia blokowany termin) zostaje: po rozpoczęciu pobytu termin i tak jest
„zużyty", więc dalsze trzymanie go w stanie „do potwierdzenia" nie ma sensu.

Uzupełnienie pkt 7 (decyzja autora przy zadaniu 047): **płatność potwierdza rezerwację już dziś, nie dopiero
w fazie pośredniej.** Oznaczenie oczekującej rezerwacji jako opłaconej — ręczne i przyszłe online — potwierdza ją
(źródło `payment`, bez terminu wygaśnięcia); opłacona rezerwacja jest zawsze potwierdzona.

## Aktualizacja (2026-10-11) — rozdzielenie potwierdzenia rezerwacji od połączenia z kontem

Zmiana pkt 3 i pkt 8 (decyzja autora po testach zadania 043, realizacja: zadanie
[049](../tasks/049-potwierdzenie-rezerwacji-a-konto-wedkarza.md)). Powód: link z maila działał jak klucz do
cudzego konta. Przekazany albo podejrzany mail pozwalał dowiązać istniejące konto do łowiska i zapisać
w jego profilu nowy telefon bez logowania. Konto zakładane po cichu, bez hasła, było dla wędkarza
niewidoczne.

1. **Potwierdzenie rezerwacji nie wymaga konta.** Kliknięcie w link dowodzi dostępu do skrzynki, a tego
   potrzebuje łowisko. Rezerwacja przechodzi w „potwierdzoną” bez logowania i bez zakładania konta.
2. **Połączenie z kontem to osobny, dobrowolny krok** na tej samej stronie, z zachętą („zaloguj się, żeby
   widzieć historię rezerwacji” / „załóż konto, żeby mieć historię rezerwacji”):
   - **konto o tym adresie istnieje:** dowiązanie i zapis w profilu (np. telefon z pkt 5) dopiero po
     zalogowaniu na **to** konto, hasłem albo przez Google;
   - **konta nie ma:** wędkarz zakłada je hasłem albo przez Google. Adres jest stały i równy adresowi
     z prośby. Konto z hasłem nie wymaga osobnej weryfikacji adresu, bo zrobiło to kliknięcie w link;
   - **system nie zakłada już kont bez udziału wędkarza** (bez hasła, bez dostawcy logowania). Zdanie
     z pkt 3 „konto powstaje dopiero po kliknięciu w mailu (e-mail zweryfikowany, bez hasła)” przestaje
     obowiązywać;
   - nigdy nie łączymy relacji z kontem o innym adresie niż adres z prośby.
3. **Zgody przy łączeniu:** przy zakładaniu konta wędkarz zaznacza wymagane zgody Fisherya i wymagane
   zgody łowiska; przy dowiązaniu istniejącego konta tylko zgody łowiska.
4. **Łowisko widzi dwie odrębne informacje:** status rezerwacji („potwierdzona”) i stan wędkarza (dane od
   łowiska / **potwierdzone konto**). „Potwierdzona” nie oznacza już „powiązana z kontem”.
5. **Bez zmian:** komunikat dla operatora (nie ujawnia istnienia konta), tożsamość = zweryfikowany e-mail
   (pkt 4), działania „Zrezygnuj” i „To nie ja” bez logowania (pkt 8). W pkt 8 przycisk w mailu nie
   obiecuje już połączenia z kontem.
