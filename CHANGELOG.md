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

### Dodane

- Właściciel łowiska może teraz opisać, czym handluje: na nowym ekranie „Sprzedaż i sezony"
  ustawia godzinę rozpoczęcia i zakończenia doby wędkarskiej oraz strefę czasową, w której ta
  doba jest liczona. Doba trwa od godziny do godziny dnia następnego, więc przechodzi przez
  północ — a w weekend zmiany czasu jest wciąż jedną dobą, choć trwa o godzinę krócej
  albo dłużej.
- Na tym samym ekranie właściciel wyznacza okresy, w których łowisko sprzedaje. Poza nimi nie
  da się kupić nic, nawet gdy stanowisko jest wolne, a łowisko bez ani jednego okresu nie
  sprzedaje wcale. Okresy nie mogą na siebie zachodzić ani kończyć się przed swoim początkiem —
  sprzeczność wychodzi przy zapisie, a nie dopiero u wędkarza.
- Odmowa sprzedaży mówi teraz, dlaczego termin jest niedostępny: czy wypada przed sezonem,
  po nim, czy łowisko nie ma jeszcze ustawionej sprzedaży. Ostatnie pozwolenie na jedną dobę
  kupuje się na przedostatni dzień okresu, bo doba rozpoczęta ostatniego dnia kończyłaby się
  już po jego zamknięciu.
- Stanowisko ma teraz pojemność: maksymalną liczbę wędkujących i maksymalną liczbę osób
  łącznie z niełowiącymi. Liczba wędkujących jest wymagana przy zapisie, a liczba osób nie może
  być od niej mniejsza.
- Administrator prowadzi wspólny dla całego portalu słownik cech stanowisk — tak/nie, liczba
  z jednostką albo wybór z listy. Właściciel wypełnia te cechy na swoich stanowiskach, a formularz
  sam pokazuje każdą cechę dopisaną do słownika. Cecha pozostawiona pusta znaczy „nie wiadomo",
  a nie „nie ma".
- Właściciel może zakładać grupy stanowisk i przypisywać do nich stanowiska — jedno stanowisko
  może należeć do wielu grup naraz. Grupa nosi własny opis: dojazd, charakterystykę brzegu
  i wszystko, czego nie da się zapisać jedną cechą.
- Cechę da się ustawić wielu stanowiskom naraz: zaznacza się je na liście albo wywołuje działanie
  z poziomu grupy. Przed zapisem widać, ilu stanowisk dotyczy.
- Właściciel łowiska może zablokować sprzedaż na wybranych stanowiskach w zadanym terminie —
  na całym łowisku, w grupie, na stanowiskach z daną cechą albo wskazanych ręcznie — z powodem
  i decyzją, czy wędkarz ten powód zobaczy. Termin może być otwarty, „do odwołania". Doba, która
  wchodzi w blokadę choćby częścią, jest niesprzedawalna.
- Osobno od blokady da się na czas określony zawiesić jedną cechę tak/nie stanowiska (np. pomost
  albo wjazd) bez zmiany jej wartości — po upływie terminu wszystko wraca samo. Stanowisko
  z zawieszoną cechą nadal się sprzedaje, tylko bez niej.
- Lista stanowisk objętych wpisem jest przeliczana z wybranego kryterium i można ją poprawić
  ręcznie przed zapisem. Zapisana lista nie zmienia się sama: stanowisko dodane później nie
  wchodzi do istniejącej blokady, a panel ostrzega o tym przy jego zakładaniu, wskazując wpisy,
  które go nie obejmują.
- Właściciel łowiska może ograniczyć, **jaki pobyt** wolno u niego kupić. Na nowym ekranie
  „Reguły sprzedaży" ustawia najkrótszy i najdłuższy pobyt liczony w dobach; puste pole znaczy
  „bez granicy". Łowisko bez żadnej z tych reguł sprzedaje pobyty dowolnej długości.
