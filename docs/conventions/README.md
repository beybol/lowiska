# Konwencje powierzchni

Ten katalog niesie **niezmienniki obowiązujące dziś** — reguły, których nie da się odtworzyć
z samego kodu: świadome odstępstwa, pułapki i rozwiązania wycofane, do których nie należy wracać.

**Tabela routingu — która powierzchnia mapuje się na który plik — żyje w `CLAUDE.md`**, w sekcji
„Konwencje powierzchni". Tutaj jest tylko instrukcja pisania.

## Kiedy powstaje plik

Przy **pierwszym zadaniu dotykającym danej powierzchni**, które ustaliło niezmiennik. Nie zakładaj
plików na zapas — pusty plik konwencji jest gorszy niż jego brak, bo sugeruje, że reguły zostały
spisane.

## Co wchodzi, a co nie

**Wchodzi:**
- reguła obowiązująca dziś, sformułowana jako niezmiennik („X zawsze idzie przez Y");
- świadome odstępstwo od reguły ogólnej, z jednozdaniowym „dlaczego";
- ⚠️ pułapka, w którą ktoś już wpadł, z jednym zdaniem „nie wracaj do X";
- odsyłacz do ADR-a, jeśli decyzja ma własne uzasadnienie.

**Nie wchodzi:**
- **historia zadania** („najpierw zrobiliśmy X, pękło, potem Y") — to zostaje w `docs/tasks/NNN-*.md`;
- **pełne uzasadnienie decyzji** — zostaje w ADR-ze; tutaj wystarczy niezmiennik + odsyłacz;
- **pomiary i archeologia** — plik zadania;
- reguła obowiązująca niezależnie od powierzchni — to `CLAUDE.md`.

## Jak aktualizować

⚠️ **Reguła unieważniona jest PRZEPISYWANA, nie dopisywana obok korekty.** Nie zostawiaj w pliku
wersji błędnej razem z dopiskiem „nieaktualne od zadania NNN" — czytelnik musi wtedy przyswoić obie.
Odwrócenie decyzji odnotowuj w ADR-ze (sekcja „Aktualizacja") i w pliku zadania.

**Miękki limit ~40 KB na plik** — po przekroczeniu skonsoliduj albo podziel wzdłuż granicy
tematycznej, zamiast dopisywać kolejną sekcję.

## Kształt pliku

```markdown
# Konwencje: <nazwa powierzchni>

Obowiązuje przy zmianach w `<ścieżki>`.

Zadania źródłowe: NNN, NNN. Uzasadnienia w ADR-NNN.

---

## 1. <Temat>

- **Niezmiennik zapisany wytłuszczeniem**, a po nim jednozdaniowe uzasadnienie.
- ⚠️ Pułapka albo świadome odstępstwo.
```
