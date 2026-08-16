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

### Bezpieczeństwo

- Logowanie przez Google i Facebook wymaga teraz kodu z drugiego składnika, tak samo jak
  logowanie hasłem — wcześniej pomijało ten krok w całości.
- Panel właściciela wymaga teraz kodu z drugiego składnika przy każdym logowaniu; wcześniej
  dało się pominąć ten krok i wejść wprost pod adres wewnątrz panelu.
- Właściciel łowiska nie zobaczy już nazwy cudzego łowiska w ścieżce nawigacji ani w tytule
  strony, gdy poda w adresie identyfikator, który do niego nie należy.
- Właściciel nie może już otworzyć ani zmienić cudzego łowiska i cudzej firmy — wcześniej
  chroniło to tylko filtrowanie list.
- Do galerii i mapy łowiska można teraz wgrać wyłącznie JPEG, PNG i WebP; wcześniej przechodził
  też plik SVG, który potrafi nieść skrypt.
- Dziennik zmian przestał zapisywać zaszyfrowane hasło i kod jednorazowy użytkownika.
- Zamknięto trzy sposoby, na jakie właściciel łowiska mógł sięgnąć poza własne dane: wyświetlić
  listę stanowisk, usług dodatkowych lub pozwoleń **cudzego** łowiska, dodać do niego nowy wpis
  oraz przenieść do niego swój istniejący wpis przez edycję. Widoczność i zapis są teraz
  ograniczone do łowisk, których użytkownik jest właścicielem, niezależnie od tego, co przyjdzie
  z przeglądarki.
- Uruchomienie testów nie może już przypadkowo skasować roboczej bazy danych — pakiet testów
  korzysta z osobnej bazy, a przebieg zatrzymuje się z czytelnym komunikatem, gdyby kiedykolwiek
  wskazał inną.
- Przygotowano aplikację do pracy za proxy terminującym szyfrowanie (wdrożenie docelowe) —
  strony i panele będą poprawnie wykrywać połączenie szyfrowane, bez ryzyka podmiany adresu
  w linkach z wiadomości e-mail.
- Ciasteczko sesji jest teraz oznaczane jako wysyłane wyłącznie po połączeniu szyfrowanym
  na środowisku docelowym.

### Poprawione

- Naprawiono podgląd mapy w formularzu łowiska — nie dawało się go pokazać. Mapa pojawia się
  teraz sama, gdy adres jest kompletny, i nadąża za zmianami w polach adresu.
- Naprawiono dodawanie usługi dodatkowej — zapis formularza kończył się błędem aplikacji
  zamiast utworzeniem usługi.
- Naprawiono dodawanie stanowiska: formularz startował z jedną pustą pozycją usługi dodatkowej,
  przez co stanowiska bez usług nie dawało się zapisać bez ręcznego usunięcia tego wiersza.
- Po założeniu łowiska użytkownik trafia teraz od razu do zarządzania nim (stanowiska,
  pozwolenia, usługi), zamiast wracać na listę łowisk.
- Naprawiono kreator zakładania łowiska w panelu właściciela: wybór firmy renderował się jako
  surowy, niestylowany element, a wybrana firma potrafiła zniknąć w drodze do kolejnego kroku.
  Kreator jest teraz jednym formularzem z krokami — wybrana firma nie gubi się między nimi,
  a krok z przelewem weryfikacyjnym pomija się, gdy firma była już zweryfikowana.
- Naprawiono wygląd strony „Zarządzaj łowiskiem" — zakładki i przyciski dodawania renderowały
  się jako wielkie, niestylowane ikony z rozsypaną nawigacją.
- W zakładkach zarządzania łowiskiem wróciły przyciski edycji przy pozycjach na listach —
  po przebudowie strony dało się rekordy tylko oglądać i dodawać.
- Strona „Zarządzaj łowiskiem" otwiera się teraz na danych łowiska, a nie na jednej z list.
  Listy pozwoleń, usług dodatkowych i stanowisk widać od razu po wejściu w zakładkę — wcześniej
  były schowane za dodatkowym przyciskiem „Lista". Z zakładki z danymi można przejść do edycji
  łowiska jednym przyciskiem.
- Zapisanie stanowiska, usługi dodatkowej lub pozwolenia odsyła teraz na listę w zarządzaniu
  łowiskiem — z widoczną nazwą łowiska i pozostałymi zakładkami — zamiast na osobną listę
  wyrwaną z kontekstu. Dotyczy zarówno dodawania, jak i edycji.
- Na stronach stanowisk, usług dodatkowych i pozwoleń ścieżka nawigacji prowadzi teraz przez
  „Łowiska → nazwę łowiska → sekcję", a dwa pierwsze elementy są klikalne — wcześniej z listy
  stanowisk nie było jak wrócić do konkretnego łowiska.
- Naprawiono zakładanie użytkownika w panelu administratora — formularz nie miał pola hasła,
  przez co zapis nowego użytkownika kończył się błędem i konta nie dało się utworzyć.
  Przy edycji istniejącego użytkownika pole hasła można zostawić puste, żeby nie zmieniać
  dotychczasowego.
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
