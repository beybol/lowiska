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

### Poprawione

- Naprawiono odczyt klucza do rejestru GUS oraz adresu administratora, które po zbudowaniu
  pamięci podręcznej konfiguracji na produkcji przestawałyby działać, uniemożliwiając wyszukiwanie
  firmy po NIP-ie oraz wyświetlanie danych kontaktowych w komunikacie o zajętej firmie.
