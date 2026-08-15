# Log zmian

Wszystkie istotne zmiany w projekcie dokumentowane są w tym pliku.

Format bazuje na [keepachangelog 1.0.0 (PL)](https://keepachangelog.com/pl/1.0.0/),
a projekt stosuje [wersjonowanie semantyczne](https://semver.org/lang/pl/).

Wpisy opisują zmianę **z perspektywy użytkownika, w czasie przeszłym** — bez nazw klas, numerów
zadań i szczegółów implementacji. Utrzymuje ten plik skill `changelog`, wołany na końcu
`/implement-task`.

## [unreleased]

<!-- Kolejne wpisy trafiają tutaj, pogrupowane w sekcje:
     ### Dodane / ### Zmienione / ### Poprawione / ### Usunięte / ### Wycofane / ### Bezpieczeństwo
     Przy wydaniu sekcja `[unreleased]` zamienia się w `## [X.Y.Z] - RRRR-MM-DD`. -->

### Zmienione

- Środowisko deweloperskie stawia się teraz jednym poleceniem `docker compose up --build` —
  razem z bazą danych, serwerem zasobów i skrzynką pocztową, bez potrzeby instalowania czegokolwiek
  na komputerze poza Dockerem. Aplikacja odpowiada pod adresem `http://localhost:11000`,
  a wiadomości wysyłane przez aplikację trafiają do skrzynki pod `http://localhost:11025`.

### Poprawione

- Naprawiono odczyt klucza do rejestru GUS oraz adresu administratora, które po zbudowaniu
  pamięci podręcznej konfiguracji na produkcji przestawałyby działać, uniemożliwiając wyszukiwanie
  firmy po NIP-ie oraz wyświetlanie danych kontaktowych w komunikacie o zajętej firmie.
- Naprawiono import listy krajów w panelu administratora, który po wdrożeniu docelowym
  przyjmowałby plik bez błędu, ale nigdy by go nie przetworzył — import wykonuje się teraz
  do końca w tym samym żądaniu.

### Bezpieczeństwo

- Uruchomienie testów nie może już przypadkowo skasować roboczej bazy danych — pakiet testów
  korzysta z osobnej bazy, a przebieg zatrzymuje się z czytelnym komunikatem, gdyby kiedykolwiek
  wskazał inną.
- Przygotowano aplikację do pracy za proxy terminującym szyfrowanie (wdrożenie docelowe) —
  strony i panele będą poprawnie wykrywać połączenie szyfrowane, bez ryzyka podmiany adresu
  w linkach z wiadomości e-mail.
