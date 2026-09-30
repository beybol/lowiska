# 032 — Strona łowiska: zakładki „Mapa i terminy" i „Szczegóły"

> **Etap 2, zadanie 1** ([roadmapa](../project/ROADMAPA.md)). Założone 30.09.2026.
> Specyfikacja: [portal-v3](../project/mockups/portal-v3/README.md) §1, §4, §6; makiety
> „Łowisko — Mapa i terminy", „Łowisko — Szczegóły", „Wersja mobilna". Wymaga 031.

## Opis problemu

Pod adresem kanonicznym łowiska (031) stoi zaślepka. Wędkarz klika łowisko, żeby zobaczyć mapę
stanowisk i terminy, a dopiero potem opis — strona ma to odwzorować (027 pkt 9.4). Brakuje też
„ceny od", jedynej liczby porównywalnej między łowiskami, której potrzebują strona łowiska
i karta na stronie głównej.

## Wymagania

- **„Cena od"** — jedna metoda w warstwie cennika, obok `PriceRuleResolver` (§4, Rozstrzygnięcie 1):
  najniższa kwota `amount` spośród **stawek** (`PriceRule` rodzaju stawka), **niezawieszonych**
  i nieusuniętych, obowiązujących w **którejś dobie od dziś do końca trwającego albo najbliższego
  okresu sprzedaży** (strefa łowiska; Rozstrzygnięcie 2). Bez dopłat, obniżki przedsprzedażowej,
  kwoty za osobę towarzyszącą i usług. Brak takiej stawki albo okresu → „cennik w przygotowaniu".
  Portal i lista łowisk jej nie liczą. Dochodzi na kartę strony głównej (031, `PortalFisheries::card()`)
  — bez N+1: stawki i okresy wczytane raz dla całej listy albo zapytaniem na łowisko przy
  liczbie łowisk z §5.2 (≤ 12).
- **Nagłówek strony:** zdjęcia **nad nazwą** — w tym zadaniu **zaślepki z makiet** (układ 1 duże +
  2 małe, etykieta „Wszystkie zdjęcia · N"); prawdziwa galeria — 036. Nazwa, akwen, województwo,
  „prowadzi: …".
- **Zakładki w jednym dokumencie HTML**, przełączane bez przeładowania, treść obu w HTML
  (indeksowana): „Mapa i terminy" (domyślna) i „Szczegóły" (kotwica zależna od języka:
  `#szczegoly` / `#details`). Przełącza je **nowe wejście Vite `resources/js/portal.js`**
  (bez Alpine, ładowane wyłącznie w układzie portalu; Rozstrzygnięcie 4): chowa nieaktywną zakładkę,
  otwiera tę z kotwicy przy wejściu i na `hashchange`. **Bez JS obie treści stoją jedna pod drugą.**
  Struktura zakładek gotowa na dołożenie „Cennika" i „Dokumentów" (034).
- **Mapa i terminy:** lewa kolumna — mapa łowiska (`map_image_path`, obrazek z dysku
  `config('filament.default_filesystem_disk')` — `Storage::disk(...)->url()`, bez przybijania dysku;
  brak mapy → sekcja niepokazywana) i **miejsce na kalendarz** (033); prawa — **box**: „cena od",
  „Rezerwacje telefonicznie", telefon, godziny, „Zadzwoń" (`tel:`), linki WWW i Facebook (tylko
  wypełnione, `rel="noopener"`, nowa karta); pod boxem opis łowiska i odsyłacz do „Szczegółów".
- **Box bez telefonu** (Rozstrzygnięcie 3): zamiast telefonu e-mail łowiska (`mailto:`), jeśli jest;
  bez obu — sama cena i linki. Bez tekstu zastępczego; pasek „Zadzwoń" na telefonie znika.
- **Szczegóły:** akwen (powierzchnia, stanowiska, doba, ryby — puste pola niepokazywane),
  „Zanim przyjedziesz" (parametry z 021; trzeci stan „nie podano" nie jest pokazywany jako „nie"),
  dojazd i udogodnienia, **stanowiska jako płaska lista po etykietach** z grupami, pojemnością
  i cechami filtrowalnymi, opisy grup, „Łowisko w sieci" (WWW, Facebook, **krótki adres wprost
  pod domeną** `{host}/{slug}` — ADR-021, opcja B).
  **Cennik i dokumenty nie wchodzą** — dostaną własne zakładki w 034.
- Ten sam box z ceną w obu zakładkach (desktop: przyklejony przy przewijaniu).
- **Telefon (390 px):** nazwa → zakładki → mapa → [kalendarz] → cena i telefon → opis → zdjęcia
  (zaślepki); pasek „Zadzwoń" przyklejony do dołu — tylko gdy łowisko ma telefon.
- **Etykieta „Wszystkie zdjęcia · N"**: N = liczba zdjęć w `gallery_images`; przy zerze etykiety nie ma
  (zaślepki zostają).
