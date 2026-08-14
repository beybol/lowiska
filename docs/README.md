# Dokumentacja projektu Łowiska

Cała dokumentacja jest **po polsku** (kod i UI po angielsku — patrz `CLAUDE.md`, sekcja „Język").

| Katalog | Co tu trafia | Kto to tworzy |
|---|---|---|
| [`tasks/`](tasks/) | Pliki zadań `NNN-opis.md` — problem, wymagania, kryteria akceptacji, zakres testów. Szablon: [`_template.md`](tasks/_template.md) | `/create-task`, uzupełnia `/review-task` |
| [`tasks/implemented/`](tasks/implemented/) | Zadania zrealizowane. **Przenosi je autor**, nie komenda | autor |
| [`adr/`](adr/) | Decyzje architektoniczne `ADR-NNN-temat.md`. Szablon: [`_template.md`](adr/_template.md) | `/review-task` zakłada, **Decyzję wypełnia autor** |
| [`conventions/`](conventions/) | Niezmienniki powierzchni aplikacji — „jak to jest zrobione i dlaczego akurat tak". Patrz [`conventions/README.md`](conventions/README.md) | `/implement-task` po zakończonym zadaniu |
| [`operations/`](operations/) | Dokumentacja operacyjna: uruchamianie, kontenery, wdrożenie | autor / zadania |

Katalog `reviews/` powstaje na żądanie — `/review-implementation` zapisuje tam raporty
(`RRRR-MM-DD-<zakres>.md`), gdy użytkownik wybierze taką dyspozycję uwag.

## Numeracja

Zadania i ADR-y mają numery **trzycyfrowe** (`001`, `042`). Numery zadań są ciągłe **przez oba
katalogi** — `tasks/` i `tasks/implemented/` — więc kolejny wolny numer ustala się skanując oba.

## Zasada rozdziału treści

- **Uzasadnienie decyzji** → ADR.
- **Przebieg zadania**, pomiary, ślepe uliczki → plik zadania.
- **Reguła obowiązująca dziś** → plik konwencji powierzchni.
- **Reguła obowiązująca niezależnie od tematu zadania** (workflow, tiery, bezpieczeństwo, komendy)
  → `CLAUDE.md`.

Ta sama treść nie powinna żyć w dwóch miejscach. Reguła unieważniona jest **przepisywana**, nie
dopisywana obok korekty.
