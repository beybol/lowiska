# ADR-026 — Zamrożenie warunków rezerwacji (snapshot G1/Z1)

- **Status:** accepted
- **Data:** 2026-10-08
- **Zadanie:** [039 — Rdzeń rezerwacji](../tasks/implemented/039-rdzen-rezerwacji.md)

## Kontekst

Zasada Z1 ([ZASADY-PROJEKTOWE](../project/ZASADY-PROJEKTOWE.md)): konfiguracja żyje, transakcja jest
zamrożona. Rezerwacja (etap 3: ręczna; etap 4: zakup) musi zapisać komplet warunków, na jakich doszło do
umowy, żeby późniejsza zmiana cennika, polityki zwrotu, regulaminu czy cech stanowiska jej nie ruszyła,
a zwrot liczył się po warunkach z chwili umowy.

Stan wyjściowy: `StayPriceBreakdown` zwraca rozbicie ceny, ale „to nie jest snapshot G1" (komentarz
w klasie); dokumenty mają niekasowane wersje (ADR-017); polityka zwrotu jest bieżącą kolumną JSON
łowiska; stanowisko ma bieżące cechy i pojemność. Powiązane: kontakt wędkarza ([ADR-024](ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md)),
przedział czasu ([ADR-025](ADR-025-zajetosc-stanowiska-jako-sloty-przedzialowe.md)).

Dlaczego ADR: **zasięg** — zwroty, faktury, raporty, etap 4; **koszt odwrócenia** — brakujące pole
w zamrożonych warunkach nie da się odtworzyć dla rezerwacji już sprzedanych; **uzasadnienie** —
„dlaczego kopia, a nie wskazanie" wynika z braku wersji polityki zwrotu i cennika, nie z kodu.

## Alternatywy

### Opcja A — wszystko w jednej kolumnie JSON na rezerwacji
**Zalety:** najprostszy zapis.
**Wady:** wartości do sumowania i filtrowania (kwota, liczba osób) siedzą w JSON-ie; trudniej o indeksy i raporty.

### Opcja B — tabela wersji warunków (append-only, 1:n)
**Zalety:** pełna historia zmian jako dane.
**Wady:** zmiany będą rzadkie; historię da się mieć z dziennika zmian. Nadmiar struktury.

### Opcja C — jeden rekord `reservation_terms` na rezerwację (1:1), kolumny dla wartości prostych, JSON dla struktur złożonych *(wybrana)*
**Zalety:** wartości do sumowania i filtrowania są kolumnami; struktury złożone (rozbicie ceny, polityka
zwrotu, cechy) w JSON-ie; zmiany śledzi dziennik.
**Wady:** historia zmian tylko w dzienniku (musi być trwały).

## Rekomendacja

Opcja C.

## Decyzja