- **„Prowadzi: …"** — nazwa firmy łowiska (`company.name`), jako informacja o stronie umowy (TODO-1 §2.1).
- SEO strony łowiska: tytuł, opis (z opisu łowiska), canonical, `hreflang`.

## Kryteria akceptacji

- [ ] „Cena od" liczona jedną metodą w warstwie cennika; testy: kilka stawek, sezon trwający
      i przyszły, stawka zawieszona, stawka tylko w minionej części sezonu, okres zakończony, dopłata
      i kwota za towarzyszącą nie wpływają, brak stawki; karta strony głównej ją pokazuje.
- [ ] Box bez telefonu pokazuje e-mail albo nic, a pasek „Zadzwoń" znika.
- [ ] Obie zakładki w jednym dokumencie; kotwica otwiera właściwą zakładkę; bez JS widać obie treści.
- [ ] Puste pola (ryby, rekord, WWW, Facebook, parametry 021) nie są pokazywane jako „nie"/puste etykiety.
- [ ] Stanowiska: tylko w sprzedaży, kolejność etykiet, grupy przy numerze.
- [ ] **Ręczna weryfikacja** na telefonie (390 px, iOS Safari i Android Chrome), tablecie i desktopie,
      w obu językach, na danych Klasztornego i Łopienna; lista w podsumowaniu. Testy przeglądarkowe — 035.
- [ ] Testy w zadeklarowanym zakresie zielone (T2); **pełny pakiet odroczony** na `/review-implementation`.

## Zakres testów

- **Tier:** T2 — zależności
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="FisheryPage|PriceFrom|Portal"`
- **Uzasadnienie:** nowy widok portalu i nowa metoda w warstwie cennika, ale „cena od" zmienia też
  kartę strony głównej (`PortalFisheries`, testy `Portal*` z 031) — to kontrakt używany gdzie indziej.
  Bez wyzwalaczy T3. Pełny pakiet — `/review-implementation` (decyzja autora, 30.09.2026).

## Zakres wyłączeń

- Kalendarz (033) — tu tylko miejsce w układzie.
- Zakładki „Cennik" i „Dokumenty" (034).
- Galeria, podgląd pełnoekranowy, warianty zdjęć (036) — tu zaślepki.
- Klikalna mapa stanowisk („na później").

## Zmiany dokumentacji

- [ ] `docs/conventions/cennik.md` — metoda „ceny od" jako jedyne źródło **i jawny wyjątek w §5**:
      portal czyta „cenę od" z cennika, nie z `StayOffer` — to odczyt konfiguracji, nie werdykt
      sprzedaży (wzorem listy usług z 020); cena pobytu nadal wyłącznie przez `StayOffer`.
- [ ] `docs/conventions/strona-publiczna.md` — zakładki w jednym dokumencie (skrypt `portal.js`, treść bez JS),
      box z ceną i kontaktem (e-mail albo nic przy braku telefonu), „cena od" wyłącznie z metody cennika.
- [ ] `docs/operations/docker.md` — tabela wejść Vite: nowe wejście `resources/js/portal.js`.
- [ ] `CHANGELOG.md` — wpis (strona łowiska w portalu).

## Ograniczenia techniczne

- Konwencje: `strona-publiczna.md` (031), `cennik.md` (§5 warstwa oferty — Z3), `dostepnosc.md`
  (stanowiska w sprzedaży).
- Eager-load relacji (stanowiska, grupy, cechy, udogodnienia).

## Rozstrzygnięcia

1. **„Cena od" to odczyt cennika, nie oferta — jawny wyjątek od „portal pyta wyłącznie `StayOffer`"**
   (`cennik.md` §5), wzorem listy usług z 020. Metoda obok `PriceRuleResolver`; `StayOffer` zostaje
   jedynym wejściem dla ceny i sprzedawalności **pobytu**. Powód: oferta pracuje per stanowisko,
   a „cena od" jest liczbą łowiska, niezależną od daty i długości pobytu (§4).
2. **Okno „ceny od": od dziś do końca trwającego albo najbliższego okresu sprzedaży** — tak samo jak
   `PricingConfigurationAudit::firstPricingGap()`; stawka obowiązująca wyłącznie w minionej części
   sezonu się nie liczy, bo przeszłości nie sprzedajemy.
3. **Box bez telefonu: e-mail łowiska albo nic** — wynika z danych, bez tekstu zastępczego; pasek
   „Zadzwoń" na telefonie znika.
4. **Zakładki: jeden dokument HTML + mały skrypt portalu**, bez przeładowania (decyzja autora po
   rozmowie). Przeładowanie wymagałoby osobnego adresu na zakładkę (czwarty segment zależny od języka,
   rozszerzenie ADR-021) z własnym canonical i `hreflang`, a treść „Szczegółów" przestałaby być
   indeksowana pod adresem łowiska — tego unikało 027 pkt 9.4.

## Powiązane ADR-y
