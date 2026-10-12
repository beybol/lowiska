# Opisy procesów

Opis procesu pokazuje **jeden przepływ od początku do końca**: kto co robi, w jakiej kolejności system sprawdza
reguły i co z tego wynika. Rozwiązuje problem rozproszenia: jeden proces bywa opisany w kilku zadaniach,
ADR-ach i plikach konwencji naraz.

**Dla kogo:** pierwsza część każdego opisu jest dla osób biznesowych — krótka, bez nazw technicznych.
Diagram (Mermaid, renderuje się w GitHubie i w podglądzie Markdowna) pokazuje przepływ. Na końcu jest krótka
sekcja „Dla zespołu" ze wskazaniem, gdzie proces żyje w kodzie i skąd pochodzą decyzje.

**Czym różni się od konwencji:** plik konwencji (`docs/conventions/`) mówi, **jakie reguły obowiązują**.
Opis procesu mówi, **w jakiej kolejności się je stosuje i co z tego wynika**. Decyzje i ich uzasadnienia
zostają w ADR-ach.

**Kiedy powstaje i kiedy się zmienia:** po implementacji albo zmianie procesu — tylko dla obszarów kluczowych,
złożonych albo rozrzuconych po wielu dokumentach. Zadanie, które zmienia opisany tu proces, aktualizuje jego
opis (pozycja w „Zmianach dokumentacji" zadania). Opis przedstawia stan obowiązujący, nie historię.

| # | Proces | Obszar |
|---|---|---|
| 1 | [Potwierdzenie rezerwacji i połączenie z kontem](01-potwierdzenie-rezerwacji-i-konto.md) | wędkarz, mail, konto |
| 2 | [Tworzenie rezerwacji i kolejność reguł](02-tworzenie-rezerwacji.md) | panel łowiska, dostępność |
| 3 | [Cykl życia rezerwacji](03-cykl-zycia-rezerwacji.md) | statusy, wygasanie, płatność, anulacja |
| 4 | [Kolizje zmian konfiguracji z rezerwacjami](04-kolizje-zmian-konfiguracji.md) | blokady, wycofanie stanowiska |
| 5 | [Logowanie i zakładanie konta](05-logowanie-i-zakladanie-konta.md) | hasło, Google, kod, zgody |
| 6 | [Wycena pobytu](06-wycena-pobytu.md) | cennik, dopłaty, przedsprzedaż, usługi |