- Weekend można sprzedawać wyłącznie w całości. Właściciel zaznacza doby, które idą razem — każda
  pokazana jako pobyt od godziny do godziny („pt 15:00 → sob 15:00"), więc widać, że weekend od
  piątku do niedzieli to dwie doby, a nie trzy dni. Wędkarz nie kupi samej soboty: musi wziąć cały
  weekend, sam albo z dobami przed nim i po nim. Doba niedzielna sprzedaje się jak zwykły dzień.
- Terminy świąteczne z konkretnymi datami też można sprzedawać wyłącznie w całości. Przy każdym
  widać wyliczony pobyt („czw 30.04 15:00 → nd 3.05 15:00 · 3 doby"), żeby nie pomylić ostatniej
  doby z dniem wyjazdu. Święta mogą na siebie zachodzić i zachodzić na weekend — zlewają się wtedy
  w jeden większy pakiet, który również sprzedaje się w całości.
- Pobyt obejmujący święto nie podlega najkrótszej długości pobytu: trzydobowa majówka sprzedaje się
  także tam, gdzie zwykle wymagane jest pięć dób. Sam weekend takiego wyjątku nie daje.
- Gdy blokada wyłączy część dób pakietu, resztę nadal można kupić — wyłączone doby po prostu z niego
  wypadają, zamiast unieważniać cały weekend czy całe święto.
- Każdy okres sprzedaży może mieć własną przedsprzedaż: okno, w którym wolno kupować doby tego
  sezonu, zanim wejdzie on w normalną sprzedaż. Właściciel może wymagać, żeby zakup w oknie obejmował
  co najmniej zadaną liczbę dób — wymaganie dotyczy każdego zakupu dób tego sezonu w czasie otwartego
  okna, więc pojedynczej doby wtedy nie kupi. Osobny przełącznik decyduje, czy święta sprzedawane
  w całości są z tego wymagania wyjęte; domyślnie są.
- Właściciel ustawia horyzont sprzedaży — jak daleko w przód wędkarz może kupować. Doba
  rozpoczynająca się dokładnie tyle dni od dziś jeszcze się sprzedaje. Okno przedsprzedaży jest
  wyjątkiem właśnie od horyzontu: pozwala kupić doby dalej, niż on sięga.
- Odmowa sprzedaży pobytu mówi wprost, co jest nie tak: pobyt za krótki, za długi, przerwany
  weekend, przerwane święto, data poza horyzontem albo zakup poniżej minimum przedsprzedaży.
  Przy przerwanym pakiecie wędkarz widzi pełny zakres dób, które musi objąć.
- Panel ostrzega przy zapisie o konfiguracji, której nie da się kupić albo która prawie na pewno
  jest pomyłką: najkrótszy pobyt dłuższy niż weekend, najdłuższy pobyt krótszy niż pakiet
  sprzedawany w całości, okno przedsprzedaży otwierające się po starcie sezonu, przedsprzedaż
  na łowisku bez horyzontu oraz święto, które po skróceniu sezonu wypadło poza sprzedaż. Zapis
  przechodzi — porządkowanie sezonu w dowolnej kolejności zostaje możliwe.
- Właściciel łowiska ustala **ceny**: na nowym ekranie „Cennik" prowadzi dwie listy — stawki
  i dopłaty. Stawka zastępuje cenę doby, dopłata się do niej dodaje, a wszystkie pasujące dopłaty
  sumują się.
- Stawka ma tylko kwoty i daty: kwotę za osobę łowiącą, kwotę za osobę towarzyszącą oraz okres,
  od kiedy i do kiedy obowiązuje. Większości łowisk wystarczy jedna taka stawka i jedna dopłata.
- Wszystkie warunki niesie dopłata: zakres dat, wybrane doby tygodnia, „tylko przy obsadzie N"
  oraz **dla kogo** ma się naliczyć. To dopłatą liczy się więcej za weekend — cena bazowa zostaje
  wtedy widoczna jako cena bazowa, a różnica jako różnica.
- Warunek liczy się osobno dla każdej doby, więc dopłata „czwartek–niedziela" przy pobycie od środy
  do piątku nalicza się za czwartek i piątek, a nie za cały pobyt ani za nic.
- Dopłata może obciążać samych łowiących (domyślnie), wszystkich uczestników albo same osoby
  towarzyszące. „Tylko przy obsadzie N" liczy przy tym samych łowiących, więc dopłata za wyłączność
  stanowiska nie znika przez to, że wędkarz przyjechał z kimś.
- Gdy do doby pasuje kilka stawek, wygrywa **tańsza dla wędkarza**. Zapis przechodzi bez błędu —
  to, co naprawdę wychodzi w cenie każdej doby, pokaże kalendarz podglądowy.
- Nowy cennik wprowadza się stawką z datą „obowiązuje od" i pustym „obowiązuje do". Poprzednia
  stawka **domyka się sama** na dzień wcześniej, a właściciel dostaje o tym powiadomienie.
  Stawka z obiema datami niczego nie domyka — jest wstawką w istniejący cennik.
- Regułę można **zawiesić** zamiast usuwać — zostaje w cenniku i wraca jednym kliknięciem.
- Osoba towarzysząca wycenia się drugą kwotą na tej samej stawce: 0,00 zł znaczy „za darmo",
  a puste pole znaczy, że doby nie da się sprzedać nikomu, kto przyjeżdża z osobą towarzyszącą.
- Zakup w otwartym oknie przedsprzedaży może być tańszy: przy okresie sprzedaży ustawia się
  procentową **obniżkę ceny**. Zdejmuje się ją od każdej doby osobno, od stawki wraz z dopłatami,
  i wędkarz widzi ją przy każdej dobie.
- Doba w otwartym sezonie, do której nie pasuje żadna stawka, jest **niesprzedawalna** — nie
  darmowa. Wędkarz dostaje wtedy czytelną odmowę, a operator ostrzeżenie już przy zapisie cennika,
  ze wskazaniem doby, dla której stawki zabrakło. Osobny komunikat mówi, gdy stawka istnieje,
  ale nie ma kwoty za osobę towarzyszącą — bo wtedy poprawia się jedno pole, a nie dopisuje regułę.
- Odpowiedź „czy tę ofertę da się kupić i ile kosztuje" powstaje w jednym miejscu, razem
  z rozbiciem ceny na doby i osoby. To ono zasili kalendarz podglądowy i przyszły koszyk, więc
  wędkarz i operator zobaczą tę samą kwotę i to samo uzasadnienie.
- Właściciel ma **kalendarz podglądowy**: nowy ekran „Kalendarz" pokazuje siatkę stanowisk i dób,
  a w każdej komórce — czy pobyt da się kupić, po jakiej cenie i za ile dób. To jedyny ekran
  konfiguracji, na którym niczego się nie wypełnia: pokazuje skutek wszystkich pozostałych.
- Widok otwiera się na **właściwym sezonie**, a nie na „najbliższych 30 dniach": gdy dziś jest poza
  sezonem, kalendarz przeskakuje na początek najbliższego przyszłego. Okno przesuwa się o miesiąc
  albo tydzień, w granicach wybranego sezonu.
- Kontrolki nad siatką pozwalają policzyć ofertę dla **dowolnego składu**: liczby łowiących, liczby
  osób towarzyszących i zadanej długości pobytu. Domyślnie pokazujemy „ceny od" — jeden łowiący
  i najkrótszy pobyt, jaki wolno kupić.
- W kalendarzu widać wreszcie rzeczy, których nie pokazywał żaden formularz: **zlewanie
  i przycinanie pakietów** (doba w środku pakietu mówi, od której doby zacząć), złożenie ceny ze
  stawki i dopłat oraz dziury w cenniku.
- Stanowisko wycofane ze sprzedaży zajmuje wiersz **jednym komunikatem**, zamiast trzydziestu razy
  powtarzać tę samą przyczynę.
- Podpowiedź przy zablokowanej dobie podaje **zasięg blokady** — ile stanowisk objęła i po czym je
  wybrano. To najłatwiejszy do przeoczenia błąd konfiguracji: blokada na piętnaście miejsc zamiast
  pięciu.
- Kalendarz pokazuje też **bałagan w cenniku**: licznik przy dobie, do której pasuje więcej niż
  jedna stawka (także gdy mają identyczne kwoty), oraz listę stawek, które nie wygrywają nigdzie
  w swoim okresie.
- Łowisko bez godzin doby, bez okresu sprzedaży albo bez stanowisk dostaje **komunikat, czego
  brakuje i dokąd pójść**, zamiast siatki samych odmów.
- Stawka w cenniku może mieć **nazwę**, np. „Cennik 2026". Nazwa jest opcjonalna i nie zmienia
  ceny — pokazuje się w nagłówku wiersza, w powiadomieniu o domknięciu poprzedniej stawki,
  w rozbiciu ceny i na kalendarzu. Stawka bez nazwy nadal pokazuje się kwotą.
- Usługa dodatkowa ma **jednostkę rozliczenia**: „za dobę" (cena razy liczba dób pobytu i liczba
  egzemplarzy — łódka, hamak, postawienie przyczepy) albo „za pobyt" (cena razy liczba egzemplarzy —
  pellet, drewno). Lista usług pokazuje cenę z jednostką, np. „20,00 zł / doba".
