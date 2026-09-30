# ADR-022 — Mechanizm interakcji portalu wędkarza

- **Status:** accepted
- **Data:** 2026-10-01
- **Zadanie:** [033 — Kalendarz portalu: płaska siatka stanowisk](../tasks/033-kalendarz-portalu.md)

## Kontekst

Kalendarz (033) jest pierwszym **interaktywnym** elementem portalu: tydzień ‹ ›, liczba łowiących,
grupa, cechy, wybór komórki z rozbiciem ceny, na telefonie wybór doby. Dotychczasowe strony portalu
(031, 032) są renderowane po stronie serwera, a jedyny skrypt (`portal.js`) przełącza zakładki
w obrębie jednego dokumentu.

Specyfikacja wymaga, żeby **stan był w adresie** (tydzień, łowiący, grupa, cechy — linkowalny,
canonical bez parametrów), a **komórki pochodziły z warstwy oferty** (`SaleCalendar` → `StayOffer`,
ADR-015) — portal niczego nie liczy po swojemu.

Dlaczego ADR, a nie rozstrzygnięcie w zadaniu:
- **Zasięg:** ten sam mechanizm obsłuży zajętość (etap 3) i wybór terminu do koszyka (etap 4) —
  kolejne interaktywne elementy portalu pójdą tą samą drogą albo będą niespójne.
- **Koszt odwrócenia:** zmiana mechanizmu po etapie 4 to przepisanie kalendarza, koszyka i ich
  testów; Livewire na stronach publicznych zmienia też model sesji, cache i to, co widzi robot.
- **Uzasadnienie warte zapamiętania:** „dlaczego portal nie używa Livewire, skoro ma go projekt"
  nie wynika z kodu.

## Alternatywy

### Opcja A — zwykłe odnośniki GET z parametrami, renderowanie po stronie serwera

Każdy przełącznik to link (`?tydzien=2026-06-08&lowiacych=2&grupa=brzeg-wschodni`), wybór doby
i stanowiska na telefonie też (`&doba=2026-06-10&st=8`); rozbicie na desktopie to podpowiedź
komórki wyrenderowana po stronie serwera (033, Rozstrzygnięcie 2). Kontroler czyta parametry, woła `SaleCalendar` i renderuje stronę.
Opcjonalnie **stopniowe ulepszenie**: `portal.js` przechwytuje kliknięcie, pobiera ten sam adres
i podmienia wyłącznie fragment kalendarza (bez przeładowania i skoku strony), aktualizując adres
przez `history.pushState`. Bez JS wszystko działa jako zwykłe przejścia.

**Zalety:**
- Stan w adresie jest **naturalny** — link jest jedynym źródłem stanu; podlinkowanie wyboru działa zawsze.
- Działa bez JS, na każdym telefonie; robot widzi zwykłe strony (canonical bez parametrów).
- Brak stanu po stronie serwera między żądaniami — cache HTTP i CDN są możliwe w przyszłości.
- Jeden kontroler, jeden widok, testy funkcjonalne zwykłymi żądaniami GET (jak cały portal).
- Stopniowe ulepszenie to dodatek do tego samego kontraktu, nie drugi mechanizm.

**Wady:**
- Bez ulepszenia każde kliknięcie to przeładowanie (pozycja przewinięcia wraca kotwicą kalendarza).
- Ulepszenie wymaga własnego kodu podmiany fragmentu (kilkadziesiąt linii) i dbałości o dostępność.

### Opcja B — komponent Livewire na stronie łowiska

Kalendarz jako komponent Livewire z `#[Url]` na właściwościach (tydzień, łowiący, grupa, cechy);
każda zmiana to żądanie `/livewire/update` i podmiana DOM-u.

**Zalety:**
- Znany mechanizm w projekcie (panele Filamenta); stan w adresie przez atrybuty `#[Url]`.
- Częściowa aktualizacja bez pisania własnego kodu podmiany.

**Wady:**
- Livewire na publicznych stronach: sesja i CSRF na każdym wejściu wędkarza, skrypt Livewire
  w portalu (dziś portal ładuje wyłącznie `portal.css` i `portal.js`, ADR-020), trudniejszy cache.
- **Każda właściwość publiczna to dane od klienta** (`CLAUDE.md`) — powierzchnia do pilnowania na
  stronie dostępnej anonimowo.
- Pierwszy render i tak musi działać bez JS dla robota — dwa tryby do testowania.
- Koszyk z etapu 4 zwiąże się z Livewire także po stronie portalu.

### Opcja C — Alpine/JS z danymi JSON z osobnego endpointu

Strona dostaje szkielet, a `portal.js` pobiera siatkę jako JSON i renderuje ją po stronie klienta.

**Zalety:**
- Najpłynniejsza interakcja; jeden endpoint danych dla przyszłej aplikacji mobilnej.

**Wady:**
- **Bez JS nie ma kalendarza**, a robot nie widzi komórek; druga warstwa szablonów (w JS) obok Blade.
- Formatowanie kwot, tłumaczenia i powody ze słownika trzeba przenieść do klienta albo serializować.
- Najwięcej kodu i najtrudniejsze testy (wymaga testów przeglądarkowych, 035).

## Rekomendacja

**Opcja A — GET z parametrami, renderowanie po stronie serwera, ze stopniowym ulepszeniem
w `portal.js` (podmiana fragmentu kalendarza bez przeładowania).** Stan w adresie jest wymaganiem,
a w A jest on jedynym źródłem prawdy, nie synchronizowaną kopią stanu komponentu. Portal zostaje
przy dwóch wejściach Vite z ADR-020 i bez sesji Livewire na stronach publicznych. Ulepszenie można
dołożyć w tym samym zadaniu albo później bez zmiany kontraktu adresów i widoku.

Konsekwencje dla 033:
- parametry adresu mają jeden dom (obok `PortalRoutes`), nieznane i błędne wartości są ignorowane
  (spadają do domyślnych), a canonical jest zawsze bez parametrów;
- fragment kalendarza ma własny partial Blade, renderowany i w pełnej stronie, i w odpowiedzi
  na żądanie ulepszenia (ten sam kontroler, nagłówek żądania wybiera fragment);
- linki przełączników niosą `rel="nofollow"`, żeby robot nie mnożył wariantów strony.

## Decyzja
Decyzja: A
