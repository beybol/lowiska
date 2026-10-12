# 041 — Rezerwacja przez operatora: lista, formularz, szczegóły, edycja i anulacja

## Opis problemu
Rdzeń rezerwacji (039) nie ma interfejsu. Operator i admin muszą móc założyć, zmienić i odwołać rezerwację
oraz zobaczyć ich listę — to zastępuje ręczny grafik. Specyfikacja i makieta: `docs/project/mockups/etap3/`
(ekrany B, C, D oraz modale z kalendarza).

## Wymagania
- Nowa pozycja sub-nawigacji łowiska **„Rezerwacje"** zaraz za „Kalendarzem" (strona zasobu `FisheryResource`,
  oba panele); plakietka z liczbą niepotwierdzonych wygasających w ciągu 24 h i „Do kontaktu".
- **Lista (B):** tabela z wędkarzem (imię, telefon, status powiązania), stanowiskiem, terminem, statusem,
  płatnością, kwotą, kto utworzył, źródłem; filtry statusu i zakładka „Do kontaktu"; wyszukiwanie wyłącznie
  w relacjach tego łowiska (ADR-024); akcje zbiorcze: potwierdź, oznacz płatność, anuluj z komunikatem.
- **Formularz (C):** pełna strona z `Grid` + `Section`; wędkarz (imię, telefon, e-mail opcjonalnie, notatka),
  pobyt (stanowisko, daty, łowiący, usługi), oferta z wyceną (`StayOffer`), cena uzgodniona z powodem,
  ostrzeżenie „poza regułami sprzedaży"; parametry z adresu (`position`, `from`, `nights`) wypełniają formularz
  (wejście z kalendarza, zadanie 042).
- **Szczegóły (D):** `ViewRecord` z infolistą — wędkarz, zamrożone warunki (terms), płatność, historia z
  kanału `reservations`.
- **Modale** współdzielone z kalendarzem: edycja (dane wędkarza, osoby, cena; zmiana terminu/stanowiska
  sprawdza dostępność przez serwis z 039) i anulacja (zwrot z warunków umowy lub **jawny wyjątek z powodem**,
  opcjonalny komunikat do wędkarza, mail o anulacji przez szablon z 040).
- **Anulacja trwającej rezerwacji** jest możliwa, ale modal najpierw pyta: „Ten pobyt już trwa. Czy na pewno
  chcesz anulować trwającą obecnie rezerwację?" (przycisk „Tak, anuluj trwającą rezerwację"). Zasady zwrotu
  za niewykorzystane doby — przy płatnościach (etap 4); teraz zmienia się status (ADR-027, aktualizacja 10.10).
- Rezerwacja bez e-maila przy anulacji → `needs_contact_at`, wpis na liście „Do kontaktu"; akcja „Oznacz jako
  załatwione" ustawia `contacted_at`.
- Autoryzacja: `ReservationPolicy` + uprawnienia Shielda (`docs/conventions/autoryzacja.md`); operator widzi
  wyłącznie rezerwacje swoich łowisk, admin wszystkie.

## Kryteria akceptacji
- [x] Operator zakłada rezerwację z formularza; zapis idzie przez serwis z 039 (test funkcjonalny).
- [x] Formularz ostrzega przy naruszeniu reguł sprzedaży i zapisuje wyjątek; kwota uzgodniona wymaga powodu.
- [x] Lista filtruje po statusie i „Do kontaktu"; wyszukiwanie nie wychodzi poza łowisko.
- [x] Anulacja zwalnia sloty, ustawia status płatności „zwrot" (gdy opłacona) i wysyła mail z komunikatem;
      bez e-maila trafia do „Do kontaktu".
- [x] Anulacja trwającej rezerwacji wymaga dodatkowego potwierdzenia (test).
- [x] Zwrot inny niż z warunków wymaga powodu i zapisuje wyjątek.
- [x] Operator cudzego łowiska dostaje 404/403; zmiany widoczne w historii szczegółów.
- [x] Zachowane konwencje `panel-wlasciciela.md` (pełne strony dla formularza, natywne komponenty, kolejność menu).
- [x] Pełny pakiet testów (T3 — polityka i Shield).

## Zakres testów
- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** nowa polityka i uprawnienia Shielda (obowiązkowy wyzwalacz T3) oraz nowa pozycja
  nawigacji współdzielonej przez oba panele.

## Zakres wyłączeń
- Kalendarz i jego popovery (042); przepływ powiązania z kontem (043); kolizje blokad (044); ustawienie
  „wymagaj potwierdzenia" i wygasanie (045).
- Zasady zwrotu za niewykorzystane doby, rejestr „do zwrotu", faktyczne zwroty (etap 4).
- Eksport grafiku/listy (Q4).

## Zmiany dokumentacji
- [x] `docs/conventions/panel-wlasciciela.md` — pozycja „Rezerwacje", plakietki, odstępstwo „modal dla krótkich akcji w kalendarzu"
- [x] `docs/conventions/autoryzacja.md` — `ReservationPolicy`
- [x] `docs/project/mockups/etap3/README.md` — stan realizacji
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne
- Filament 5, strona zasobu `FisheryResource` (jak `ManageCalendar`); tabela przez `ManageRelatedRecords` lub
  własna strona — decyzja w `/review-task`.
- Mail anulacji używa layoutu z zadania 040 (zależność).

## Rozstrzygnięcia
- **Zasób i autoryzacja:** powstaje `ReservationResource` (zasób podrzędny łowiska, ukryty w głównej nawigacji, wzorzec
  `AvailabilityBlockResource`), bo polityka odpowiada zasobowi (`autoryzacja.md` §5) i Shield generuje uprawnienia
  `*:reservation` z zasobu. Lista (B), formularz (C) i szczegóły (D) to strony tego zasobu, wejście z sub-nawigacji
  łowiska „Rezerwacje". Dwie warstwy zawężenia z §4 (zakres zapytania + bramka `fishery_id` przy zapisie) obowiązują.
  Zasób rejestrowany w obu panelach, tak jak zasoby zawężane przez `scopeToOwnedFisheries`.
- **Kto może anulować/edytować:** każdy z dostępem do rezerwacji (właściciel łowiska, admin); bez rozróżnienia uprawnień (§5).
- **Mail anulacji** (treść w tym zadaniu, na szablonie 040): powód, komunikat operatora, informacja o pełnym zwrocie
  („nie pobieramy opłaty", etap 3), kontakt do łowiska. Błąd wysyłki nie blokuje anulacji — rezerwacja trafia do „Do kontaktu".
- **Akcje zbiorcze listy:** potwierdź, oznacz płatność, anuluj — wszystkie przez serwisy z 039, rekord po rekordzie z autoryzacją.
- **Zwrot „z warunków":** w etapie 3 nie ma płatności online — anulacja opłaconej rezerwacji ustawia `payment_status = refund`;
  wyjątek od polityki zwrotu zapisywany w `reservation_terms` (ADR-026, aktualizacja 10.10).
- Wysyłka maila po zapisie rezerwacji (potwierdzenie z tokenami) dochodzi w 043, nie tutaj.

## Powiązane ADR-y
- [ADR-024](../../adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md),
  [ADR-026](../../adr/ADR-026-zamrozenie-warunkow-rezerwacji.md),
  [ADR-027](../../adr/ADR-027-kolizje-konfiguracji-z-rezerwacjami.md) — rozstrzygnięte.
