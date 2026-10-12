# 040 — Spójny szablon maili

## Opis problemu
W aplikacji istnieje jeden własny mail (`resources/views/emails/verify-email.blade.php`, styl inline) i jedna
notyfikacja (`SendTwoFactorCode`). Etap 3 dodaje kolejne wiadomości — prośba o potwierdzenie rezerwacji,
anulacja z komunikatem łowiska, potem w etapie 4 potwierdzenia zamówień. Bez wspólnego szablonu każda
będzie wyglądać inaczej i trzeba będzie ją przerabiać osobno. Zadanie dostarcza jeden layout i zestaw
komponentów, na których stoją maile z zadań 041 i 043.

## Wymagania
- Wspólny layout HTML maila zgodny z klientami pocztowymi (tabele, style inline, szerokość ~600 px,
  odporny na tryb ciemny tam, gdzie klient to wspiera): nagłówek z marką Fisherya, miejsce na nazwę łowiska
  (nadawca merytoryczny), treść, stopka (kontakt, wyjaśnienie, dlaczego dostajesz tę wiadomość).
- Komponenty Blade: przycisk główny, przycisk drugorzędny, ramka informacyjna/ostrzegawcza, blok
  „szczegóły rezerwacji" (tabela etykieta–wartość), akapit z komunikatem łowiska.
- Wariant tekstowy każdej wiadomości (multipart) — wspólny sposób budowania.
- Paleta i typografia zgodne z „Głębią" (portal) w granicach tego, co działa w pocztach.
- Przepięcie istniejącego `verify-email` na nowy layout (spójność od pierwszego dnia); `SendTwoFactorCode`
  — sprawdzić, czy używa domyślnego szablonu Laravela, i ujednolicić.
- Wspólny `Mailable` bazowy (lub trait) z nagłówkami, tematem z prefiksem i obsługą języka z `PortalLocale`.
- Podgląd wiadomości bez wysyłki: trasa lub komenda deweloperska renderująca każdą wiadomość z danymi
  przykładowymi (dla przeglądu wyglądu), niedostępna na produkcji.
- Teksty w angielskim (kod/klucze) z tłumaczeniem PL w plikach `lang` — jak reszta aplikacji.

## Kryteria akceptacji
- [x] Layout i komponenty istnieją, a `verify-email` używa ich bez zmiany treści.
- [x] Każda wiadomość ma wariant tekstowy; test renderu sprawdza oba warianty.
- [x] Podgląd renderuje wszystkie istniejące maile; na produkcji trasa/komenda niedostępna (test).
- [ ] Wygląd w Gmail (web) i Outlook — **ręcznie po serii** (notatka autora), nie blokuje implementacji.
- [x] Nowa wiadomość = klasa `Mailable` + widok na komponentach, bez kopiowania stylów (przykład w dokumentacji).

## Zakres testów
- **Tier:** T1
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="Mail"`
- **Uzasadnienie:** nowe widoki, komponenty i klasy bazowe, bez migracji i bez dotykania wyzwalaczy T3.
  Przepięcie `verify-email` obejmują testy logowania/rejestracji, więc dodać je do filtra, jeśli istnieją.
  Pełny pakiet odroczony na koniec sesji.

## Zakres wyłączeń
- Treści wiadomości etapu 3 (prośba o potwierdzenie, anulacja) — zadania 041 i 043 używają szablonu.
- Wysyłka masowa, kolejka, szablony edytowalne przez łowisko, personalizacja logo łowiska.
- Dostawca poczty i konfiguracja SMTP (poza zakresem; `MAIL_MAILER` bez zmian).

## Zmiany dokumentacji
- [x] `docs/conventions/integracje.md` — zasada „maile tylko przez layout i komponenty"
- [x] `docs/project/mockups/etap3/README.md` — wzór maili G zgodny z layoutem
- [x] `CHANGELOG.md` — wpis w changelogu

## Ograniczenia techniczne
- Laravel 13 `Mailable`/Markdown lub własny Blade — decyzja w `/review-task`; komponenty muszą działać w
  klientach pocztowych (bez Flexbox/Grid, bez zewnętrznych arkuszy).
- Obrazy (logo) osadzone ze stabilnego adresu aplikacji, nie z `localhost` (środowiska lokalne).

## Rozstrzygnięcia
- **Technika:** własny layout Blade + anonimowe komponenty (`resources/views/components/mail/*`), nie Markdown Mailable
  Laravela — potrzebna pełna kontrola nad tabelami, stylami inline i paletą „Głębi". Wariant tekstowy: osobny widok
  `*.text.blade.php` obok HTML (jeden helper bazowy wiąże oba w `Mailable`).
- **Podgląd:** trasa deweloperska `/dev/mail-preview/{mail}` rejestrowana wyłącznie w środowiskach `local` i `testing`
  (nie na produkcji i stagingu); test sprawdza 404 poza tymi środowiskami.
- **Sprawdzenie w Gmail/Outlook** to czynność ręczna, której nie wykona implementacja nocna: kryterium przechodzi do
  listy „po serii" (notatka w zadaniu uzupełniana przez autora); zielone testy renderu go nie zastępują.
- Logo: adres z `config('app.url')`, nie z żądania.
- Zadanie nie zależy od 039; wykonywane zaraz po nim wyłącznie ze względu na numerację.

## Powiązane ADR-y
- Brak (rozstrzygnięcia punktowe, bez ADR).
