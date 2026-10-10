# 033 — Kalendarz portalu: płaska siatka stanowisk

> **Etap 2, zadanie 2** ([roadmapa](../project/ROADMAPA.md)). Założone 30.09.2026.
> Specyfikacja: [portal-v3](../project/mockups/portal-v3/README.md) §3; makiety „Łowisko — Mapa
> i terminy", „Łopienno — dopłata", „Mapa i terminy z zajętością" (wygląd etapu 3 — informacyjnie),
> „Wersja mobilna". Wymaga 032.

## Opis problemu

Kalendarz jest główną wizualizacją zasad sprzedaży łowiska i powodem, dla którego wędkarz otwiera
stronę łowiska. Dane i werdykty już są (warstwa oferty, `SaleCalendar` z 019) — brakuje widoku
portalu: płaskiej siatki stanowisk z przełącznikami grup i cech, wersji mobilnej i rozbicia ceny.

## Wymagania

- **Siatka tydzień × stanowiska** (§3.1): wiersz = stanowisko **w sprzedaży** (wycofane są ukryte —
  Rozstrzygnięcie 3) w **naturalnej kolejności etykiet** (jak lista w „Szczegółach", 032), jeden
  tydzień pon–nd z nawigacją ‹ ›. W etapie 2 wszystkie wiersze mają te same ceny — świadomie.
- **Wiersz „Pakiety"** nad stanowiskami — pas na każdy zakres dni `bundleFirstDay`–`bundleLastDay`
  niesiony przez komórki „zacznij wcześniej" **widocznych wierszy**, złożony do jednego pasa na zakres
  (Rozstrzygnięcie 4). Portal tylko czyta werdykty. Pakiet przechodzący przez granicę tygodnia
  pokazuje się w obu tygodniach, z datą początku.
- **Komórka** — odpowiedź `StayOffer::shortestOffer()` przez `SaleCalendar` dla pobytu od tej doby
  i wybranej liczby łowiących; stany: sprzedawalne („cena · długość", „z dopłatą"), zacznij
  wcześniej, niesprzedawalne czasowo (powód ze słownika `SaleUnavailabilityReason`), trwale (komunikat
  na wiersz). **Portal niczego nie liczy po swojemu** (Z3, ADR-015).
- **Przełącznik łowiących** (1 / 2 / … do największej pojemności); stanowisko o mniejszej pojemności
  → komunikat na cały wiersz.
- **Przełączniki grup** (jedna naraz albo „Wszystkie"; brak przy łowisku bez grup) i **cech
  filtrowalnych** (I); liczby w bieżącym wyborze; stanowisko w kilku grupach widoczne w każdej.
- **Nagłówek wiersza** ze znacznikami (ograniczenie, usługa obowiązkowa, blokada) i **notki nad
  siatką** dla ograniczeń/usług obejmujących wiele stanowisk.
- **Wyciąg zasad** nad siatką (§3.2) — ta sama metoda daje **linijkę zasad na karcie strony głównej**.
- **Rozbicie ceny** po wyborze komórki (§3.4): stawka × doby, osoba towarzysząca (gdy płatna),
  dopłaty pod nazwami łowiska, obniżka przedsprzedażowa, suma; usługi obowiązkowe **pod sumą, osobno**.
- „Wolne terminy potwierdzi łowisko przez telefon" (etap 2 — bez zajętości).
- **Stan w adresie** (tydzień, łowiący, grupa, cechy) — linkowalny; canonical bez parametrów.
- **Wersja mobilna** (§3.5): pasek dób z klamrą pakietów, przełączniki przewijane poziomo, płaska
  lista stanowisk dla wybranej doby, karta doby z rozbiciem; skrócony wyciąg zasad z „Zasady ›".
- Siatka na desktopie z przyklejonym nagłówkiem dni i kolumną stanowisk.
- **Mechanizm przełączania** — wg [ADR-022](../adr/ADR-022-mechanizm-interakcji-portalu.md)
  (rekomendacja: GET z parametrami, renderowanie po stronie serwera, stopniowe ulepszenie w `portal.js`).
- **Parametry adresu zależne od języka** (Rozstrzygnięcie 1), jeden dom obok `PortalRoutes`:
  PL `tydzien`, `lowiacych`, `grupa`, `cecha`, `doba`, `st`; EN `week`, `anglers`, `group`,
  `feature`, `night`, `pos`. Wartości: tydzień = data poniedziałku (`YYYY-MM-DD`), grupa i cecha = slug
  z nazwy (`Str::slug`), cecha typu „wybór" = `slug-cechy:slug-opcji`. Nieznane albo błędne wartości
  są ignorowane (spadają do domyślnych); przełącznik języka niesie ten sam stan pod nazwami drugiego
  języka; canonical zawsze bez parametrów.
- **Rozbicie ceny na desktopie — podpowiedź komórki** (Rozstrzygnięcie 2): dymek z rozbiciem
  i usługami obowiązkowymi, widoczny na `:hover` i `:focus-within` (komórka jest fokusowalna), więc
  na tablecie otwiera się dotknięciem. Na telefonie — karta wybranej doby i stanowiska (`doba` + `st`).
- **`SaleCalendar` dostaje zawężenie do podanych stanowisk** (wiersze po filtrach grup i cech),
  żeby portal nie liczył werdyktów dla wierszy, których nie pokaże; kalendarz panelu (019) bez zmian
  zachowania.

## Kryteria akceptacji

- [ ] Komórki zgodne z kalendarzem podglądowym panelu (019) dla tych samych danych — test
      porównujący werdykty dla Klasztornego (tydzień z Bożym Ciałem) i Łopienna (dopłata czw–nd).
- [ ] Przełączniki: grupa, cecha, łowiący, tydzień — testy funkcjonalne wyniku (wiersze, komórki).
- [ ] Stanowisko w dwóch grupach widoczne po wybraniu każdej z nich.
- [ ] Parametry: PL i EN nazwy, błędne wartości ignorowane, przełącznik języka przenosi stan,
      canonical bez parametrów, linki przełączników z `rel="nofollow"`.
- [ ] Wiersz „Pakiety" zgodny z komórkami „zacznij wcześniej" (Boże Ciało w Klasztornym, weekend).
- [ ] Stanowisko wycofane nie ma wiersza; stanowisko o mniejszej pojemności — komunikat na wiersz.
- [ ] Liczba zapytań siatki portalu nie rośnie z liczbą widocznych wierszy (test jak w 019).
- [ ] Linijka zasad na karcie strony głównej z tej samej metody co wyciąg.
- [ ] **Ręczna weryfikacja** na telefonie (390 px, iOS Safari i Android Chrome), tablecie i desktopie
      (26 wierszy Klasztornego, przyklejony nagłówek), w obu językach; lista w podsumowaniu.
- [ ] Testy w zadeklarowanym zakresie zielone (T2); **pełny pakiet odroczony** na `/review-implementation`.

## Zakres testów

- **Tier:** T2 — zależności
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="PortalCalendar|SaleCalendar|StayOffer|FisheryPage|Portal"`
- **Uzasadnienie:** nowy widok portalu na istniejącym modelu `SaleCalendar`, ale zadanie **zmienia
  kontrakt `SaleCalendar`** (zawężenie do stanowisk — kalendarz panelu 019, `SaleCalendarPageTest`)
  i dokłada **linijkę zasad na karcie** strony głównej (`PortalFisheries`, testy `Portal*`) oraz
  kalendarz na stronie łowiska (`FisheryPageTest`). Bez wyzwalaczy T3. Pełny pakiet —
  `/review-implementation` (decyzja autora, 30.09.2026).

## Zakres wyłączeń

- Zajętość (etap 3) — makieta etapu 3 tylko jako sprawdzian, że układ jej nie wymaga zmieniać.
- Usługi w sumie ceny (etap 4, decyzja z 020).
- Wybór terminu do koszyka (etap 4).
- Klikalna mapa.

## Zmiany dokumentacji

- [ ] `docs/conventions/dostepnosc.md`, `cennik.md` — kalendarz portalu jako trzeci widok tej samej prawdy.
- [ ] `docs/conventions/strona-publiczna.md` — przełączniki zamiast grupowania, stan w adresie
      (nazwy parametrów zależne od języka, jeden dom), mechanizm z ADR-022, wiersz „Pakiety" z werdyktów.
- [ ] `CHANGELOG.md` — wpis (kalendarz na stronie łowiska).

## Ograniczenia techniczne

- Konwencje: `dostepnosc.md`, `cennik.md` (§5 warstwa oferty), `strona-publiczna.md`.
- Wspólne dane z kalendarzem podglądowym, osobny widok (§3.6) — portal nie zależy od Filamenta.
- Wydajność: 26 stanowisk × 7 dób × pytania warstwy oferty — **sprawdzone przy przeglądzie:**
  `SaleCalendar::grid()` tworzy jedną instancję `StayOffer` na stanowisko, cennik wczytuje raz na
  render i podaje wszystkim stanowiskom, usługi przez jedną instancję `PositionServices` (pomiar
  w zadaniu 019). Portal nie buduje własnej pętli po warstwie oferty. Test liczby zapytań dla siatki
  portalu (niezależnej od liczby wierszy) wchodzi do kryteriów.

## Rozstrzygnięcia

1. **Parametry adresu zależne od języka** (PL `tydzien`/`lowiacych`/`grupa`/`cecha`/`doba`/`st`,
   EN `week`/`anglers`/`group`/`feature`/`night`/`pos`), wartości grup i cech jako slugi z nazwy —
   wędkarze udostępniają linki, a specyfikacja używa `grupa=`; mapa nazw ma jeden dom obok `PortalRoutes`.
2. **Rozbicie na desktopie — podpowiedź komórki** (decyzja autora, zgodnie z §3.4), otwierana także
   fokusem, żeby działała na tablecie dotykiem; na telefonie karta doby.
3. **Stanowiska wycofane są w portalu ukryte** — siatka, lista w „Szczegółach" i liczniki mówią
   o stanowiskach w sprzedaży; „trwale niesprzedawalne" to wiersz stanowiska o zbyt małej pojemności.
4. **Wiersz „Pakiety" z werdyktów komórek** — zakresy `bundleFirstDay`–`bundleLastDay` z komórek
   „zacznij wcześniej" widocznych wierszy; bez nowej metody w warstwie oferty (ADR-013/015 bez zmian).

## Powiązane ADR-y

- [ADR-022 — Mechanizm interakcji portalu](../adr/ADR-022-mechanizm-interakcji-portalu.md) — ⚠️ Decyzja do wypełnienia.
- [ADR-015](../adr/ADR-015-warstwa-oferty-pobytu.md) — warstwa oferty jako jedyne wejście portalu (bez zmian).