Decyzja: C — uzgodniona z autorem 08.10.2026 (zamiast wersjonowania: „zmian będzie mało, to overkill").
Ustalenia wiążące:

1. **Tabela `reservation_terms`, jeden rekord na rezerwację (1:1).** Kolumny dla wartości prostych
   (suma po obniżkach w groszach, waluta, liczba łowiących, liczba towarzyszących, `schema_version`);
   JSON wyłącznie dla struktur złożonych i szczegółów (rozbicie ceny na doby i osoby, polityka zwrotu,
   wartości cech stanowiska, pozycje usług, wyjątki od reguł). Kontakt wędkarza zgodnie z ADR-024
   (`contact_snapshot`), przedziały czasu w `reservation_slots` (ADR-025).
2. **Wskazanie, gdzie jest wersja; kopia, gdzie jej nie ma.** Dokumenty łowiska: `id` wersji
   obowiązujących w chwili rezerwacji (ADR-017). Polityka zwrotu, cennik (rozbicie), parametry
   stanowiska: kopia wartości.
3. **Zamrożenie nie znaczy „nieedytowalne", tylko „nie zmienia się niejawnie".** Warunki zmienia
   wyłącznie jawna operacja (zmiana terminu, liczby osób, ceny), zawsze ze zdarzeniem w dzienniku zmian
   (stare i nowe wartości, kto, dlaczego). Zmiana konfiguracji łowiska nigdy nie przepisuje warunków
   (zob. ADR-025 pkt 10). Pilnuje tego test: edycja konfiguracji nie zmienia `reservation_terms`.
4. **Dziennik zmian rezerwacji w osobnym kanale** (`log_name = reservations`, `LogsActivity`,
   `dziennik-zmian.md`). Kanał nie podlega czyszczeniu; bez trwałego dziennika punkt 3 przestaje chronić
   historię.
5. **Zwrot i anulacja liczą się wyłącznie z `reservation_terms`**, nigdy z bieżącej konfiguracji.
6. **Zdarzenia osobno od warunków:** akceptacja dokumentów przez wędkarza, płatność i potwierdzenie
   rezerwacji nie są częścią zamrożonych warunków. W etapie 3 rezerwacja ręczna wskazuje obowiązujące
   wersje dokumentów, a akceptacja czeka na etap 4.
7. **Cena ręczna operatora:** operator może ją zmienić. `reservation_terms` zapisuje cenę *wyliczoną
   przez system* i *uzgodnioną*, razem z obowiązkowym powodem. Cena wyliczona nie jest nadpisywana.
8. **Rezerwacja wbrew regułom sprzedaży:** operator może zarezerwować mimo odmowy z
   `StayOffer`/`PositionAvailability` (np. poza sezonem, poniżej minimalnej długości). Interfejs
   pokazuje ostrzeżenie z powodem odmowy; wyjątek jest zapisany w `reservation_terms` (która reguła,
   powód, kto) i w dzienniku. Dotyczy reguł sprzedaży; **kolizja z zajętym slotem** (ADR-025) i
   **blokada nałożona na stanowisko** to osobna decyzja (G8), niniejszy punkt jej nie rozstrzyga.
9. **Usługi dodatkowe z wyceną wchodzą do etapu 3** (zmiana względem roadmapy z 24.09.2026, która
   odkładała wycenę usług do etapu 4). Wycena usług dla pobytu przechodzi do warstwy oferty
   (aktualizacja [ADR-015](ADR-015-warstwa-oferty-pobytu.md)); pozycje usług, ilości i ceny trafiają
   do `reservation_terms`. Limity egzemplarzy: ADR-025 pkt 7.

## Aktualizacja (2026-10-08)

Punkt 8: zastrzeżenie o blokadzie domyka [ADR-027](ADR-027-kolizje-konfiguracji-z-rezerwacjami.md) pkt 7 —
rezerwacja operatora na zablokowanym stanowisku jest dozwolona z ostrzeżeniem i zapisana jako wyjątek.

## Aktualizacja (2026-10-10)

Zwrot przy anulacji domyślnie wynika z `reservation_terms` (polityka zwrotu z chwili umowy). **Operator może
wybrać inny zwrot** (np. pełny wbrew polityce) jako jawny wyjątek — zapisany w warunkach rezerwacji razem
z powodem i kto/kiedy, tak samo jak wyjątek od ceny (pkt 7) i reguł sprzedaży (pkt 8).

## Aktualizacja (2026-10-11)

Uzupełnienie pkt 8 (decyzja autora przy zadaniu 047, B6): **operator i admin mogą zapisać rezerwację z usługą
nieoferowaną na stanowisku albo przy braku lub zawieszeniu wymaganej cechy** — jako wyjątek z powodem w warunkach
rezerwacji (rodzaj `service`: usługa, przyczyny, powód, kto, kiedy), tak samo jak wyjątek od reguł sprzedaży.
Skoro operator może sprzedać pobyt wbrew regułom, odmowa dodania łódki „bo nie jest przypięta do tego stanowiska"
blokowałaby obsługę telefonu bez żadnej ochrony. **Wyczerpany limit egzemplarzy wyjątku nie ma** — to podwójna
sprzedaż tego samego przedmiotu, jak zajęty slot (ADR-025 pkt 7). Kanał wędkarza nie zapisuje takich wyjątków nigdy.

Przy zmianie warunków (`rebook()`) zapisane wyjątki są punktem wyjścia: wyjątek od tej samej reguły (albo usługi)
przenosi się bez nowego powodu, a nowe się dopisują (zadanie 047, C2).
