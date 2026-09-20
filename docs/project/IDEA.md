# Fisherya — system obsługi łowisk dla operatorów i system sprzedaży dostępu do nich dla wędkarzy

## 1 Idea ogólna

System powstaje z obserwacji słabej digitalizacji i realnych problemów świata wędkarzy i łowisk komercyjnych.
W Polsce i na świecie jest coraz więcej łowisk zarządzanych przez firmy lub osoby prywatne i udostępnianych wędkarzom na zasadach komercyjnych.
Kupowanie pozwoleń na łowienie na takich łowiskach odbywa się jednak najczęściej analogowo i polega na kupowaniu ich telefonicznie, w przydrożnym sklepie lub mailowo.
Co więcej, bardzo często nie wiadomo, czy miejsce na łowisku będzie wolne, bo nie ma nigdzie on-line dostępu do kalendarza łowiska.
Pojawiła się więc idea, żeby wzorem Ubera czy Glovo — które rozwiązały uciążliwości związane z zamawianiem taksówek (nieznana cena i czas przyjazdu) czy jedzenia (brak dostępu do menu i brak informacji o czasie dostarczenia) — zbudować aplikację, która zagospodarowałaby branżę wędkarską.

Produkt ma dwie strony rynku i obie muszą działać od pierwszego dnia:

- **Dla wędkarza**: jedno konto, jedna wyszukiwarka, realna dostępność stanowisk i zakup online zamiast telefonu.
- **Dla łowiska**: darmowe narzędzie do prowadzenia biznesu (kalendarz, cennik, rozliczenia), które przy okazji przynosi nowych klientów.

