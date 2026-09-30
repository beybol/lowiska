# 030 — Dane łowiska dla portalu

> **Etap 1, zadanie 3** ([roadmapa](../project/ROADMAPA.md)). Założone 30.09.2026.
> Specyfikacja: [portal-v3 §6](../project/mockups/portal-v3/README.md). Może iść równolegle z 029.

## Opis problemu

Portal potrzebuje danych łowiska, których konfiguracja nie ma: adresu strony (slug), stałego slugu
województwa, kontaktu do rezerwacji telefonicznych, linków do strony WWW i Facebooka oraz decyzji
właściciela, że łowisko ma się pokazać wędkarzom. Kolumna `positions_count` jest drugą prawdą obok
tabeli stanowisk. Bez tych danych strona główna (031) nie ma czego wylistować ani dokąd linkować.

## Wymagania

- **Slug łowiska** — kolumna unikalna w **całym portalu**, generowana z nazwy przy utworzeniu
  (`Str::slug`, sufiks `-2`, `-3`… przy kolizji — **łącznie z łowiskami usuniętymi miękko**, bo indeks
  unikalny ich nie pomija), **stała** (zmiana nazwy nie zmienia sluga). Zmiana wyłącznie przez admina
  w panelu admina (pole walidowane: format sluga, unikalność); w panelu właściciela slug widoczny
  **tylko do odczytu** (Rozstrzygnięcie 6). **Bez historii slugów** (027 pkt 9.3). Istniejące łowiska
  dostają slug w migracji.
- **Slug województwa** — stała kolumna w słowniku `State` (np. `wielkopolskie`), uzupełniona
  migracją dla istniejących województw (`Str::slug(name)`); unikalna w obrębie kraju
  (`unique(country_id, slug)`). Nowe województwo dostaje slug z nazwy przy utworzeniu; admin może go
  zmienić w zasobie `State`; zmiana nazwy sluga nie zmienia.
- **Kontakt na łowisku:** telefon, e-mail, godziny kontaktu (tekst) — wszystkie opcjonalne
  (walidacja: Rozstrzygnięcie 7).
- **Strona WWW i Facebook** — dwa opcjonalne pola URL, walidowane (Rozstrzygnięcie 7).
- **Publikacja — kolumna `published_at`** (nullable timestamp; `null` = nieopublikowane, scope
  `published()`), ustawiana przez właściciela w panelu właściciela przyciskiem „Opublikuj" /
  „Wycofaj z portalu" (Rozstrzygnięcia 2 i 3). Przed publikacją **ostrzeżenie o brakach** z listą
  z §6 specyfikacji i odsyłaczami do ekranów poprawy. Ostrzeżenie **nie blokuje** publikacji — z jednym
  wyjątkiem: **bez województwa publikacja jest niemożliwa** (nie ma adresu kanonicznego).
  Lista punktów liczona w jednej usłudze w `app/Services/` (np. `FisheryPublicationReadiness`):
  1. godziny doby i 2. okres sprzedaży — z `SaleCalendar::missingSetup()`;
  3. stanowisko: brak jakiegokolwiek — z `missingSetup()`; stanowiska są, ale żadne nie jest
     w sprzedaży — `positions()->available()->exists()` (Rozstrzygnięcie 1);
  4. dziura w cenniku — `PricingConfigurationAudit::firstPricingGap()`;
  5. telefon;
  6. opis, co najmniej jedno zdjęcie w galerii, mapa łowiska;
  7. obowiązujący regulamin (model `Document` z 021).
  Punkty 1–4 **nie sprawdzają po swojemu**.
- **Województwo wymagane w formularzu, póki łowisko jest opublikowane** — zapis bez niego się nie
  uda. Pozostałe braki powstałe po publikacji łowiska nie wycofują (Rozstrzygnięcie 4).
- **Autoryzacja publikacji:** metoda `FisheryPolicy::publish()` z tą samą regułą co `update()`
  (właściciel — tylko swoje łowisko, admin — każde), **bez nowego uprawnienia Shielda**. Akcja woła
  jawnie `authorize('publish', $record)`. Panel admina pokazuje status publikacji, bez przycisku.
