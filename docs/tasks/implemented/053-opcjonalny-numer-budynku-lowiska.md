# 053 — Opcjonalny numer budynku łowiska

## Opis problemu
Pole `building_number` łowiska było obowiązkowe (formularz `required()`, kolumna `NOT NULL`). Część łowisk nie ma numeru budynku (adres to sama ulica lub miejscowość), więc operator nie mógł ich zarejestrować bez wpisywania wartości zastępczej.

## Wymagania
- Pole „Building number" w formularzu łowiska (`FisheryResource`) nie jest wymagane.
- Kolumna `fisheries.building_number` jest nullable (nowa migracja zmieniająca kolumnę).
- Adres łowiska na ekranie łowiska (`FisheryResource`) i podgląd mapy poprawnie obsługują brak numeru.

## Kryteria akceptacji
- [ ] Formularz łowiska pozwala zapisać rekord bez numeru budynku.
- [ ] Migracja nullable wykonana.
- [ ] Test: utworzenie/zapis łowiska bez `building_number` przechodzi, a adres i podgląd mapy renderują się bez błędu.
- [ ] Wpis w `CHANGELOG.md`.
- [ ] Pełny pakiet testów zielony (T3 — wykonany na koniec sesji).

## Zakres testów
- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** zmiana obejmuje migrację, która jest wyzwalaczem T3 wg `CLAUDE.md`.

## Zakres wyłączeń
- Numer domu firmy (`companies.house_number`) — bez zmian, pozostaje wymagany.
- Walidacja formatu numeru budynku.

## Zmiany dokumentacji
- [ ] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne
- Brak ponad domyślne dla stacku.

## Rozstrzygnięcia
- Brak numeru zapisujemy jako `NULL`, nie pusty string — kolumna nullable, spójnie z innymi polami opcjonalnymi łowiska.

## Powiązane ADR-y
- Brak — zmiana odwracalna jedną migracją, bez zasięgu poza polem.
