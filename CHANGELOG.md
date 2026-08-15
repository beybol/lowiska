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

- Dodanie nowego rekordu w panelu administratora (m.in. udogodnienia, kraje, waluty, rodzaje
  i metody łowienia, ryby, stany, użytkownicy, usługi dodatkowe, stanowiska) wraca teraz od razu
  na listę zamiast przechodzić do widoku edycji dopiero co utworzonego rekordu.
- Aplikacja działa teraz na nowszym wydaniu frameworka, panelu administracyjnego oraz języka PHP.
  Dla korzystających z aplikacji nic nie zmienia się w wyglądzie ani w sposobie pracy — panele
  administratora i właściciela pokazują te same dane i te same możliwości co wcześniej.
- Środowisko deweloperskie stawia się teraz jednym poleceniem `docker compose up --build` —
  razem z bazą danych, serwerem zasobów i skrzynką pocztową, bez potrzeby instalowania czegokolwiek
  na komputerze poza Dockerem. Aplikacja odpowiada pod adresem `http://localhost:11000`,
  a wiadomości wysyłane przez aplikację trafiają do skrzynki pod `http://localhost:11025`.

### Poprawione

- Naprawiono podniesienie wersji dziennika zmian: aktualizacja wewnętrznej biblioteki
  odpowiadającej za rejestrowanie kto i co zmienił w danych — bez zauważalnej zmiany dla
  korzystających z aplikacji.
- Naprawiono formularz rejestracji w panelu, który przestał pokazywać pola nazwiska, prefiksu
  kraju i telefonu — strona otwierała się normalnie, więc brak tych pól nie rzucał się w oczy.
- Naprawiono formularz firmy w panelu właściciela: strona dodawania firmy przestawała się
  otwierać zamiast wyświetlić komunikat o błędzie pobierania danych z rejestru GUS.
- Naprawiono zakładanie konta administratora, które dawało dostęp do logowania, ale panel
  administracyjny wyglądał na pusty — bez żadnej pozycji nawigacji ani zasobu. Komenda zakładająca
  konto nadaje teraz od razu pełne uprawnienia; uruchomiona ponownie na istniejącym koncie
  uzupełnia brakujące uprawnienia, nie tworząc duplikatów.
- Naprawiono odczyt klucza do rejestru GUS oraz adresu administratora, które po zbudowaniu
  pamięci podręcznej konfiguracji na produkcji przestawałyby działać, uniemożliwiając wyszukiwanie
  firmy po NIP-ie oraz wyświetlanie danych kontaktowych w komunikacie o zajętej firmie.
- Naprawiono import listy krajów w panelu administratora, który po wdrożeniu docelowym
  przyjmowałby plik bez błędu, ale nigdy by go nie przetworzył — import wykonuje się teraz
  do końca w tym samym żądaniu.
- Naprawiono zapis mapy i galerii zdjęć łowiska w panelu administratora, który po wdrożeniu
  docelowym przyjmowałby plik bez błędu, ale obrazek przestawałby się otwierać po najbliższym
  restarcie — uploady trafiają teraz na trwały storage, a aplikacja odmawia startu, gdyby to
  ustawienie zostało przez pomyłkę pominięte.

### Bezpieczeństwo

- Uruchomienie testów nie może już przypadkowo skasować roboczej bazy danych — pakiet testów
  korzysta z osobnej bazy, a przebieg zatrzymuje się z czytelnym komunikatem, gdyby kiedykolwiek
  wskazał inną.
- Przygotowano aplikację do pracy za proxy terminującym szyfrowanie (wdrożenie docelowe) —
  strony i panele będą poprawnie wykrywać połączenie szyfrowane, bez ryzyka podmiany adresu
  w linkach z wiadomości e-mail.
