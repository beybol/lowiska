# 044 — Kolizje konfiguracji z rezerwacjami: blokada i wycofanie stanowiska

## Opis problemu
Blokada dostępności albo wycofanie stanowiska może wypaść na termin z już sprzedaną rezerwacją. Zasada G8:
kolizja wymaga jawnej decyzji operatora, a nie cichego nadpisania. Decyzje:
[ADR-027](../../adr/ADR-027-kolizje-konfiguracji-z-rezerwacjami.md) (usługa kolizji, dwie decyzje, komunikat,
„Do kontaktu", zwrot jako status). Specyfikacja: README makiet, ekran E.

## Wymagania
- **Jedna usługa kolizji konfiguracji**: dla zmiany (w etapie 3: blokada, wycofanie stanowiska) wylicza
  dotknięte rezerwacje — **przyszłe, jeszcze nie rozpoczęte**; pobytów w toku nie dotyka (nie trafiają na listę).
  Zbiór decyzji zależy od typu zmiany; mechanizm ma być użyty ponownie przez kolejne zmiany konfiguracji.
- **Ekran decyzji (E)** po zapisie blokady/wycofania, gdy są kolizje: tabela rezerwacji, decyzja per wiersz
  i zbiorczo — **„anuluj z pełnym zwrotem"** albo **„zostaw"** (bez „przenieś"); wspólny, opcjonalny
  komunikat do wędkarzy (indywidualny i zbiorczy), włączany do maila anulacji (layout z 040).
- Anulacja: zwalnia sloty, ustawia status płatności „zwrot" (bez rejestru „do zwrotu"), zapis powodu, kto i
  kiedy w rezerwacji i kanale `reservations`; rezerwacje bez e-maila trafiają na „Do kontaktu".
- „Zostaw": rezerwacja zostaje na zablokowanym terminie jako **wyjątek** zapisany w terms (z ostrzeżeniem w UI).
- Rezerwacja na zablokowanym stanowisku przez operatora/admina dozwolona z ostrzeżeniem (ADR-027 pkt 7);
  zajęty slot nadal niemożliwy; kanał wędkarza nigdy nie omija blokady.
- Zawieszenie cechy (`attribute_suspended`) tylko informacyjnie (bez wymuszania decyzji).
- Wysyłka wielu maili w jednym żądaniu: ustalić limit i zachowanie przy przekroczeniu (Q6, `sync` bez workera).

## Kryteria akceptacji
- [x] Zapis blokady kolidującej z rezerwacjami przekierowuje do ekranu decyzji; bez kolizji zapisuje się jak dotąd.
- [x] Pobyty w toku nie występują na liście kolizji (test).
- [x] Decyzja „anuluj" zwalnia sloty, ustawia „zwrot", wysyła mail z komunikatem (lub „Do kontaktu" bez e-maila).
- [x] Decyzja „zostaw" zapisuje wyjątek; rezerwacja widoczna w kalendarzu z oznaczeniem.
- [x] Decyzja zbiorcza i indywidualna dają ten sam skutek dla pojedynczej rezerwacji (test).
- [x] Wycofanie stanowiska korzysta z tej samej usługi z innym zbiorem decyzji (test).
- [x] Zachowanie przy przekroczeniu limitu maili opisane i przetestowane.

## Zakres testów
- **Tier:** T2
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="Collision|Block|Reservation"`
- **Uzasadnienie:** nowa usługa wołana przez ekrany blokad i stanowisk (016) oraz serwis rezerwacji (039);
  brak migracji (kolumny anulowania w 039). Pełny pakiet odroczony na koniec sesji.

## Zakres wyłączeń
- Automatyczne „przenieś na inne stanowisko".
- Kolizje innych zmian konfiguracji (godziny doby, okresy sprzedaży) — mechanizm gotowy do użycia, bez wdrożenia.
- Faktyczne zwroty płatności online i rozliczenie niewykorzystanych dób pobytu w toku (etap 4).
- Klauzula „zamknięcie łowiska bez rekompensaty" (prawnik, TODO-3 pkt 4).

## Zmiany dokumentacji
- [x] `docs/conventions/dostepnosc.md` — blokada a rezerwacje
- [x] `docs/project/ZASADY-PROJEKTOWE.md` — G8 (jeśli wdrożenie coś doprecyzuje)
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne
- Zależność: 039, 040, 041 (modal anulacji i mail).
- Operacja w jednej transakcji; blokada wiersza stanowiska jak w ścieżce zapisu z 039.

## Rozstrzygnięcia
- **Q6 (autor, 10.10):** **bez limitu maili, wysyłka sekwencyjna** w jednym żądaniu. Świadome ryzyko: przy dużej liczbie
  rezerwacji żądanie może zbliżyć się do limitu czasu Cloud Run. Zabezpieczenia w zakresie zadania: (1) cała zmiana i
  anulacje zapisują się w transakcji **przed** wysyłką; (2) błąd wysyłki pojedynczego maila nie przerywa pętli, a rezerwacja
  trafia do „Do kontaktu"; (3) ekran wyniku raportuje, ilu wędkarzom wysłano mail, a ilu nie. Ewentualny limit/kolejka —
  osobne zadanie, jeśli ryzyko się zmaterializuje.
- **Nazwa usługi:** `ConfigurationCollisionService` w `app/Services/`; opis zmiany jako obiekt wartości (rodzaj + parametry);
  zbiór decyzji z enuma zależnego od rodzaju zmiany.
- **Ekran decyzji (E):** strona zasobu blokad/stanowisk po zapisie; zapis blokady i decyzje to jedna transakcja (ADR-027 pkt 3),
  więc blokada jest zapisywana dopiero po zatwierdzeniu decyzji, a nie przed ekranem.
- **„Zostaw":** rezerwacja zostaje, wyjątek w `reservation_terms` (jak w 039).
- Anulacja korzysta z tego samego serwisu anulacji co modal z 041 (jedna ścieżka).

## Powiązane ADR-y
- [ADR-027](../../adr/ADR-027-kolizje-konfiguracji-z-rezerwacjami.md) — rozstrzygnięty.