Zasada przewodnia przy projektowaniu: **łowiska bardzo się od siebie różnią** (karpiowe, pstrągowe, mieszane, sumowe, „na godziny", turnusowe, sezonowe). System nie narzuca jednego modelu sprzedaży — daje operatorowi zestaw konfigurowalnych reguł i pilnuje, żeby domyślne ustawienia były sensowne dla najczęstszego przypadku.

## 2 Słownik pojęć

- **Łowisko**: jezioro lub staw zarządzane przez firmę lub osobę, która udostępnia je na zasadach komercyjnych dla wędkarzy.
- **Wędkarz**: osoba łowiąca ryby, chcąca korzystać z Łowiska.
- **Klient**: właściciel Łowiska (podmiot, z którym Fisherya ma relację handlową).
- **Operator**: właściciel Łowiska lub jego pracownik korzystający z systemu w celu zarządzania łowiskiem.
- **Firma**: podmiot gospodarczy zarejestrowany w systemie i zweryfikowany, do którego przypisane są Łowiska i na którego konto trafiają płatności.
- **Stanowisko**: wyznaczone miejsce do łowienia na danym łowisku (np. konkretny pomost, plaża, zatoczka lub wejście do wody). Ma własne atrybuty — dojazd autem, parking, miejsce do wodowania łodzi, prąd, miejsce na namiot, odległość do toalety — bo wędkarz pyta o konkretne miejsce, a nie o cały akwen (patrz [ANKIETA-WNIOSKI.md](ANKIETA-WNIOSKI.md), 4.1).
- **Pozwolenie**: uprawnienie nabywane komercyjnie, pozwalające na korzystanie z Łowiska i/lub Stanowiska.
- **Pozwolenie długookresowe**: Pozwolenie na sezon lub kilka miesięcy. Pozwala na korzystanie z Łowiska w okresie, który obejmuje. Nie gwarantuje wolnego Stanowiska.
- **Pozwolenie jednorazowe**: Pozwolenie na godziny lub dni, zawsze powiązane z konkretnym Stanowiskiem.
- **Rezerwacja**: zamówienie konkretnego Stanowiska — element nierozłączny dla Pozwoleń jednorazowych. Może być też tworzona za opłatą lub bez w przypadku korzystania z Łowiska w oparciu o Pozwolenie długookresowe — Wędkarz ma prawo łowienia na danym Łowisku, ale rezerwuje sobie konkretny dzień i konkretne Stanowisko.
- **Okres sprzedaży** (dawniej: jednostka czasu): definiowany przez Operatora sposób sprzedaży czasu na Stanowisku — doba wędkarska od stałej godziny, przedział godzinowy, turnus o stałych ramach. ⚠️ **Stan na dziś (zrealizowane zadanie 015):** Łowisko ma **jeden** tryb sprzedaży — dobę od stałej godziny, liczoną w strefie czasowej Łowiska. Kilka typów naraz pozostaje kierunkiem rozwoju, nie stanem systemu.
- **Uczestnik**: osoba objęta Rezerwacją, w roli **łowiącego** lub **osoby towarzyszącej**. Rola wpływa na cenę.
- **Usługa dodatkowa**: każda usługa, która może uzupełniać ofertę Łowiska i która może być dosprzedana do Pozwolenia lub Rezerwacji. Przykłady: namiot, łódka, grill.
- **Blokada**: wpis Operatora zajmujący Stanowisko lub całe Łowisko bez sprzedaży (zawody, zarybianie, konserwacja, urlop). ⚠️ **Stan na dziś (zrealizowane zadanie 016):** blokada i ograniczenie to **jeden wpis z polem skutku** — wyłączenie sprzedaży albo zawieszenie jednej cechy typu tak/nie. Wskazuje dowolny zbiór Stanowisk (całe Łowisko, grupa, wybór po cesze, ręczne zaznaczenie), zapisany jako lista konkretnych Stanowisk, niesie powód i decyzję, czy Wędkarz ten powód widzi. Data końcowa jest opcjonalna.
- **Rezerwacja offline**: Rezerwacja wprowadzona ręcznie przez Operatora dla klienta spoza systemu (telefon, gotówka na miejscu). Nie generuje prowizji.
- **Administrator**: pracownik Fisherya.com zarządzający portalem.

Role nie są rozłączne. **Jedno konto może pełnić kilka ról naraz** — ta sama osoba może być Wędkarzem, Operatorem swojego łowiska i Administratorem portalu. Konto jest jedno, a role są zbiorem uprawnień przypisanym do niego; interfejs przełącza kontekst (panel wędkarza / panel operatora / panel administratora), a nie wymusza osobnych loginów.

## 3 Podstawowe procesy

### 3.1 Zakładanie/rejestracja Łowiska

Właściciel lub Operator Łowiska tworzy swoje indywidualne konto w systemie.
Konto pozwala mu na dodanie firmy poprzez wyszukanie jej w bazie GUS i autoryzację.
Autoryzacja musi zapewnić, że osoba rejestrująca daną firmę jest jej przedstawicielem.
W zależności od operatora płatności i funkcji, które on udostępni, autoryzacja może być automatyczna lub w oparciu o przelew z konta firmy na kwotę 1 zł na konto Fisherya.com (w takim przypadku administrator systemu autoryzuje firmę ręcznie).
Po autoryzacji użytkownika może on zdefiniować ofertę Łowiska i rozpocząć sprzedaż Pozwoleń, Rezerwacji i usług dodatkowych.

Uwaga do wersji globalnej: baza GUS jest rozwiązaniem lokalnym dla Polski. Model danych zakłada wymienny **dostawcę weryfikacji firmy per kraj**, a przelew weryfikacyjny pozostaje uniwersalną ścieżką awaryjną.

### 3.2 Dostęp pracowników Łowiska

Właściciel zaprasza pracowników mailem do swojej Firmy. Zaproszony zakłada własne konto — konta nie są współdzielone, bo każda operacja w kalendarzu i w rozliczeniach musi mieć autora.

Role (docelowo, zakres uprawnień do doprecyzowania przy projektowaniu):

- **Właściciel** — pełny dostęp, w tym dane firmy, cennik, rozliczenia, zapraszanie pracowników.
- **Manager** — zarządzanie ofertą i kalendarzem, bez dostępu do danych rozliczeniowych firmy.
- **Obsługa** — kalendarz, lista przyjazdów na dziś, rezerwacje offline; bez cennika i rozliczeń.

Właściciel i pracownicy Łowiska mogą jednocześnie korzystać z portalu jako Wędkarze na tym samym koncie — w tym kupować na cudzych łowiskach.

### 3.3 Wyszukiwanie łowisk

Na stronie głównej portalu Wędkarz może wyszukać z mapy lub z listy przez wyszukiwarkę tekstową łowiska, które mogą go interesować.

Filtry wyszukiwania korzystają ze słowników już obecnych w systemie: typ łowiska, gatunki ryb, metody połowu, udogodnienia, lokalizacja (kraj/województwo, odległość od punktu), oraz — najważniejsze — **dostępność w wybranym terminie**.

Część filtrów musi działać **na poziomie Stanowiska, nie Łowiska**. „Łowisko z parkingiem" i „stanowisko, pod które dojadę autem" to dwie różne rzeczy, a w ankiecie wśród wędkarzy dojazd, parking i miejsce do wodowania okazały się najczęstszą trudnością przy wyborze łowiska — częstszą niż rybostan.

Każde Łowisko ma publiczną, indeksowalną stronę pod własnym adresem. To główny kanał pozyskania ruchu: wędkarz szuka w Google konkretnego łowiska po nazwie, a nie portalu.

### 3.4 Konto Wędkarza

Wędkarz może założyć sobie konto, które będzie pozwalało na kupowanie Pozwoleń, Rezerwacji i Usług dodatkowych na wybranych Łowiskach.

**Konto jest wymagane do zakupu, ale nie do przeglądania.** Wędkarz może swobodnie przeglądać ofertę, sprawdzać dostępność i skompletować koszyk bez logowania — rejestracja lub logowanie następuje dopiero przy finalizacji zamówienia, a zawartość koszyka jest wtedy zachowywana.

### 3.5 Zakup

1. Wędkarz wybiera Łowisko, typ Pozwolenia i termin.
2. Dla Pozwolenia jednorazowego wybiera Stanowisko z kalendarza dostępności.
3. Deklaruje Uczestników (łowiący / osoby towarzyszące) — od tego zależy cena.
4. Dodaje Usługi dodatkowe.
5. Akceptuje regulamin Łowiska (wersjonowany — system zapamiętuje, którą wersję zaakceptowano) oraz regulamin portalu.
6. Loguje się lub zakłada konto.
7. Płaci; środki dzielą się automatycznie (patrz sekcja 5).
8. Otrzymuje potwierdzenie z kodem Rezerwacji; Operator dostaje powiadomienie.

### 3.6 Anulowanie i zwrot

Łowisko definiuje **okna czasowe anulacji** i przypisany im procent zwrotu (np. 100% do 7 dni przed, 50% do 48 godzin, 0% później). Wędkarz widzi te zasady przed zakupem.

**Zwrot obejmuje również prowizję Fisherya** — przy anulacji wędkarz odzyskuje pełną kwotę należną wg polityki łowiska, a Fisherya oddaje swoją część. Konsekwencja do rozstrzygnięcia z dostawcą płatności: opłaty transakcyjne PSP zwykle nie są zwracane, więc trzeba ustalić, kto je pokrywa.

Odwołanie przez Łowisko (pogoda, awaria, zamknięcie) oznacza zawsze pełny zwrot niezależnie od okna czasowego.

### 3.7 Rezerwacja offline

Operator może wpisać do kalendarza Rezerwację klienta spoza systemu (telefon, gotówka na miejscu) oraz Blokady. **Od rezerwacji offline nie jest pobierana prowizja.**

To jest decyzja świadoma i kluczowa dla wiarygodności produktu: jeśli wpisanie rezerwacji telefonicznej kosztowałoby łowisko prowizję, łowisko prowadziłoby drugi kalendarz na kartce, a portal pokazywałby wędkarzom fałszywą dostępność. Wartość systemu polega na tym, że kalendarz w Fisherya jest jedynym źródłem prawdy.

## 4 Główne funkcje

### 4.1 Zarządzanie Łowiskiem

Operator może zarządzać jednym lub wieloma łowiskami zarządzanymi przez jedną lub wiele firm.

W ramach zarządzania Łowiskiem jego Operator może opisać łowisko i jego parametry, dodać zdjęcia itp.
Kluczowe jest jednak zdefiniowanie oferty Pozwoleń (długookresowych i/lub jednorazowych), Stanowisk (z mapą) i Usług dodatkowych.

Operator może również zarządzać Rezerwacjami (za pomocą kalendarza pokazującego w kolumnach kolejne dni tygodnia/miesiąca, a w wierszach Stanowiska).

Operator widzi również stan rozliczeń — liczbę i wartość opłaconych Rezerwacji i wykupionych Pozwoleń.

Operator może też zarządzać Wędkarzami korzystającymi z jego Łowisk.

### 4.2 Oferta, okresy sprzedaży i cennik

Model sprzedaży jest **konfigurowany per Łowisko**. Operator definiuje jeden lub kilka typów Okresu sprzedaży:

- **doba wędkarska** — od stałej godziny do stałej godziny (np. 7:00–7:00), sprzedawana w wielokrotnościach;
- **przedział godzinowy** — wędkarz wybiera zakres godzin (typowe dla łowisk pstrągowych);
- **turnus** — gotowe sloty wystawiane przez Operatora (np. pt 16:00 – nd 12:00).

⚠️ **Stan na dziś (zrealizowane zadanie 015):** zbudowana jest wyłącznie **doba wędkarska**, a tryb
sprzedaży jest polem Łowiska — pozostałe typy dokłada się wartością, nie przebudową. Sprzedaż
w wielokrotnościach (reguły długości pobytu) jest osobnym zakresem i jeszcze nie powstała.

Cena jest naliczana **za każdą osobę**, przy czym:

- stawka zależy od **roli Uczestnika** — łowiący płaci więcej, osoba towarzysząca mniej (możliwa stawka zerowa);
- kolejne osoby mogą być tańsze (cennik progowy);
- Operator ustala maksymalną liczbę osób na Stanowisku.

Dodatkowe wymiary cennika do obsłużenia: sezonowość, dni tygodnia (weekend vs dzień roboczy), dopłata za dodatkową wędkę, kody rabatowe.

Reguły dostępności ustalane przez Operatora: horyzont rezerwacji (jak daleko w przód można kupić), godzina odcięcia sprzedaży na dziś, minimalna i maksymalna długość pobytu.

**Domyślnie odcięcie sprzedaży ma być liberalne.** Ankieta pokazała, że decyzja o wyjeździe bywa spontaniczna, a największą przeszkodą są zamknięte punkty sprzedaży („punkty zakupu zezwoleń są zamknięte", „dziwne sklepiki czynne od 8", „dostępność, 24/h"). Wędkarz kupujący w piątek o 22:00 nie jest przypadkiem brzegowym, tylko istotną częścią rynku — a możliwość obsłużenia go jest jedną z niewielu rzeczy, których telefon nie potrafi.

### 4.3 Pozwolenia długookresowe a Rezerwacje

Relacja Pozwolenia długookresowego do Rezerwacji jest **konfigurowana przez Łowisko**, bo praktyka rynkowa jest tu bardzo różna. System musi obsłużyć wszystkie warianty:

1. Rezerwacja darmowa i bez limitu.
2. Rezerwacja darmowa z limitem ilościowym (np. najwyżej 2 aktywne rezerwacje naraz albo X dni w miesiącu).
3. Rezerwacja darmowa do limitu, płatna po jego przekroczeniu.
4. Rezerwacja zawsze dodatkowo płatna (zwykle wg obniżonego cennika).

Pozwolenie długookresowe jest imienne i nieprzenoszalne.

### 4.4 Funkcje Wędkarza

Wędkarz może wyszukać Łowisko na stronie systemu i zapoznać się z jego ofertą.

Wędkarz może założyć konto w systemie i za jego pomocą może kupować Pozwolenia i Rezerwacje na dowolnym Łowisku, które jest zarządzane w systemie.
Wędkarz widzi w widoku kalendarza dostępność Stanowisk na wybranym Łowisku.

Wędkarz widzi wszystkie swoje historyczne Rezerwacje i wykupione Pozwolenia oraz aktualne Pozwolenia długookresowe wraz z pozostałym limitem rezerwacji.

### 4.5 Powiadomienia

Minimalny zestaw zdarzeń: potwierdzenie zakupu, przypomnienie przed przyjazdem, anulowanie (przez wędkarza i przez łowisko), zbliżający się koniec Pozwolenia długookresowego, nowa rezerwacja u Operatora. Kanał podstawowy: e-mail. SMS i push — poza MVP.

### 4.6 Lista oczekujących na zwolnione Stanowisko

Wędkarz, który nie znalazł wolnego Stanowiska w interesującym go terminie, może zapisać się na powiadomienie: „daj znać, gdy zwolni się miejsce na sobotę". Gdy Rezerwacja zostanie anulowana albo Operator zdejmie Blokadę, system powiadamia oczekujących.

Jest to funkcja **w zakresie MVP** i jest ważniejsza, niż wygląda:

- dla Wędkarza to rzecz, której telefon nie potrafi — nikt nie oddzwoni, gdy komuś wypadnie wyjazd;
- dla Łowiska zamienia anulacje w sprzedaż tego samego terminu, co bezpośrednio łagodzi koszt polityki pełnych zwrotów (3.6);
- dla portalu jest to najmocniejszy powód, żeby Wędkarz założył konto, zanim cokolwiek kupi.

Kolejność powiadamiania i czas na reakcję (np. pierwszeństwo przez określony czas dla pierwszej osoby z listy) wymagają doprecyzowania przy projektowaniu.

## 5 Model biznesowy

Portal ma zarabiać na prowizji od wszystkich operacji zakupu realizowanych przez Wędkarzy.
Obsługa rezerwacji w panelu Operatora nie wymaga płatności — system dla Łowisk jest darmowy i pozwala zarządzać nimi bez ograniczeń.
Przychód jest realizowany wyłącznie jako procent od płatności realizowanych w systemie przez Wędkarzy.

### 5.1 Przepływ pieniędzy

Sprzedaż odbywa się wyłącznie za **pełną płatnością z góry** — jak bilet do kina. Nie ma zadatków, dopłat na miejscu ani płatności odroczonych. Upraszcza to rozliczenia, zwroty i split payment, a Łowisku daje pewność środków przed przyjazdem Wędkarza.

Model docelowy to **split payment**: wpłata Wędkarza jest dzielona po stronie operatora płatności — kwota netto trafia bezpośrednio na konto Łowiska, a prowizja operatora płatności i prowizja Fisherya.com są potrącane automatycznie.

Konsekwencje, które trzeba przyjąć świadomie:

- każda Firma przechodzi proces weryfikacji (KYC) u dostawcy płatności — to dodatkowy krok w onboardingu (patrz 3.1) i realna bariera wejścia dla najmniejszych łowisk;
- do czasu zakończenia weryfikacji Łowisko może działać w systemie, ale nie może sprzedawać;
- Fisherya nie przechowuje środków klientów, co upraszcza sytuację regulacyjną.

Prowizja wynosi **10%**, z czego 1–2 punkty procentowe pochłania dostawca płatności. **Wędkarz prowizji nie widzi** — cena w portalu jest taka sama jak na stronie łowiska.

Kryteria wyboru dostawcy płatności, w kolejności ważności:

1. **Obsługa BLIK-a.** Jest jedną z trzech najczęstszych metod płatności wśród respondentów ankiety i wymieniana po imieniu jako oczekiwanie. Dostawca bez BLIK-a odpada bez dyskusji.
2. **Przyjazność procesu KYC** dla jednoosobowej działalności — a najlepiej możliwość przyjmowania płatności z wypłatą wstrzymaną do zakończenia weryfikacji.
3. Obsługa modelu marketplace ze split payment.
4. Koszt transakcji, bo przy prowizji 10% każdy punkt procentowy dostawcy to jedna dziesiąta przychodu.

**Do rozstrzygnięcia**: konkretny dostawca (kandydaci na rynku polskim: Stripe Connect, PayU, Przelewy24, Autopay, Tpay).

### 5.2 Dokumenty i rozliczenia

- Sprzedawcą usługi dla Wędkarza jest **Łowisko** — to ono odpowiada za dokument sprzedaży dla wędkarza. System dostarcza dane transakcyjne potrzebne do jego wystawienia.
- Fisherya wystawia Łowisku **zbiorczą fakturę za prowizję raz w miesiącu**, obejmującą wszystkie transakcje z danego okresu.
- Proces fakturowania prowizji ma być automatyczny i **zintegrowany z KSeF**.

### 5.3 Rezerwacje bez prowizji

Rezerwacje offline (3.7) i Blokady nie generują przychodu. Jest to celowa inwestycja w kompletność kalendarza — bez niej dane o dostępności są niewiarygodne, a to jest główna obietnica produktu wobec wędkarza.

## 6 Analiza konkurencji

Stan rozpoznania: sierpień 2026. Dane o skali pochodzą z deklaracji na stronach graczy i nie są zweryfikowane niezależnie.

### 6.1 Rynek polski

**Marketplace'y rezerwacji łowisk — konkurencja bezpośrednia**

| Gracz | Model | Skala (deklarowana) | Uwagi |
|---|---|---|---|
| [fish.do](https://fish.do/pl-pl) | Marketplace, prowizja | 500+ łowisk | Wyszukiwarka wg województwa/gatunku/stylu, kalendarz stanowisk, płatność online, opinie. Stawka prowizji nieujawniona publicznie. Brak informacji o pozwoleniach sezonowych i usługach dodatkowych. |
| [zasiadki.pl](https://zasiadki.pl/) | Marketplace, prowizja od łowiska („0 zł dla wędkarza"), 3 miesiące bez prowizji na start | 300+ łowisk, **74 zarejestrowanych wędkarzy** | Model niemal identyczny z Fisherya. Dysproporcja podaży do popytu pokazuje główny problem tej kategorii: łowisko łatwo dodać do katalogu, trudno przyprowadzić wędkarzy. |
| ryblo.com | Marketplace | — | **Nie działa**, domena wystawiona na sprzedaż. Dowód, że ten model już raz w Polsce poległ. |
| znajdzlowisko.pl, rezerwujlowisko.pl | Katalog/marketplace | niepotwierdzone | Nie udało się pobrać treści; rezerwujlowisko.pl ma podstrony PL/Litwa/Włochy. |

**SaaS dla łowisk — konkurencja o panel operatora**

| Gracz | Model | Uwagi |
|---|---|---|
| [LakeControl](https://lakecontrol.pl/) | Abonament **od 99 zł/mies.**, bez prowizji, pakiety wg liczby stanowisk | Kalendarz stanowisk, mapa/ortofotomapa z numeracją, płatności (BLIK, P24, karty), doby z elastycznymi cenami, sprzedaż dodatków (pellet, łódka, pomost), RODO. Brak warstwy odkrywania — klient musi już znać łowisko. Deklarowane 3 łowiska pilotażowe. |
| [Fishitly](https://fishitly.com/) | niepotwierdzony | „Program do zarządzania łowiskiem". Cennik niedostępny (403) — do zweryfikowania. |
| [EasyFishing / BookingFish](https://easyfishing.pl/bookingfish/) | SaaS + obsługa „pod klucz" | Działa od 2015, deklaruje łowiska w 10 krajach Europy. Obsługuje też czartery, przewodników, pobyty. Skala i model przychodowy nieujawnione. |
| [e-rezerwacje24.pl](https://www.e-rezerwacje24.pl/) | Generyczny silnik rezerwacji (od 2008) | Sprzedawany łowiskom jako widget kalendarza na własną stronę. Pokazuje, że część „autorskich" systemów łowisk stoi na wspólnym silniku. |

**Sprzedaż zezwoleń — sąsiedni segment**

- **e-zezwolenia okręgów PZW** (Kraków, Wrocław, Zielona Góra, Szczecin i inne) oraz białoetykietowy produkt **ezezwolenie.pl** ([wedkarzonline.pl](https://wedkarzonline.pl/)) dla gospodarstw rybackich: panel, płatności online, moduł kontrolera do weryfikacji ważności zezwolenia na miejscu. **Brak kalendarza rezerwacji stanowisk** — i to jest ich strukturalna luka.
- **[WIR – system Wód Polskich](https://wir.wody.gov.pl/uslugi/zakup-zezwolenia-na-amatorski-polow-ryb/)**: zezwolenia 1-, 3-, 7-, 14-, 30-dniowe i sezonowe na wody Skarbu Państwa. Inny segment (wody publiczne), ale ważny — przyzwyczaja wędkarza do płacenia za wędkowanie online.
- **[Zimorodek](https://zimorodek.pl/)** — agregator zezwoleń z aplikacją mobilną i największym zasięgiem instalacyjnym na rynku. Wymaga osobnego omówienia niżej.

**Zimorodek — baza wiedzy o wodach, nie kanał sprzedaży**

| Wymiar | Stan (rozpoznanie 08.2026) |
|---|---|
| Produkt | Aplikacja „Wędkarstwo Zezwolenia Mapy" / „Zimorodek: Zezwolenia i Mapy" (Android + iOS) i serwis zimorodek.pl. Wydawca: *Zimorodek – pasja i wędkarstwo* (Piotr Ślęzak) |
| Skala | **100 000+ pobrań w Google Play, ocena 4,9 z 1 920 recenzji**, aktywnie rozwijana (wersja 2.0.33, aktualizacja 02.2026) |
| Kim są użytkownicy | **Nie jest to baza kupujących wędkarzy.** Znaczącą część instalacji generują osoby zajmujące się tematyką wód zawodowo — administracja, urzędy, gospodarze wód — dla których wartością jest **baza wiedzy**: mapa wód, dane dzierżawcy, przepisy, wymiary i okresy ochronne. Wędkarze też tam są, ale sięgają po aplikację po informację, nie po zakup |
| Podaż | Zezwolenia okręgów PZW, RZGW Wód Polskich, gospodarstw rybackich, parków narodowych **oraz pojedynczych łowisk komercyjnych** (np. [Łowisko Szachty](https://zimorodek.pl/zezwolenia/lowisko-szachty), [Łowisko Piaseczno](https://zimorodek.pl/zezwolenia/lowisko-piaseczno)) — katalog uporządkowany per „gospodarz wody" |
| Funkcje | Zakup zezwolenia 24/7, mapa i wyszukiwarka wód wg typu, dane dzierżawcy, baza sklepów wędkarskich, posterunki PSR, wymiary i okresy ochronne gatunków, GPS i ulubione, nawigacja |
| Czego brakuje | **Kalendarza dostępności i rezerwacji stanowiska.** Model pozostaje „zezwolenie na wodę", nie „konkretne stanowisko w konkretnej dobie" |
| Model przychodowy | **Prowizja 10%** — dokładnie tyle, ile zakłada Fisherya |

Zimorodek zbudował zasięg, jakiego nie ma żaden marketplace rezerwacyjny (zasiadki.pl: 74 zarejestrowanych wędkarzy), i zrobił to **użytecznością referencyjną**, nie transakcją. To jest najważniejsza obserwacja z całego rozdziału 6 — i jednocześnie powód, dla którego jego pozycji **nie należy czytać jako gotowego popytu**. Sto tysięcy instalacji aplikacji, po którą sięga się po informację o wodzie, to sto tysięcy czytelników, a nie sto tysięcy kupujących. Płynność transakcyjna wciąż nie istnieje u nikogo na tym rynku.

Trzy wnioski praktyczne:

1. **Prowizja 10% nie jest u nas problemem cenowym.** Rynek zezwoleń już ją akceptuje w tej wysokości. Argument „konkurencja bierze 2%" (LakeBookings) dotyczy innego rynku; polski punkt odniesienia dla gospodarza wody to właśnie 10%.
2. **Zagrożenie jest inne, niż wygląda na pierwszy rzut oka.** Zimorodek nie odbierze nam rezerwacji, bo jego użytkownik nie przychodzi kupować. Odbierze nam natomiast **rolę miejsca, w którym wędkarz szuka informacji o wodzie** — a to jest wejście do lejka. Dodanie przez nich kalendarza stanowisk to kwestia decyzji, nie technologii.
3. **Mechanizm jest do skopiowania, produkt nie.** Warstwa referencyjna daje powód do otwierania aplikacji poza momentem zakupu. U nas jej odpowiednikiem są **dane na poziomie stanowiska** — dojazd, parking, wodowanie, rybostan — czyli dokładnie to, co ankieta wskazała jako problem numer jeden przy wyborze łowiska (15 wskazań, więcej niż rybostan). Z tą różnicą, że my mamy je od operatora, a nie z rejestrów.

Do rozważenia osobno: **Zimorodek jako kanał dystrybucji, nie konkurent** — skoro sprzedaje już zezwolenia łowisk komercyjnych, integracja „zezwolenie u nich, stanowisko u nas" jest możliwa. Wartość tego kanału trzeba jednak wycenić realistycznie, biorąc pod uwagę profil ich użytkownika: to raczej dostęp do widoczności niż do gotowych klientów.

**Do zweryfikowania ręcznie:** czy w aplikacji istnieje jakakolwiek forma rezerwacji terminu, ilu łowisk komercyjnych (a nie wód PZW/RZGW) faktycznie dotyczy oferta, oraz jak wygląda umowa i panel dla gospodarza wody. Serwisu nie da się odczytać automatycznie — jest aplikacją JS zwracającą pustą powłokę.

**Konkurencja realna, czyli status quo**

Największym konkurentem pozostają telefon, Messenger, zeszyt i arkusz Google. Duża część małych, sezonowych łowisk nie używa żadnego systemu — a wiele tych, które używają, ma rezerwację wpiętą we własną stronę ([lowisko-samsieczno.pl](https://lowisko-samsieczno.pl/bookings/), [lowiskozgoda.pl](https://rezerwacja.lowiskozgoda.pl/), [strongcarplake.pl](https://strongcarplake.pl/rezerwacja-online/), [lowiskobigfishlake.pl](https://lowiskobigfishlake.pl/rezerwacja/) i wiele innych). Rynek jest przyzwyczajony do rezerwacji „na stronie łowiska", nie w jednym portalu.

Do rozważenia są też uniwersalne systemy rezerwacyjne ([Bookero](https://bookero.pl/), Booksy, Reservio, Checkfront) — technicznie zdolne obsłużyć prosty booking, ale bez mapy stanowisk, pozwoleń sezonowych i modelu prowizyjnego marketplace'u.

### 6.2 Rynek zagraniczny

| Gracz | Rynek | Model i skala | Czego uczy |
|---|---|---|---|
| [LakeBookings.com](https://lakebookings.com/fishing-booking-system/) | UK | Rejestracja darmowa, **prowizja ~2%** + opłaty Stripe. Od 2018. Aplikacja „Lake Manager" dla obsługi do weryfikacji biletów na miejscu, bilety sezonowe z przypomnieniem o odnowieniu | Najbliższy funkcjonalnie odpowiednik Fisherya. Wzorzec przejrzystego, niskiego take-rate i aplikacji dla strażnika |
| [Swimbooker](https://swimbooker.com/) | UK | Marketplace z aplikacją mobilną (iOS/Android) + osobny serwis na wyjazdy karpiowe do Francji | Pokazuje wartość rozszerzenia z rezerwacji dobowej na pobyty wyjazdowe. Szczegółów nie udało się potwierdzić |
| [FishingBooker](https://fishingbooker.com/) | globalnie, 100+ krajów | Marketplace czarterów; kapitan sam ustawia prowizję (typowo 10–30%, najlepsze wyniki 15–20%) | Inny segment, ale najlepszy wzorzec działającego modelu prowizyjnego w wędkarstwie |
| [hejfish.com](https://www.hejfish.com/) | DE/AT | Sprzedaż cyfrowych „Angelkarten", **3000+ wód** | Odpowiednik polskich e-zezwoleń, w skali. Prawdopodobnie bez rezerwacji stanowisk |
| [Fiskado.de](https://fiskado.de/gewaesser/) | DE | Mapa wód + sprzedaż zezwoleń (na WooCommerce) | Ten sam segment co hejfish |
| [GoCarp](https://gocarp.com/en/) | FR | Katalog łowisk karpiowych, 213+ jezior, program dla właścicieli | Rezerwacja w dużej mierze przez formularz/telefon — słabszy technologicznie |
| [Hipcamp](https://en.wikipedia.org/wiki/Hipcamp), [Pitchup](https://www.pitchup.com/), [Camplify](https://help.camplify.com.au/au-help-centre/payments-and-fees) | US/UK/AU | Marketplace'y kempingowe. Camplify ujawnia stawki zależne od planu: **16% / 12,5% / 7%** | Wzorzec dla progowego modelu prowizji: niższy procent w zamian za większe zaangażowanie łowiska |

### 6.3 Wnioski

**Czy istnieje bezpośredni konkurent w Polsce?** Tak, ale żaden nie zajął rynku. fish.do i zasiadki.pl realizują ten sam pomysł, LakeControl i Fishitly walczą o panel operatora abonamentem, e-zezwolenia obsługują pozwolenia bez kalendarza. **Osobno stoi Zimorodek — nie zajął naszej kategorii, ale zajął rolę bazy wiedzy o wodach**, czyli wejście do lejka. Nikt natomiast nie łączy w jednym produkcie: odkrywania łowiska + kalendarza stanowisk + pozwoleń jednorazowych **i** sezonowych + usług dodatkowych + split payment + darmowego panelu.

**Luki, które Fisherya może zająć:**

1. **Pozwolenie sezonowe i rezerwacja stanowiska w jednym koncie** — dziś to dwa rozłączne światy (e-zezwolenia vs marketplace'y rezerwacyjne).
2. **Darmowy panel + prowizja od transakcji** zamiast abonamentu — dla małego, sezonowego łowiska 99 zł/mies. przez cały rok za narzędzie używane cztery miesiące to bariera nie do przejścia.
3. **Kompletny kalendarz dzięki bezprowizyjnym rezerwacjom offline** — konkurenci pobierający prowizję od wszystkiego uczą łowiska prowadzenia drugiego kalendarza.
4. **Aplikacja obsługi łowiska do weryfikacji na miejscu** (wzorzec LakeBookings) — w Polsce nikt tego jawnie nie oferuje.
5. **Jedno konto wędkarza na wiele rynków** — rynek jest rozdrobniony per kraj i nikt nie zbudował marki europejskiej.

**Weryfikacja z ankiety (październik 2025, 67 wędkarzy).** Na pytanie o znajomość stron do rezerwacji łowisk:

- **36 osób (54%) nie zna żadnej;**
- **22** wymieniły stronę lub Facebooka **konkretnego łowiska**;
- **6** wymieniło jakikolwiek agregator — w tym dwie osoby szwedzką aplikację Ifiske;
- **ani jedna osoba nie wymieniła fish.do ani zasiadki.pl.**

Deklarowane przez tamte serwisy 500+ i 300+ łowisk nie przekłada się na żadną rozpoznawalność po stronie popytu. Rynek jest realnie otwarty, a **prawdziwym konkurentem nie jest inny portal, tylko fanpage łowiska na Facebooku.** Szczegóły w [ANKIETA-WNIOSKI.md](ANKIETA-WNIOSKI.md).

Zastrzeżenie: pytanie w ankiecie dotyczyło *stron do rezerwacji*, więc Zimorodek mógł nie pasować do kategorii w głowie respondenta — to aplikacja do zezwoleń i map, nie do rezerwacji. Zerowa liczba wskazań przy 100 tys. pobrań jest więc raczej artefaktem pytania niż dowodem braku zasięgu — ale też potwierdza, że **Zimorodek nie funkcjonuje w głowie wędkarza jako sposób na załatwienie wyjazdu.** W kolejnym badaniu trzeba zapytać osobno o znajomość aplikacji i osobno o to, do czego jest używana.

**Ryzyka:**

- **Płynność dwustronna, nie podaż.** zasiadki.pl mają 300+ łowisk i 74 wędkarzy, a w ankiecie zero wskazań. Zebranie łowisk jest łatwe i niewiele znaczy — produkt wygrywa dopiero wtedy, gdy przyprowadza wędkarzy.
- **Presja na wysokość prowizji.** LakeBookings 2%, zasiadki „0 zł dla wędkarza" — rynek ustawia oczekiwania nisko, a niski take-rate przy sezonowym popycie oznacza długą drogę do rentowności. **Kontrargument: Zimorodek pobiera 10%** i sprzedaje w tym modelu zezwolenia okręgom PZW i gospodarstwom rybackim, więc polski punkt odniesienia dla gospodarza wody jest bliżej naszej stawki niż brytyjskich 2%.
- **Nieufność łowisk** do oddania pośrednikowi danych klientów i płatności, zwłaszcza tych, które mają już własny system.
- **Historia porażek** (ryblo.com, mała trakcja zasiadki.pl) — warto ustalić, dlaczego te próby nie wypaliły, zanim powtórzymy ich strategię wejścia.
- **KYC jako tarcie w onboardingu.** Split payment jest właściwy modelowo, ale każda dodatkowa formalność na starcie odpada część łowisk.
- **Więksi gracze** (BookingFish już w 10 krajach, FishingBooker z know-how marketplace'owym) mogą wejść w segment, gdy zobaczą potencjał.
- **Zimorodek jako właściciel wejścia do lejka.** Nie konkuruje o rezerwację i jego baza to w dużej części nie wędkarze-kupujący, tylko odbiorcy wiedzy o wodach (w tym administracja). Ryzyko nie polega więc na przejęciu transakcji, lecz na tym, że **to u nich zaczyna się szukanie informacji o wodzie**, a dodanie kalendarza stanowisk jest po ich stronie decyzją, nie wyzwaniem technicznym. Odpowiedź: własna warstwa danych o stanowisku, której z rejestrów zbudować się nie da.

## 7 Pomysły na przyszłość

- **Program lojalnościowy ponad Łowiskami** — Wędkarz zbiera punkty za obrót **do jednego worka, niezależnie od Łowiska**, i wymienia je na zniżki w portalu lub produkty w afiliowanych sklepach wędkarskich. Punkty ważne na jednym Łowisku umie wydać samo Łowisko; wspólna waluta jest czymś, czego pojedynczy Operator nie odtworzy. Punkty nie są naliczane od Rezerwacji offline — dzięki temu Wędkarzowi opłaca się kupować w systemie przy identycznej cenie. Punkty muszą mieć datę ważności.
- **Upsell i afiliacja sklepów wędkarskich** — zanęty, sprzęt, akcesoria; drugi strumień przychodu i miejsce, w którym punkty lojalnościowe mają realną wartość.
- **Aplikacja mobilna** dla wędkarza i operatora — powiadomienia push, mapa stanowisk w terenie, praca offline.
- **Check-in i weryfikacja obecności** — kod QR lub kod rezerwacji sprawdzany przez obsługę na miejscu, lista przyjazdów, obsługa no-show. Wymaga aplikacji mobilnej, dlatego trafia tutaj, a nie do MVP.
- **Opinie i oceny łowisk** wystawiane po zweryfikowanym pobycie — zaufanie i treść pod SEO.
- **Zawody i wydarzenia** — sprzedaż miejsc, zapisy, wyniki.
- **Rejestr połowów** — dane o presji i efektywności łowiska dla operatora, statystyki dla wędkarza.
- **Progowy model prowizji** (wzorzec Camplify) — niższa stawka za wyłączność lub pełne przeniesienie sprzedaży do systemu.

## 8 Zarządzanie danymi

Każdy Wędkarz rejestrujący się w systemie staje się klientem Fisherya.com. Portal staje się administratorem jego danych osobowych.
Wędkarz kupujący cokolwiek od danego Łowiska zgadza się na przekazanie jego danych do Operatora, który w tym momencie również staje się administratorem danych osobowych danego Wędkarza.
Operator widzi tylko Wędkarzy, których dane zostały mu przekazane.
Zakres zgód (RODO) może być różny dla Fisherya i każdego z Operatorów i musi podlegać konfiguracji.

### 8.1 Model prawny

Fisherya i Łowisko są **odrębnymi administratorami**, nie współadministratorami. Fisherya administruje kontem Wędkarza i danymi transakcyjnymi portalu; Łowisko — danymi swoich klientów od momentu zakupu, we własnych celach (realizacja usługi, regulamin, księgowość, kontakt). Każdy z nich ma własną politykę prywatności i własne zgody.

Wymaga to jasnej informacji dla Wędkarza w momencie zakupu: komu i w jakim zakresie dane są przekazywane.

### 8.2 Zakres danych przekazywanych Operatorowi

- imię i nazwisko;
- e-mail i telefon;
- dane do faktury (firma, NIP, adres) — jeśli Wędkarz o fakturę poprosił;
- historia pobytów tego Wędkarza **na łowiskach tego Operatora** (nie na innych).

Dane Uczestników innych niż kupujący ograniczamy do niezbędnego minimum — do rozstrzygnięcia, czy łowisko potrzebuje imion osób towarzyszących, czy wystarczy ich liczba i role.

### 8.3 Retencja i marketing

- Usunięcie konta Wędkarza nie może usuwać danych, których przechowywania wymagają przepisy księgowe — po stronie Fisherya i po stronie Łowiska.
- Marketing Operatora do „swoich" Wędkarzy wymaga osobnej zgody, zbieranej i odwoływalnej w systemie. Domyślnie jest wyłączony.

## 9 Zakres MVP

W pierwszym wydaniu:

- rejestracja Firmy i Łowiska z weryfikacją;
- definiowanie Stanowisk z mapą, Okresów sprzedaży i cennika;
- **Rezerwacje jednorazowe z płatnością online** (split payment);
- **Pozwolenia długookresowe** wraz z regułami rezerwacji dla ich posiadaczy (4.3);
- **Usługi dodatkowe** dosprzedawane w koszyku;
- **wyszukiwarka z mapą i publiczne strony łowisk**;
- kalendarz Operatora z rezerwacjami offline i blokadami;
- konto Wędkarza z historią, koszyk przed logowaniem;
- **lista oczekujących na zwolnione Stanowisko** (4.6);
- **Pozwolenie i potwierdzenie w telefonie, bez drukowania** — z kodem, gotowe do okazania na miejscu. To nie to samo co check-in Operatora (poza MVP): chodzi wyłącznie o stronę Wędkarza. W ankiecie konieczność drukowania potwierdzeń wskazano wprost jako jedną z trudności;
- anulacje i zwroty wg polityki Łowiska;
- powiadomienia e-mail;
- automatyczne fakturowanie prowizji z integracją KSeF.

Poza MVP: aplikacja mobilna, check-in, opinie, rejestr połowów, zawody, SMS/push, wielojęzyczność interfejsu (choć model danych ma być na nią gotowy).

Osobno: **program lojalnościowy i afiliacja sklepów** (7) formalnie są poza MVP, ale pełnią funkcję inną niż reszta tej listy — są mechanizmem, który utrzymuje sprzedaż w systemie zamiast na telefonie. Powinny wejść jako pierwsze po MVP, a decyzja o wysokości prowizji musi od początku uwzględniać ich koszt.

Rynek startowy: **Polska**. Model danych, słowniki krajów i walut oraz warstwa płatności są jednak projektowane pod docelową obecność globalną — lokalne pozostają wyłącznie integracje (GUS, KSeF, dostawca płatności), które muszą być wymienne per kraj.

## 10 Strategia wejścia i miary sukcesu

### 10.1 Pierwszy klient i region startowy

**Pierwszym łowiskiem gotowym na wdrożenie jest Jezioro Gackie.** To ono wyznacza region startowy i pierwszą społeczność wędkarzy — a przy okazji było jednym z kanałów, którymi dystrybuowana była ankieta z października 2025 (17 wzmianek w odpowiedziach).

Zasada: **gęstość zamiast zasięgu.** Celem nie jest portal dla Polski, tylko komplet łowisk w jednym promieniu wokół Gackiego. Dopiero gdy wędkarz z tego obszaru widzi w portalu wszystko, co go interesuje, portal przestaje być katalogiem. Żadnej ekspansji na drugi region przed powtarzalnymi zakupami w pierwszym.

Konfigurację pierwszych łowisk wykonuje zespół Fisherya (onboarding concierge) — właściciel tylko zatwierdza.

### 10.2 Hipotezy

Poniższe są **hipotezami do weryfikacji**, nie ustaleniami. Część została już potwierdzona ankietą — zaznaczono to przy każdej.

**Hipoteza segmentu.** Najlepszym punktem startu są łowiska karpiowe z wyznaczonymi stanowiskami i sprzedażą dobową — mają największy problem z kalendarzem, najwyższą wartość transakcji i najbardziej zaangażowaną społeczność. *Ankieta: karpiowanie z gruntu to najczęstsza metoda w próbce (30 z 67).*

**Hipoteza wygody.** Wartością nie jest odkrywanie nowych łowisk, tylko sposób załatwienia sprawy — bez telefonu, o dowolnej porze. *Ankieta potwierdza mocno: 43 osoby wracają od lat na te same łowiska, a mimo to 44 z 67 jako ideał wskazuje internet lub aplikację.*

**Hipoteza dystrybucji.** Wędkarz nie szuka portalu — szuka konkretnego łowiska w Google i na Facebooku. Publiczne strony łowisk i SEO są kanałem numer jeden. *Ankieta potwierdza: 22 osoby jako „stronę do rezerwacji" wymieniły stronę lub Facebooka konkretnego łowiska; żadna nie wymieniła fish.do ani zasiadki.pl.*

**Hipoteza wartości dla łowiska.** Łowisko przejdzie na system nie dla nowych klientów, tylko dlatego, że przestanie odbierać telefony. *Niezweryfikowana — to jest teza o drugiej stronie rynku, a ankieta objęła wyłącznie wędkarzy.*

**Hipoteza portalu — najsłabiej ugruntowana i najważniejsza.** Że wędkarz chce jednego miejsca dla wielu łowisk, a nie tylko kalendarza na stronie swojego łowiska. *Ankieta tego nie dowodzi: o jeden serwis dla wielu wód poprosiły z własnej inicjatywy trzy osoby z 67. To jest pytanie do następnej rundy badań i teza, na której stoi cały model biznesowy.*

**Miary sukcesu do ustalenia liczbowo:**

- udział rezerwacji online w całym ruchu łowiska (czy kalendarz jest kompletny);
- liczba łowisk **aktywnie sprzedających**, nie zarejestrowanych;
- powracalność wędkarza (drugi zakup, także na innym łowisku — to test wartości portalu, a nie pojedynczej strony);
- GMV i take-rate.

## 11 Pytania otwarte

1. Wybór dostawcy płatności obsługującego split payment i marketplace; jego wymagania KYC i wpływ na onboarding (kryteria w 5.1).
2. **Czy pełna płatność z góry nie odetnie części rynku.** Decyzja z 5.1 jest podjęta, ale w ankiecie cztery osoby z własnej inicjatywy wskazały jako ideał opłatę na miejscu albo zaliczkę („opłata mogłaby być uiszczana u opiekuna łowiska", „telefoniczna rezerwacja, opłata na miejscu"). To mniejszość, ale niezerowa — warto to sprawdzić wprost, zanim temat zostanie zamknięty.
3. Wysokość prowizji i to, czy jest doliczana Wędkarzowi, czy potrącana Łowisku; kto pokrywa nieodzyskiwalne opłaty PSP przy zwrocie.
4. Karta wędkarska / uprawnienia do połowu — czy system w ogóle je odnotowuje i czy któreś łowisko wymaga ich weryfikacji.
5. Wędkarze niepełnoletni — czy mogą mieć konto, czy występują wyłącznie jako Uczestnicy przy koncie opiekuna.
6. Zakres danych osób towarzyszących.
7. Szczegółowy podział uprawnień w rolach pracowniczych.
8. Co się dzieje z Łowiskiem i historią jego rezerwacji przy zmianie właściciela lub firmy.
