# 013 — Testy mutacyjne `Helper` i rozbicie klasy na dziedziny

## Opis problemu

`app/Helpers/Helper.php` urósł do ~620 linii i ponad trzydziestu metod statycznych, a od zadania
012 trzyma **niezmiennik bezpieczeństwa** panelu właściciela: `assertFisheryAccessOrAbort()`,
`forceVerifiedFishery()`, `scopeToOwnedFisheries()`, `findFishery()`. To dokładnie ta kategoria
kodu, dla której testy mutacyjne mają sens — ocalały mutant w bramce dostępu to realna luka,
a nie kosmetyka.

Problem: **mutacji tej klasy nie da się dziś uruchomić do końca.** Zmierzone w sesji zadania 012:

- Pełny pakiet testów jako baseline dla silnika mutacji: **~2,5 min**.
- Dwie próby przebiegu (`vendor/bin/pest --mutate --covered-only --class="App\Helpers\Helper"`,
  raz bez filtra, raz zawężony do nowego `HelperFisheryAccessTest`) **przekroczyły 10 minut
  i zostały przerwane bez wyniku**.
- Dla porównania `App\Rules\IbanValidation` — klasa pokryta szybkim testem `tests/Unit` —
  przeszła w **~8 min**, 131 mutantów, **MSI 100%**, zero ocalałych.

Przyczyna jest strukturalna, nie sprzętowa: `Helper` jest pokryty testami `Feature`, które
bootują panele Filamenta, więc koszt jednego mutanta to kilkadziesiąt sekund, a mutantów są
setki. Zadanie 012 dołożyło `tests/Feature/HelperFisheryAccessTest.php` (17 przypadków, ~27 s)
wołający metody bramkujące bezpośrednio — to skróciło cykl, ale nie na tyle, żeby zmieścić
całą klasę w rozsądnym oknie.

## Wymagania

1. **Rozbić `Helper` wzdłuż granicy dziedzinowej.** Kandydaci widoczni dziś:
   - dostęp i widoczność łowisk (bramka, zawężenie zapytań, wymuszenie `fishery_id`),
   - nawigacja (okruszki, adresy zakładek huba, adresy sekcji),
   - formularze współdzielone (pola łowiska, pole ceny, opcje edytora),
   - role i uprawnienia (`addOwnerRole`, `isOwnerPanel`),
   - integracja z GUS (`fetchDataFromCSO` i okolice — patrz `docs/conventions/integracje.md`).
2. **Klasa z bramkami dostępu ma mieć pokrycie testami niewymagającymi bootowania paneli** —
   `HelperFisheryAccessTest` jest punktem wyjścia, ale docelowo powinien trafić do `tests/Unit`
   albo zostać ograniczony do minimum potrzebnego zaplecza.
3. **Uruchomić mutacje na wydzielonej klasie dostępu i doprowadzić do zera ocalałych mutantów.**
   Wynik (MSI + lista mutantów, jeśli któryś przeżył ze świadomej decyzji) zapisać w tym zadaniu.
4. Zachować wsteczną zgodność wywołań albo przenieść je wszystkie — `Helper::` jest wołany
   z kilkudziesięciu miejsc, więc rozbicie bez migracji wywołań nie ma sensu.

## Kryteria akceptacji

- [ ] `Helper` nie przekracza ~250 linii albo znika na rzecz klas dziedzinowych.
- [ ] Klasa niosąca bramki dostępu ma test wołający ją bezpośrednio, bez renderowania stron.
- [ ] `vendor/bin/pest --mutate --covered-only --class="<klasa dostępu>"` **kończy się** i raportuje
      MSI; ocalałe mutanty albo zabite, albo opisane tu z uzasadnieniem.
- [ ] Pełny pakiet testów zielony (patrz `## Zakres testów`).
- [ ] `docs/conventions/autoryzacja.md` §4 i `panel-wlasciciela.md` §2 wskazują nowe nazwy klas.

## Zakres testów

**Tier:** T3 — pełny pakiet.

**Uruchamiamy:** `docker compose exec app php artisan test`

**Uzasadnienie:** zadanie rusza `Helper`, czyli klasę wołaną z obu paneli, z polityk i ze stron
zasobów; dotyka też pośrednio ról i uprawnień Shielda (`addOwnerRole`). To trafia w listę
wyzwalaczy T3 z `CLAUDE.md` — tier nie jest tu przedmiotem wyboru.

## Zakres wyłączeń

- **Nie** obejmuje mutacji pozostałych klas (zasoby i strony Filamenta to szkielet deklaratywny
  o niskiej wartości mutacyjnej — patrz kryterium w komendzie `/review-implementation`).
- **Nie** obejmuje dodania PCOV/Xdebuga — PCOV **jest** w obrazie deweloperskim (zweryfikowane
  w sesji 012; notatka w komendzie `/review-implementation` mówiąca, że go nie ma, jest
  nieaktualna i wymaga poprawki przy okazji).
- Nie zmienia zachowania aplikacji — to refaktor plus testy.

## Zmiany dokumentacji

- `docs/conventions/autoryzacja.md` §4 — nazwy klas po rozbiciu.
- `docs/conventions/panel-wlasciciela.md` §2 — jw.
- `docs/conventions/integracje.md` — nagłówek wskazuje dziś `app/Helpers/**`; po rozbiciu
  doprecyzować, która klasa jest integracją, a która nawigacją.
- `.claude/commands/review-implementation.md` — sprostować notatkę o braku sterownika pokrycia.

## Ograniczenia techniczne

- Pest 5 (wbudowany silnik mutacji; Infection **nie** wchodzi w grę — nie mapuje mutantów na
  testy Pesta, bo Pest opakowuje klasy testowe we własną przestrzeń nazw).
- Testy biegną na MySQL-u w schemacie `lowiska_test`; obowiązuje pięć warstw izolacji z `CLAUDE.md`.

## Rozstrzygnięcia

<!-- uzupełnia /review-task -->

## Powiązane ADR-y

<!-- uzupełnia /review-task -->
