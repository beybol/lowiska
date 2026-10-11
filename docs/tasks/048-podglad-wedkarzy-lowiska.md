# 048 — Podgląd wędkarzy łowiska

## Opis problemu

Po zadaniach 039–047 łowisko ma relacje z wędkarzami (`fishery_anglers`, ADR-024): powstają przy
rezerwacji ręcznej, wędkarz może je powiązać ze swoim kontem (zadanie 043). Operator i administrator nie
mają jednak żadnego miejsca, w którym zobaczą listę wędkarzy łowiska. Nie widać, kto potwierdził konto,
a kto istnieje tylko z danymi wpisanymi przez łowisko. Nie da się też obejrzeć wszystkich rezerwacji
jednego wędkarza. Dziś trzeba przeszukiwać listę rezerwacji po nazwisku albo adresie.

Brakuje prostego podglądu: lista wędkarzy łowiska z filtrami, ich podstawowe dane i status powiązania,
a przy każdym wędkarzu lista jego rezerwacji z przejściem do istniejących szczegółów.

## Wymagania

- **Nowa podstrona rekordu łowiska „Wędkarze”** w sub-nawigacji, zaraz po „Rezerwacjach”. Wzorem jest
  `FisheryResource\Pages\ManageReservations` (`ManageRelatedRecords` na relacji `Fishery::anglers()`).
  Działa w obu panelach (`/admin` i `/owner`), bo oba rejestrują `FisheryResource`.
- **Tabela wędkarzy łowiska.** Źródłem są wyłącznie rekordy `fishery_anglers` tego łowiska (ADR-024
  pkt 2), nigdy `users`. Kolumny:
  - imię i nazwisko, e-mail i telefon, czyli **dane efektywne** z `FisheryAngler::effectiveContact()`.
    Przy `revoked` są puste i strona mówi wprost „dostęp cofnięty”;
  - **status** (`AnglerLinkStatus`) jako plakietka. Musi być czytelne, czy dane pochodzą od łowiska
    (`unlinked`, `pending`), czy z potwierdzonego konta (`linked`);
  - liczba rezerwacji i data najbliższego nadchodzącego pobytu.
- **Filtry:**
  - wyszukiwanie tekstowe po imieniu i nazwisku, e-mailu i telefonie. Przy `linked` szuka po danych
    konta, przy `unlinked`/`pending` po `given_*`. Nie wolno przez wyszukiwarkę ujawnić danych spoza
    relacji łowiska;
  - status powiązania;
  - „Ma nadchodzącą rezerwację”: aktywna rezerwacja jeszcze nierozpoczęta;
  - „Do kontaktu”: wędkarz ma rezerwację w stanie „Do kontaktu” (`Reservation::toContact()`).
- **Podgląd wędkarza** (akcja wiersza: modal albo slide-over) pokazuje:
  - dane efektywne, status i datę powiązania (`linked_at`), notatki łowiska tylko do odczytu;
  - **listę rezerwacji tego wędkarza w tym łowisku.** Relacja jest unikalna po znormalizowanym adresie
    w łowisku, więc to są rezerwacje danego adresu e-mail. Kolumny: numer, termin, stanowisko, status,
    płatność. Każda pozycja prowadzi do istniejących szczegółów rezerwacji (`ReservationResource`).
- **Tylko odczyt.** Strona niczego nie zapisuje.
- **Autoryzacja przez rodzica** (`autoryzacja.md` §5). `FisheryAngler` nie dostaje zasobu ani polityki.
  Dostęp do strony wynika z dostępu do łowiska, tak samo jak przy „Rezerwacjach”. Podgląd rezerwacji
  i link do niej respektują `ReservationPolicy`.
- **Bez N+1:** eager-load `user`, liczba i najbliższa rezerwacja agregatem, nie zapytaniem na wiersz.
- Teksty UI po angielsku, tłumaczenia w `lang/pl.json`.

## Kryteria akceptacji

- [ ] W menu łowiska jest pozycja „Wędkarze” w obu panelach; operator widzi wyłącznie wędkarzy swojego
      łowiska, a wejście na stronę cudzego łowiska kończy się odmową.
