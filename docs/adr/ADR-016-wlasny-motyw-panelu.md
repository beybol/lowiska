# ADR-016 — Własny motyw panelu i granica stosowania klas Tailwinda

- **Status:** accepted
- **Data:** 2026-09-23
- **Zadanie:** [019 — Kalendarz podglądowy konfiguracji](../tasks/implemented/019-kalendarz-podgladowy-konfiguracji.md)

## Kontekst

Do dziś **żaden panel nie rejestruje własnego motywu** (`viteTheme()`). Wynikający z tego niezmiennik
zapisano w [`panel-wlasciciela.md`](../conventions/panel-wlasciciela.md) §1: klasy Tailwinda użyte
wewnątrz stron Filamenta **nie mają skąd wziąć CSS-u**, bo kompiluje się wyłącznie to, czego używa
sam framework. Ręcznie stylowany komponent wygląda więc poprawnie **tylko przypadkiem** i rozsypuje
się przy pierwszym upgrade'ie. Zasada powstała po tym, jak kreator łowiska i strona zarządzania
wymagały ręcznej naprawy przy dwóch kolejnych wersjach Filamenta.

Przez pięć zadań (014–018) zasada nie kosztowała nic, bo każdy ekran dawał się złożyć ze
standardowych komponentów: formularze, repeatery, tabele list. **Zadanie 019 jest pierwszym, które
tego nie potrafi.** Kalendarz podglądowy to siatka stanowiska × doby z komórkami niosącymi kwotę
i liczbę dób, z przyklejoną pierwszą kolumną i z wierszem „Pakiety", w którym jedna komórka rozciąga
się na kilka kolumn.

Rozpoznanie z 2026-09-22 zamknęło trzy drogi i **żadna nie omija motywu**:

- **tabela Filamenta z kolumnami generowanymi na doby** — tabele Filamenta 5 nie znają `colspan`,
  więc wiersz „Pakiety" jest w nich niewykonalny; sortowanie, filtry i przełączniki kolumn są przy
  setkach komórek martwym balastem;
- **`saade/filament-fullcalendar`** — wsparcie dla Filamenta 5 w becie, a widok zasobowy to
  **FullCalendar Premium**, licencja od 480 USD rocznie dla zastosowania komercyjnego;
- **`guava/calendar`** — darmowy (MIT), stabilny dla Filamenta 5, z widokami zasobowymi, ale zakłada
  **model zdarzeń**: nasze komórki są wyliczonymi werdyktami, więc trzeba by produkować setki
  sztucznych jednodobowych „eventów" na render. ⚠️ **Ta wtyczka również wymaga własnego motywu** —
  jej dokumentacja mówi o tym wprost.

Decyzja jest więc nie „wtyczka czy własny widok", tylko **czy w ogóle znosimy zakaz ręcznego
stylowania** — a jeśli tak, to gdzie przebiega nowa granica. Bez odpowiedzi zadanie 019 nie da się
zaimplementować żadną drogą.

⚠️ **Rzecz, której nie widać z samego zadania:** po dołożeniu motywu **każdy** przyszły ekran będzie
mógł sięgnąć po klasy Tailwinda. Zniesienie zakazu bez zapisania, kiedy wolno, zamienia jednorazowy
wyjątek w domyślny sposób pracy — i wraca problem, dla którego zakaz powstał.

## Alternatywy

### Opcja A — rejestrujemy motyw w obu panelach, a granicę zapisujemy jako regułę

Jedno małe zadanie przed 019: `viteTheme()` w `AdminPanelProvider` i `OwnerPanelProvider`, plik
motywu z dyrektywami Filamenta, konfiguracja Vite. **Bez żadnych zmian wizualnych** — motyw wchodzi
pusty, żeby zmiana dała się odróżnić od zmian wyglądu.

Nowa granica zastępuje dzisiejszy zakaz: **klas Tailwinda wolno użyć wyłącznie w widoku, którego nie
da się złożyć ze standardowych komponentów, i tylko po uzasadnieniu w zadaniu.** Reguła „przyciski
i ikony deklaruje `Action::make()->icon()`, nie surowy `<svg>`" zostaje w mocy bez zmian.

