# 045 — Ustawienia rezerwacji łowiska i wygasanie niepotwierdzonych

## Opis problemu
ADR-024: łowisko może wymagać potwierdzenia rezerwacji ręcznej — niepotwierdzona rezerwacja wygasa po oknie
w godzinach. Potrzebne są ustawienia łowiska, wyliczanie `confirm_by` i mechanizm, który wygaszone rezerwacje
faktycznie zwalnia. Na Cloud Run nie ma workera kolejki (ADR-004), więc wygaszanie wymaga zaplanowanego
wywołania. Specyfikacja: README makiet, ekran F, wnioski W1 i W7.

## Wymagania
- Kolumny ustawień łowiska: `manual_reservation_confirmation_required` (domyślnie wyłączone),
  `manual_reservation_hold_hours`, `public_availability_enabled` (używane w 046).
- Sekcja „Rezerwacje" na stronie „Sprzedaż i sezony": dwa przełączniki i pole okna w godzinach (domyślnie 48),
  z walidacją (okno wymagane przy włączonym potwierdzaniu).
- Przy zakładaniu rezerwacji (041) ustawianie `confirm_by` = teraz + okno, gdy potwierdzanie włączone.
- **Wygaszanie:** komenda Artisan wyszukuje rezerwacje po `confirm_by` i zmienia status na wygasłą,
  zwalniając sloty (przez serwis z 039); idempotentna, bezpieczna przy równoległym uruchomieniu; zapis w
  kanale `reservations`.
- Harmonogram: wywołanie komendy co minutę/pięć minut w środowisku Cloud Run (Cloud Scheduler → endpoint lub
  zadanie) — sposób uruchamiania do ustalenia w `/review-task`; lokalnie `schedule:work`/`schedule:run`.
- Potwierdzenie rezerwacji (przez operatora lub wędkarza, 043) kasuje `confirm_by`.
- Mail o wygaśnięciu do wędkarza z adresem (layout z 040) — decyzja w `/review-task` (czy wysyłać).
- Plakietka „niepotwierdzone ≤ 24 h" w nawigacji liczy się z `confirm_by`.

## Kryteria akceptacji
- [x] Przełączniki i okno zapisują się, walidacja działa, domyślnie potwierdzanie wyłączone.
- [x] Rezerwacja ręczna dostaje `confirm_by` tylko przy włączonym ustawieniu.
- [x] Komenda wygasza rezerwacje po terminie i zwalnia sloty; ponowne uruchomienie nic nie zmienia (test).
- [x] Potwierdzona rezerwacja nie wygasa.
- [x] Harmonogram udokumentowany i działa na środowisku docelowym (lub opisany krok uruchomienia).
- [x] Pełny pakiet testów (T3 — migracja).

## Zakres testów
- **Tier:** T3
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** migracja kolumn ustawień łowiska (obowiązkowy wyzwalacz T3) i nowy harmonogram.

## Zakres wyłączeń
- Powiadomienia przypominające przed wygaśnięciem (poza etapem 3).
- Blokada czasowa zamówienia online i płatności (etap 4).
- Same przełączniki widoczności kalendarza w portalu — logika w 046.

## Zmiany dokumentacji
- [x] `docs/conventions/panel-wlasciciela.md` — sekcja ustawień rezerwacji
- [x] `docs/operations/` — sposób uruchamiania harmonogramu na Cloud Run
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne
- Brak workera (ADR-004); wygaszanie przez wywołanie harmonogramu, nie kolejkę.
- Strefa czasowa łowiska (024) dla wyświetlania terminów; w bazie UTC.

## Rozstrzygnięcia
- **Mail o wygaśnięciu (autor, 10.10): wysyłamy**, gdy rezerwacja ma adres e-mail; treść z makiety G.3 na szablonie 040;
  błąd wysyłki nie blokuje wygaszania (rezerwacja bez adresu lub z błędem → bez maila).
- **Uruchamianie harmonogramu:** komenda `reservations:expire` zarejestrowana w `routes/console.php` co 5 minut
  (lokalnie `schedule:work`). Na Cloud Run **bez endpointu HTTP** (brak nowej powierzchni ataku): Cloud Scheduler uruchamia
  zadanie Cloud Run Job z poleceniem `php artisan schedule:run` (lub bezpośrednio komendą). Implementacja dostarcza komendę,
  rejestrację w schedulerze i opis konfiguracji w `docs/operations/`; **utworzenie zadania w GCP to krok ręczny autora**
  (kryterium harmonogramu spełnia się opisem kroku uruchomienia).
- **Bezpieczeństwo równoległości:** wygaszanie rekord po rekordzie w transakcji z `lockForUpdate` i ponownym sprawdzeniem
  statusu/`confirm_by`; zwolnienie slotów przez serwis z 039.
- **Plakietka „≤ 24 h"** liczona jednym zapytaniem agregującym po `confirm_by` (z „Do kontaktu" z 041), bez N+1.
- **Domyślne okno:** 48 h; walidacja: całkowita 1–720 godzin, wymagana tylko przy włączonym potwierdzaniu.
- `public_availability_enabled` — kolumna i przełącznik w tym zadaniu (formularz „Sprzedaż i sezony"), logika w 046.
- **Zmiana ustawień nie przepisuje `confirm_by` istniejących rezerwacji** (zasada „konfiguracja żyje, transakcja zamrożona").

## Powiązane ADR-y
- [ADR-024](../../adr/ADR-024-wedkarz-jako-tozsamosc-globalna-i-relacja-z-lowiskiem.md),
  ADR-004 — rozstrzygnięte.