- [ ] Status `linked` pokazuje dane konta (z `phone_override`), `unlinked`/`pending` dane od łowiska,
      a `revoked` dane puste z informacją o cofniętym dostępie.
- [ ] Każdy z czterech filtrów zawęża listę zgodnie z opisem. Wyszukiwarka nie znajduje wędkarza
      z innego łowiska.
- [ ] Podgląd wędkarza pokazuje jego rezerwacje tylko z tego łowiska, każda z działającym przejściem
      do szczegółów.
- [ ] Liczba zapytań tabeli nie rośnie z liczbą wędkarzy (test liczby zapytań).
- [ ] Testy z zakresu T2 przechodzą, `pint` i PHPStan są czyste.
- [ ] Pełny pakiet testów jest **odroczony** na koniec sesji (`/review-implementation`, Krok 1).

## Zakres testów

- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="FisheryAnglers|AnglerLinking|ManageReservations|ReservationResource|AdminPanelTest|OwnerPanelTest"`
- **Uzasadnienie:** nowa strona w istniejącym zasobie, bez nowej polityki, providera i migracji, więc
  nie ma wyzwalaczy T3. T2 obejmuje nowy test strony (`FisheryAnglersPageTest`) oraz to, z czego strona
  korzysta i co może naruszyć: granice paneli, relację wędkarza i tabelę rezerwacji.

## Zakres wyłączeń

- Edycja danych wędkarza, notatek, nadpisania telefonu, ponowna wysyłka prośby o powiązanie i cofanie
  dostępu. Strona jest tylko do odczytu, zmiany przyjdą osobnym zadaniem.
- Widok wędkarza ponad łowiskami, także dla administratora. Administrator widzi wędkarza w kontekście
  jednego łowiska, jak operator.
- Ręczne zakładanie wędkarza bez rezerwacji.
- Eksport listy i RODO (prośby o dane, usunięcie): TODO-3 z ADR-024.
- Portal wędkarza: profil i lista rezerwacji po stronie wędkarza.

## Zmiany dokumentacji

- [ ] `docs/conventions/rezerwacje.md`: nowa sekcja o stronie „Wędkarze” (źródło danych = relacje
      łowiska, dane efektywne, tylko odczyt, autoryzacja przez rodzica).
- [ ] `docs/conventions/panel-wlasciciela.md`: pozycja sub-nawigacji łowiska, jeśli plik wylicza
      kolejność pozycji.
- [ ] `MANUAL.md`: opis strony „Wędkarze” dla operatora.
- [ ] `CHANGELOG.md`: wpis w changelogu.

## Ograniczenia techniczne

- Laravel 13, Filament 5 (`ManageRelatedRecords`), Pest; testy w kontenerze (`lowiska_test`).
- ADR-024 pkt 2 wiąże wprost: łowisko widzi wędkarza tylko przez swoje relacje, bez globalnej
  wyszukiwarki.
- Dane efektywne liczy wyłącznie `FisheryAngler::effectiveContact()`. Wyszukiwanie i sortowanie po
  nich w SQL muszą odwzorować tę samą regułę, a nie drugą wersję logiki z innym wynikiem.
- Rekord łowiska na stronie bierze się z sub-nawigacji, nie z trasy (`panel-wlasciciela.md` §2).

## Rozstrzygnięcia

- Umiejscowienie: podstrona rekordu łowiska, a nie osobny zasób w menu głównym (decyzja autora,
  11.10.2026). Daje autoryzację przez rodzica bez nowej polityki i uprawnień Shielda.
- Strona tylko do odczytu (decyzja autora, 11.10.2026).
- Rezerwacje przy wędkarzu wyłącznie z tego łowiska, także dla administratora (decyzja autora,
  11.10.2026), zgodnie z ADR-024 pkt 2.
- Filtry: wyszukiwanie tekstowe, status powiązania, „Ma nadchodzącą rezerwację”, „Do kontaktu”
  (decyzja autora, 11.10.2026).

## Powiązane ADR-y

- [ADR-024](../adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md): wędkarz jako tożsamość globalna
  i relacja z łowiskiem. Wiąże źródło danych i widoczność.
