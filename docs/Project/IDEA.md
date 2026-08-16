# Fisherya - system obsługi łowisk dla operatorów i system sprzedaży dostępu do nich dla wędkarzy

## 1 Idea ogólna

System powstaje z obserwacji słabej dygitalizacji i realnych problemów świata wędkarzy i łowisk komerycjnych.
W Polsce i na świecie jest coraz więcej łowisk zarządzanych przez firmy lub osoby prywatne i udostępnianych wędkarzom na zasadach komercyjnych.
Kupowanie pozwoleń na łowienie na takich łowiskach odbywa się jednak najczęściej analogowo i polega na kupowaniu ich telefonicznie, w przydrożnym sklepie lub mailowo.
Co więcej bardzo często nie wiadomo, czy miejsce na łowisku będzie wolne bo nie ma nigdzie on-line dostępu do kalendarza łowiska.
Pojawiła się więc idea, żeby wzorem Ubera czy Glovo które rozwiązały uciążliwości związane z zamawianiem taksówek (nieznana cena i czas przyjazdu) czy jedzenia (brak dostępu do menu i brak informacji o czasie dostarczenia) zbudować aplikację która zagospodarowałaby branżę wędkarską.

## 2 Słownik pojęć

- Łowisko: jezioro lub staw zarządzane przez firmę lub osobę która udostępnia je na zasadach komercyjnych dla wędkarzy
- Wędkarz: osoba łowiąca ryby chcąca korzystać z Łowiska
- Klient: właściciel Łowiska
- Operator: właściciel Łowiska lub jego pracownik korzystający z systemu w celu zarządzania łowiskiem
- Stanowisko: wyznaczone miejsce do łowienia na danym łowisku (np. konkretny pomost, plaża, zatoczka lub wejście do wody)
- Pozwolenie: uprawnienie nabywane komercyjnie pozwalające na korzystanie z Łowiska i/lub Stanowiska
- Pozwolenie długookresowe: Pozwolenie na sezon lub kilka miesięcy. Pozwala na korzystanie z Łowiska w okresie które obejmuje. Nie gwarantuje wolnego Stanowiska.
- Pozwolenie jednorazowe: Pozwolenie na godziny lub dni, zawsze powiązane z konkretnym Stanowiskiem.
- Rezerwacja: zamówienie konkretnego Stanowiska - element nierozłączny dla Pozwoleń jednorazowych. Może być też tworzone za opłatą lub bez w przypadku korzystania z Łowiska w oparciu o Pozwolenie długokresowe - Wędkarz ma prawo łowienia na danym Łowisku ale rezerwuje sobie konkretny dzień i konkretne Stanowisko
- Usługa dodatkowa: każda usługa, która może uzupełniać ofertę Łowiska i która może być dosprzedana do Pozwolenia lub Rezerwacji. Przykłady: namiot, łódka, gril.
- Administrator: pracownik Fisherya.com zarządzający portalem

## 3 Podstawowe procesy

### 3.1 Zakładanie/rejestracja Łowiska

Właściciel lub Operator Łowiska tworzy swoje indywidualne konto w systemie.
Konto pozwala mu na dodanie firmy poprzez wyszukanie jej w bazie GUS i autoryzację.
Autoryzacja musi zapewnić, że osoba rejestrująca daną firmę jest jes przedstawicielem. 
W zależności od operatora płatności i funkcji które on udostępni autoryzacja może być automatyczna lub w oparciu o przelew z konta firmy na kwotę 1zł na konto Fisherya.com (w takim przypadku administrator systemu autoryzuje firmę ręcznie).
Po autoryzacji użytkownika może on zdefiniować ofertę Łowiska i rozpocząć sprzedaż Pozwoleń, Rezerwacji i usług dodatkowych.

### 3.2 Wyszukiwanie łowisk

Na stronie głównej portalu Wędkarz może wyszukać z mapy lub z listy przez wyszukiwarkę tekstową łowisk, które moga go interesować.

### 3.3 Konto Wędkarza

Wędkarz może założyć sobie konto, które będzie pozwalało na kupowanie Pozwoleń, Rezerwacji i Usług dodatkowych na wybranych Łowiskach

## 4 Główne funkcje

### 4.1 Zarządzanie Łowiskiem

Operator może zarządzać jednym lub wieloma łowiskami zarządzanymi przez jedną lub wiele firm.

W ramach zarządzania Łowiskiem jego Operator może opisać łowisko i jego parametry, dodać zdjęcia itp.
Kluczowe jest jednak zdefiniowanie oferty Pozwoleń (długookresowych i/lub krótkookresowych), Stanowisk (z mapą) i Usług dodatkowych.

Operator może również zarządzać Rezerwacjami (za pomocą kalendarza pokazującego w kolumnach kolejne dni tygodnia/miesiąca), a w wierszach Stanowiska.

Operator widzi również stan rozliczeń - liczbę i wartość opłaconych Rezerwacji/wykupionych Pozwoleń

Operator może też zarządzać Wędkarzami, korzystającymi z jego Łowisk

### 4.2 Funkcje Wędkarza

Wędkarz może wyszukać Łowisko na stronie systemu i zapoznać się z jego ofertą.

Wędkarz może założyć konto w systemie i za jego pomocą może kupować Pozwolenia i Rezerwacje na dowolnym Łowisku, które jest zarządzane w systemie.
Wędkarz widzi w widoku kalendarza dostępność Stanowisk na wybranym Łowisku.

Wędkarz widzi wszystkie swoje historyczne Rezerwacje i wykupione Pozwolenia.

## 5 Model biznesowy

Portal ma zarabiać na prowizji od wszystkich operacji zakupu relizowanych przez Wędkarzy.
Obsługa rezerwacji w panelu Operatora nie wymaga płątności - system dla Łowisk jest darmowy i pozwala zarządzać nimi bez ograniczeń.
Przychód jest tylko realizowany jako procent od płatności realizoanych w systemie przez Wędkarzy.

Kluczowe jest znalezienie operatora, który pozwoli na to, zeby płatności trafiały do razu na konto Łowiska ale były pomniejszane o prowizję operatora płatności i Fisherya.com (split payment).
Może się to wiązać z koniecznością przejścia procesu autoryzacji każdej firmy rejestrowanej w systemie (patrz proces opisany w punkcie 3.1)

## 6 Analiza konkurencji

## 7 Pomysły na przyszłość

## 8 Zarządzanie danymi

Każdy Wędkarz rejestrujący się w systemie staje się klientem Fisherya.com. Portal staje się administratorem jego danych osobowych.
Wędkarz kupujący cokolwiek od danego Łowiska zgadza się na przekazanie jego danych do Operatora, który w tym momencie również staje się adminsitratorem danych osobowych danego Wędkarza.
Operator widzi tylko Wękdarzy, których dane zostały mu przekazane.
Zakres zgód (RODO) moze być różny dla Fisherya i każdego z Operatorów i musi podlegać konfiguracji.