**Zalety:**
- Odblokowuje 019 jedyną drogą, która nie wymaga ani licencji, ani modelu zdarzeń.
- Motyw jest infrastrukturą jednorazową: raz zarejestrowany, działa dla wszystkich przyszłych ekranów.
- Zmiana pusta wizualnie jest **łatwa do zweryfikowania** — pełny pakiet testów albo przechodzi
  bez zmian, albo coś jest nie tak z rejestracją.
- Granica zapisana jako reguła zachowuje to, co chroniło dotychczasowy zakaz: domyślnie nadal
  składamy ekrany z komponentów.

**Wady:**
- ⚠️ Dotyka **providerów obu paneli**, czyli wyzwalacza T3 z `CLAUDE.md` — wymusza pełny pakiet.
- Reguła „tylko gdy się nie da inaczej" jest miękka; jej egzekwowanie zależy od przeglądu, nie od
  narzędzia.
- Dochodzi krok budowania zasobów, o którym trzeba pamiętać przy wdrożeniu i w obrazie produkcyjnym.

### Opcja B — zostawiamy zakaz, a 019 buduje siatkę bez własnych stylów

Kalendarz powstaje wyłącznie na standardowych komponentach: tabela Filamenta z kolumnami
generowanymi na doby, bez wiersza „Pakiety" i bez przyklejonej kolumny.

**Zalety:**
- Zero zmian w providerach, więc 019 zostaje przy T1.
- Niezmiennik z `panel-wlasciciela.md` §1 zostaje nietknięty.

**Wady:**
- ⚠️ **Wiersz „Pakiety" wypada bezpowrotnie** — a to on oddziela regułę od jej skutku i pokazuje
  zlewanie pakietów, czyli jeden z trzech powodów, dla których 019 w ogóle powstaje.
- Tabela niesie przy setkach komórek maszynerię (sortowanie, filtry, przełączniki), z której
  podgląd nie korzysta.
- Odkłada problem, zamiast go rozstrzygnąć: następny ekran wymagający własnego układu wróci z tym
  samym pytaniem, tylko później i pod presją.

### Opcja C — motyw tylko w panelu właściciela

Rejestrujemy `viteTheme()` wyłącznie w `OwnerPanelProvider`, bo to tam żyje kalendarz.

**Zalety:**
- Mniejszy zasięg zmiany; panel administratora zostaje nietknięty.

**Wady:**
- ⚠️ **Rozjeżdża panele**: `FisheryResource` jest zarejestrowany w **obu**, więc ten sam ekran
  wyglądałby poprawnie u właściciela i rozsypywał się u administratora — który ogląda cudze łowiska
  w ramach onboardingu concierge (IDEA 10.1), czyli dokładnie w scenariuszu, dla którego kalendarz
  powstał.
- Tworzy różnicę między panelami, której dziś nie ma, i każdy przyszły ekran będzie musiał pytać,
  w którym panelu wolno mu się stylować.

## Rekomendacja

**Opcja A**, jako **osobne zadanie poprzedzające 019**.

1. **Opcja B odbiera 019 jedną z trzech rzeczy, dla których powstaje.** Wiersz „Pakiety" nie jest
   ozdobnikiem — pokazuje zlewanie, którego nie widać w żadnym formularzu. Rezygnacja z niego jest
   cięciem zakresu, nie wyborem technicznym.
2. **Opcja C kupuje mniejszy zasięg kosztem rozjazdu między panelami** w ekranie, który z założenia
   żyje w obu. To gorsze niż problem, który rozwiązuje.
3. **Osobne zadanie, a nie część 019**, bo rejestracja motywu dotyka **providerów paneli** — wyzwalacza
   T3. Włączona do 019 podniosłaby tamto zadanie z T1 na T3 i zmieszała pełny pakiet nad zmianą
   kilkunastu linijek z pełnym pakietem nad nowym ekranem, usługą widoku i pomiarem wydajności.
   Rozdzielone: T3 biegnie nad zmianą, która ma jednego oczywistego podejrzanego.

⚠️ **Czego rekomendacja NIE rozstrzyga:** czy w motywie wolno definiować **własne klasy**, czy tylko
korzystać z warstwy narzędziowej Tailwinda. To pytanie wróci przy pierwszym ekranie, który zechce
własnego komponentu wizualnego — dziś nie ma go po co rozstrzygać, bo kalendarz potrzebuje wyłącznie
siatki.

## Decyzja
Decyzja: A