- **Usunięcie `positions_count`** — liczba stanowisk wyliczana ze stanowisk w sprzedaży; usunięcie
  z formularzy, kreatora i zasobów.
- Pola widoczne i edytowalne w panelu właściciela (ekran ustawień łowiska) i w panelu admina.
- Zmiany logowane w dzienniku zmian, jeśli model ma `LogsActivity`.

## Kryteria akceptacji

- [ ] Migracje dodają slug łowiska, slug województwa, kontakt, WWW, Facebook, flagę publikacji
      i usuwają `positions_count`; istniejące rekordy mają slugi.
- [ ] Slug nie zmienia się po zmianie nazwy; kolizja dostaje sufiks; admin może zmienić slug ręcznie.
- [ ] Publikacja bez województwa jest zablokowana z komunikatem; pozostałe braki dają ostrzeżenie
      z listą i odsyłaczami, publikacja jest możliwa; wycofanie czyści `published_at`.
- [ ] Opublikowane łowisko nie zapisze się bez województwa.
- [ ] Właściciel nie może zmienić sluga (pole tylko do odczytu, a próba zapisu z pominięciem
      interfejsu nie zmienia wartości).
- [ ] Testy: generowanie i stałość sluga, kolizja (także z łowiskiem usuniętym miękko), slug
      województwa, ostrzeżenie (każdy punkt listy, w tym „stanowiska są, ale żadne w sprzedaży"),
      blokada bez województwa, autoryzacja publikacji (właściciel tylko swojego łowiska),
      walidacja telefonu, e-maila, WWW i Facebooka.
- [ ] Pełny pakiet testów zielony (T3).

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** migracje i nowa metoda w `FisheryPolicy` — wyzwalacze T3 z `CLAUDE.md`.

## Zakres wyłączeń

- Współrzędne i geokodowanie — „na później" (próg 12 łowisk).
- Warianty zdjęć i zasady galerii (zadanie 036).
- Wyświetlanie tych danych w portalu (031, 032).
- Zawieszenie łowiska przez administratora („na później" w roadmapie) i przycisk publikacji w panelu admina.
- Trasy portalu i ADR schematu adresów (031).
- Samoczynne wycofywanie łowiska przy brakach innych niż województwo.

## Zmiany dokumentacji

- [x] `docs/conventions/panel-wlasciciela.md` — publikacja i ostrzeżenie o brakach (§6 ekran ustawień).
- [x] `docs/conventions/panel-admina.md` — ręczna zmiana sluga.
- [x] `docs/conventions/autoryzacja.md` — `FisheryPolicy::publish()` jako metoda bez własnego
      uprawnienia Shielda (reguła = `update()`).
- [x] ~~`docs/conventions/dostepnosc.md` / `cennik.md`~~ (pliki nie wymieniają odbiorców — bez zmian) — odsyłacz: ostrzeżenie przed publikacją jest
      kolejnym odbiorcą `missingSetup()` i `firstPricingGap()` (jeśli pliki wymieniają odbiorców).
- [x] `MANUAL.md` — §2 (publikacja jako ostatni krok kolejności), §3 (nowe pola jednym zdaniem:
      kontakt, WWW, Facebook, adres strony tylko do odczytu; przycisk „Opublikuj" z ostrzeżeniem
      i blokadą bez województwa), §16 (admin zmienia slug ręcznie; zmiana unieważnia stary adres).
      Bez opisu walidacji poszczególnych pól — to poziom szczegółu spoza instrukcji.
- [x] `CHANGELOG.md` — wpis (nowe pola i publikacja w panelu).

## Ograniczenia techniczne

- Konwencje: `panel-wlasciciela.md` (ekran ustawień, natywne komponenty Filamenta — ADR-006),
  `autoryzacja.md` (polityki), `dziennik-zmian.md`, `cennik.md` i `dostepnosc.md` (źródła punktów 1–4).
- Relacje z generykami (`CLAUDE.md`).

## Rozstrzygnięcia

1. **Punkt 3 ostrzeżenia to dwa sprawdzenia:** „brak stanowisk" z `SaleCalendar::missingSetup()`
   oraz „stanowiska są, ale żadne nie jest w sprzedaży" przez `positions()->available()->exists()`.
   `missingSetup()` zostaje bez zmian — inaczej kalendarz 019 zamiast siatki wycofanych stanowisk
   pokazywałby mylące „dodaj stanowiska".
2. **Publikacja to `published_at` (nullable timestamp), nie bool** — ta sama informacja plus data
   wejścia do portalu, bez sięgania do dziennika zmian.
3. **Publikuje ten, kto edytuje:** `FisheryPolicy::publish()` = reguła `update()`, bez nowego
   uprawnienia Shielda; przycisk tylko w panelu właściciela.
4. **Województwa nie da się usunąć z opublikowanego łowiska** (walidacja formularza); inne braki
   po publikacji nie wycofują łowiska — to ostrzeżenie, nie blokada.
5. **Bez ADR-u w tym zadaniu.** Kształt sluga (unikalny w portalu, stały, bez historii) rozstrzygnęło
   027 pkt 9.3; kolumna jest dziś tania w zmianie, a nieodwracalny jest dopiero krótki adres
   drukowany na banerach — ten zapisuje ADR schematu adresów w 031, który przyjmie slug z 030 jako dane.
6. **Slug w panelu właściciela — widoczny tylko do odczytu** (właściciel chce znać adres swojej
   strony); pole edytowalne istnieje wyłącznie w schemacie formularza panelu admina, więc Filament
   nie przyjmie tej wartości od właściciela.
7. **Walidacja pól kontaktu:** telefon — tekst do 32 znaków, wyłącznie cyfry, spacje, `+`, `-`, `(`, `)`,
   co najmniej 6 cyfr; e-mail — reguła `email`; WWW — `url` ze schematem `http`/`https`; Facebook —
   `url` z hostem `facebook.com`, `www.facebook.com`, `m.facebook.com` albo `fb.com`; godziny
   kontaktu — tekst do 255 znaków. Reguły w `app/Rules/`, jeśli wychodzą poza wbudowane.

## Powiązane ADR-y

- Brak — patrz Rozstrzygnięcie 5. Slug łowiska i województwa trafi jako dane wejściowe do ADR-u
  schematu adresów zakładanego w 031.

## Stan po implementacji (2026-09-30)

Zrealizowane w całości. Odnotowane świadomie:

1. **Punkt 2 listy („trwający albo przyszły okres sprzedaży") pochodzi z `SaleCalendar::seasons()`**, nie
   z `missingSetup()`. `missingSetup()` zwraca `sale_period` tylko przy braku JAKIEGOKOLWIEK okresu,
   więc łowisko z samym okresem zakończonym przeszłoby bez ostrzeżenia. `seasons()` to ta sama klasa
   kalendarza (okresy trwające i przyszłe), więc warunek „nie sprawdzają po swojemu" jest zachowany.
2. **`missingSetup()` zatrzymuje się na pierwszym braku**, więc „brak stanowisk" (`NoPositions`) widać
   tylko wtedy, gdy doba i okres są ustawione. Przy brakującej dobie łowisko bez stanowisk dostaje
   „żadne stanowisko nie jest w sprzedaży" — prawdziwe i prowadzące do tego samego ekranu.
3. **Województwo było wymagane w formularzu już wcześniej** (`state_id` z `required()`), więc
   Rozstrzygnięcie 4 nie wymagało nowej walidacji — pilnuje go test. ⚠️ Kolumna ma nadal
   `onDelete('set null')`: usunięcie województwa ze słownika zdejmie je także z opublikowanego
   łowiska. Wtedy łowisko zostaje opublikowane bez adresu kanonicznego — do rozważenia w 031
   (np. blokada usunięcia używanego województwa).
4. **Slug województwa liczy się z POLSKIEGO tłumaczenia nazwy** — nazwy w bazie to angielskie klucze
   tłumaczeń („Greater Poland"), a `Str::slug()` z samej nazwy dałby `greater-poland`.
5. Linki do ekranów poprawy prowadzą: doba i okres → „Sprzedaż i sezony", stanowiska → „Stanowiska",
   cennik → „Cennik", regulamin → „Dokumenty", pozostałe → formularz edycji łowiska.