- Usługa może być **bezpłatna**: cena 0,00 pokazuje się jako „bezpłatna", a usługa nadal ma limit
  egzemplarzy.
- Usługa ma pole **„Dostępna na"**: całe łowisko (każde stanowisko, także dodane później) albo
  wybrane stanowiska. Usługa „wybrane stanowiska", której nie przypięto nigdzie, jest niedostępna
  i formularz oraz lista o tym ostrzegają. Zmiana na „całe łowisko" odpina usługę od stanowisk —
  po potwierdzeniu z podaną liczbą przypięć.
- Usługę można powiązać z **wymaganymi cechami stanowiska** typu tak/nie, np. przyczepę z wjazdem
  pojazdem. Na stanowisku, które takiej cechy nie ma, nie ma jej wypełnionej albo ma ją zawieszoną
  ograniczeniem, usługa jest niedostępna — w przypadku ograniczenia tylko na jego czas.
- Działania **„Przypnij usługę"** i **„Odepnij usługę"** na liście stanowisk i przy każdej grupie
  stanowisk: usługa trafia na wszystkie zaznaczone stanowiska naraz (od razu z oznaczeniem
  „obowiązkowa" albo bez), a pozostałe usługi tych stanowisk zostają bez zmian. Po przypięciu widać,
  na ilu stanowiskach usługa jest martwa z powodu brakującej cechy.
- Kalendarz pokazuje przy każdym stanowisku **plakietkę z liczbą jego usług**; gdy któraś jest
  w pokazywanym okresie niedostępna, plakietka zmienia kolor i dostaje dopisek „1 niedostępna".
  Po najechaniu widać listę usług z ceną, oznaczeniem „obowiązkowa", liczbą egzemplarzy i — przy
  niedostępnej — przyczyną wraz z datami ograniczenia. Ceny w komórkach kalendarza się nie zmieniły.

### Zmienione

- Liczba egzemplarzy usługi dodatkowej znaczy teraz liczbę sztuk dostępnych **w każdej dobie**.
  Brak limitu zaznacza się pustym polem — wpisane 0 nie oznacza już „bez limitu", a istniejące
  usługi z zerem dostały limit pusty.
- Na formularzu stanowiska do przypięcia są już tylko usługi dostępne na wybranych stanowiskach —
  usługa dostępna na całym łowisku jest na nim zawsze i nie da się jej oznaczyć jako obowiązkowej.

- Lista stawek, które nigdy nie wygrywają, opisuje teraz każdą z nich **nazwą, kwotą z walutą
  i zakresem dat** zamiast samej kwoty w rodzaju „90.00" — po tym łatwo trafić do właściwego
  wiersza w cenniku.
- Doby tygodnia przy dopłacie i przy weekendzie sprzedawanym w całości wybiera się teraz tak samo:
  siedmioma przyciskami, z których każdy pokazuje dobę od dnia do dnia i jej godziny, a pod nimi
  jest podsumowanie wyboru. Wcześniej weekend miał listę pól do zaznaczenia, a dopłata same nazwy
  dni, bez informacji, że chodzi o doby. Przy dopłacie przyciski działają także bez ustawionych
  godzin doby.

- Ekran „Sprzedaż i sezony" odpowiada teraz na pytanie, **kiedy** łowisko sprzedaje: doszły do niego
  przedsprzedaż przy każdym okresie oraz horyzont sprzedaży. Reguły mówiące, **jaki pobyt** wolno
  kupić, mieszkają na osobnym ekranie „Reguły sprzedaży" — jedno pytanie na ekran.

- Stanowisko ma teraz stan „w sprzedaży" albo „wycofane" zamiast przełącznika aktywności.
  Liczniki i listy liczą po nowym stanie.
- Odmowa sprzedaży rozróżnia teraz także „stanowisko wycofane" i „sprzedaż w tym dniu
  zablokowana". Gdy zachodzi kilka przyczyn naraz, wędkarz widzi tę najtrwalszą — wycofanie
  stanowiska przed sezonem, sezon przed blokadą.
- Zarządzanie łowiskiem ma teraz jedno, wspólne menu boczne zamiast zakładek: dane łowiska,
  stanowiska, grupy, sprzedaż i sezony, blokady, usługi dodatkowe i pozwolenia są w nim obok
  siebie, każde z własnym adresem. Menu towarzyszy właścicielowi także na formularzu edycji
  łowiska, więc nie trzeba się z niego cofać. Liczniki przy pozycjach zostały bez zmian.
- Menu panelu administratora jest uporządkowane: na wierzchu zostały Panel, Firmy i Łowiska,
  a pozostałe pozycje trafiły do dwóch grup — „Słowniki" (udogodnienia, rodzaje łowisk, metody
  łowienia, ryby, cechy stanowisk, kraje, województwa, waluty) i „Dostępy" (użytkownicy, role).
  Boczne menu da się zwinąć, żeby zrobić miejsce formularzom.
- Nazwa stanowiska nie może się powtórzyć w obrębie jednego łowiska — także wtedy, gdy stanowisko
  o tej nazwie zostało wcześniej wycofane. Wcześniej wycofana nazwa wracała do obiegu i historia
  pozwoleń mogła wskazywać na dwa różne miejsca o tej samej etykiecie.
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

- Nagłówek dopłaty w cenniku poprawnie pokazuje doby, które nie idą po kolei — dopłata na poniedziałek
  i środę widnieje jako „pon, śr", a nie jako „pon–śr", które sugerowało także wtorek.
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
