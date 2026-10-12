# 046 — Publiczna dostępność w portalu: stan „Zajęte" w kalendarzu

## Opis problemu
Kalendarz portalu (033) pokazuje ceny i zasady, ale nie zajętość — dziś łowisko „potwierdza wolne terminy
telefonicznie". Po etapie 3 system zna prawdziwą zajętość, więc wędkarz może ją zobaczyć, o ile łowisko
to włączy. Specyfikacja: README makiet, ekran I; replika układu portalu w `etap3.html`.

## Wymagania
- Kalendarz portalu (`PortalCalendar`, `portal/partials/calendar*`) pokazuje stan komórki **„Zajęte"**
  dla doby objętej rezerwacją — wyłącznie na podstawie `PositionAvailability` (powód `Occupied`);
  widok niczego nie liczy sam.
- Styl: neutralny szary, **bez kreskowania** (kreskowanie zostaje dla „niedostępne — powód od łowiska");
  legenda pokazuje „Zajęte" tylko, gdy występuje w widocznym tygodniu; ten sam stan w widoku mobilnym (lista dnia).
- **Prywatność:** żadnych danych o rezerwującym ani numerów rezerwacji; niepotwierdzone rezerwacje liczą się
  jako zajęte (wniosek W10, akceptowane ryzyko).
- Ustawienie łowiska `public_availability_enabled` (z 045): wyłączone = dotychczasowy komunikat „Łowisko
  potwierdzi wolne terminy telefonicznie"; włączone = stan „Zajęte" i zmieniony komunikat.
- Wybór stanowiska/dnia w portalu (cena, rozbicie) działa jak dotąd.
- Pamięć podręczna fragmentów kalendarza (jeśli jest) unieważniana przy zmianie rezerwacji.

## Kryteria akceptacji
- [x] Przy włączonym ustawieniu zajęta doba pokazuje „Zajęte"; przy wyłączonym — jak dotąd (test).
- [x] Widok nie ujawnia danych rezerwującego (test na wyjściu HTML i fragmencie `X-Portal-Fragment`).
- [x] Legenda i widok mobilny obsługują nowy stan.
- [x] Testy 033 i portalu zielone; brak dodatkowych zapytań rosnących z liczbą dób (test).
- [x] Warunek uruchomienia opisany w dokumentacji: obsługa wpisuje każdą rezerwację telefoniczną.

## Zakres testów
- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="PortalCalendar|Portal"`
- **Uzasadnienie:** zmiana kontraktu `PortalCalendar` i widoków portalu; brak migracji (ustawienie z 045).
  Pełny pakiet odroczony na koniec sesji.

## Zakres wyłączeń
- Liczba wolnych stanowisk w karcie łowiska na stronie głównej (Q2).
- Koszyk, rezerwacja i płatność przez wędkarza (etap 4).
- Klikalna mapa stanowisk.

## Zmiany dokumentacji
- [x] `docs/conventions/strona-publiczna.md` — stan „Zajęte" i zasada prywatności
- [x] `docs/project/mockups/portal-v3/README.md` — odsyłacz do stanu „Zajęte"
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne
- Zależność: 039 (`Occupied`), 045 (ustawienie).
- Tailwind 4 / motyw portalu; kolory tylko z `portal.css`.

## Rozstrzygnięcia
- **Pamięć podręczna:** jeśli kalendarz portalu nie ma dziś cache'u fragmentów, nic się nie dokłada; jeśli ma —
  klucz uwzględnia ustawienie i znacznik ostatniej zmiany rezerwacji łowiska (bez ręcznego unieważniania).
- **Pakiet stanowisk zajęty w części = zajęty w całości** (makieta I) — stan wynika z `PositionAvailability`, widok go nie liczy.
- **Blokada** pokazuje powód tylko przy `reason_visible` (jak dziś); „Zajęte" nigdy nie niesie powodu ani danych rezerwującego.
- Przy wyłączonym `public_availability_enabled` kalendarz zachowuje się jak dziś — także gdy w bazie są rezerwacje
  (zajętość nie wycieka do widoku przy wyłączonym ustawieniu).
- Nowe teksty PL/EN w plikach `lang`; kolory wyłącznie z `portal.css`.

## Powiązane ADR-y
- [ADR-012](../../adr/ADR-012-jedno-zrodlo-prawdy-o-dostepnosci.md),
  [ADR-025](../../adr/ADR-025-zajetosc-stanowiska-jako-sloty-przedzialowe.md) — rozstrzygnięte.
