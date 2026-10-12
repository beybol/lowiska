# ADR-028 — Rejestr zgód i akceptacji, dokumenty portalu

- **Status:** accepted
- **Data:** 2026-10-12
- **Zadanie:** [049 — Potwierdzenie rezerwacji oddzielone od połączenia z kontem wędkarza](../tasks/049-potwierdzenie-rezerwacji-a-konto-wedkarza.md)

## Kontekst

Zadanie 049 (ADR-024, Aktualizacja 2026-10-11) wymaga zgód przy zakładaniu konta (dokumenty Fisherya
i łowiska), przy dowiązaniu konta do łowiska (dokumenty łowiska) i przy zwykłej rejestracji
(dokumenty Fisherya, rozstrzygnięcie R3). Etap 4 doda akceptację przy zakupie. Dyskusja z autorem
(11–12.10.2026) poszerzyła temat o trzy sprawy, które kształtują dane od pierwszego wiersza:

1. **Rynki.** Portal ma działać na wielu rynkach, a dokumenty mogą różnić się treścią (lokalne
   regulacje), nie tylko językiem.
2. **Zmiany dokumentów.** Portal i łowisko zmieniają dokumenty, czasem w zakresie wymagającym
   ponownej akceptacji.
3. **Zgody marketingowe**, osobne dla każdego kanału i nadawcy, z możliwością wycofania.

Stan dzisiejszy: system nie zapisuje żadnych zgód. Dokumenty łowiska (`documents`, ADR-017) są
wersjonowane i mają flagi `required_at_purchase` oraz `required_at_registration` (znaczenie robocze).
Dokumentów Fisherya nie ma (statyczne strony, TODO-1). Rezerwacja zamraża warunki w `terms_snapshot`
(ADR-026). Łowisko ma kraj przez województwo (`fisheries.state_id → states.country_id`).

Dlaczego to ADR:
- **zasięg:** rejestracja, łączenie konta, zakup, profil wędkarza, każda przyszła wysyłka
  marketingowa i obowiązki RODO;
- **koszt odwrócenia:** zapisy zgód są dowodem, więc zmiana ich kształtu oznacza migrację danych,
  które nie mogą zginąć;
- **uzasadnienie:** dlaczego zgoda wskazuje wersję i wariant językowy, a rynek wynika z łowiska, a nie
  z kraju wędkarza, nie da się odczytać z kodu.

⚠️ Dokument nie jest opinią prawną. Punkty oznaczone „TODO-3” czekają na prawnika.

## Trzy rodzaje zapisów

| Rodzaj | Przykład | Natura | Wycofanie |
|---|---|---|---|
| **Akceptacja warunków** | regulamin Fisherya, regulamin łowiska | zawarcie umowy | brak, możliwe rozwiązanie umowy (usunięcie konta) |
| **Zapoznanie się** | polityka prywatności | obowiązek informacyjny RODO, nie zgoda (TODO-3) | nie dotyczy |
| **Zgoda marketingowa** | marketing e-mail, SMS, push, telefon | zgoda RODO | tak, w każdej chwili, tak łatwo jak wyrażenie |

Poza rejestrem, bo nie są zgodą: **preferencje powiadomień technicznych** (§3a).

## Alternatywy

### Opcja A — jeden rejestr zdarzeń + dokumenty portalu w `documents` *(rekomendowana)*

**Rejestr (`consent_events`, tylko dopisywanie, bez edycji i kaskady):** `user_id`, rodzaj zapisu
(`acceptance` · `acknowledgement` · `marketing`), przedmiot, decyzja (`granted` · `withdrawn`), kontekst
(`registration` · `angler_link` · `profile` · `unsubscribe_link` · później `purchase`), opcjonalnie
`fishery_angler_id`, `occurred_at`, adres IP.
- Przy akceptacji i zapoznaniu przedmiotem jest **wersja dokumentu i wariant językowy**, który
  wędkarz zobaczył.
- Przy zgodzie marketingowej przedmiotem jest nadawca (Fisherya, partnerzy Fisherya albo konkretne
  łowisko) z kanałem i **wersją treści klauzuli**.
- Stan bieżący to ostatnie zdarzenie dla przedmiotu, liczone przez jedną usługę. Historii się nie
  nadpisuje.

**Dokumenty portalu w `documents`:** `fishery_id` nullowalny (`NULL` = Fisherya), ta sama reguła jednej
wersji obowiązującej na rodzaj, ta sama metoda „wersja obowiązująca”. Unikalność pilnuje reguła
w `app/Rules/`, nie indeks, więc pułapka `NULL` w indeksie z ADR-017 nie występuje.

**Zalety:** jeden kształt dowodu dla wszystkich rodzajów; zakup (etap 4) i nowe kanały to nowe
wartości, nie nowe tabele; jedno miejsce pytania o zgodę dla każdej wysyłki.
**Wady:** zapytania o dokumenty łowiska muszą zawsze filtrować po łowisku; dokumenty portalu
potrzebują osobnej strony w panelu admina.

### Opcja B — osobne tabele per rodzaj (akceptacje dokumentów, zgody marketingowe) i `platform_documents`
**Zalety:** każda tabela prostsza, dokumenty łowisk nietknięte.
**Wady:** drugi mechanizm wersji (drugi literał reguły „wersja obowiązująca”); dwa kształty dowodu;
akceptacja wskazuje dokument polimorficznie, bez klucza obcego.

### Opcja C — znaczniki w rekordach (`users.terms_accepted_at`, JSON zgód)
**Zalety:** najmniej kodu.
**Wady:** brak historii, więc nie da się wykazać, którą wersję zaakceptowano i kiedy wycofano zgodę.
Odpada przy marketingu.

## Rekomendacja

**Opcja A**, z poniższymi ustaleniami.

### 1. Rynek i zakres dokumentów

- **Rynek dokumentów łowiska to kraj łowiska.** Umowa z łowiskiem jest wykonywana w jego kraju.
  Usługa świadczona wyłącznie w innym kraju niż kraj zamieszkania konsumenta to sytuacja, w której
  ochrona konsumencka z kraju zamieszkania co do zasady nie wypiera prawa wybranego (Rzym I, art. 6
  ust. 4 lit. a). Do potwierdzenia: TODO-3. Polskie łowisko nie potrzebuje regulaminu „dla Niemców”.
- **Regulamin Fisherya jest jeden i ogólny (globalny)**, z klauzulą zachowującą bezwzględnie
  obowiązujące przepisy kraju zamieszkania konsumenta. Usługa platformy (pośrednictwo online) jest
  świadczona tam, gdzie jest wędkarz, więc jego ochrony nie da się wyłączyć wyborem prawa. Ogólna
  treść z taką klauzulą unika regionalizacji (TODO-3).
- **Dokument Fisherya może mieć opcjonalny zakres rynku**, na wypadek gdy wejście na rynek wymusi
  lokalny aneks. Rynkiem jest kraj łowiska, z którego wędkarz korzysta. Aneks akceptuje się przy
  pierwszym kontakcie z łowiskiem tego rynku (dowiązanie, zakup), a nie przy rejestracji. Na start
  nie ma żadnego aneksu, a kolumna zakresu jest pusta.
- **Kraju zamieszkania wędkarza nie używamy do wyboru dokumentów.**
- **Warianty językowe:** wersja dokumentu ma warianty językowe tej samej treści, jeden oznaczony
  jako wiążący (TODO-3). Dokumenty łowiska mają jeden wariant w języku łowiska. System niczego
  nie tłumaczy (D8). Możliwość dodania wariantu przez łowisko zostaje otwarta na później.

### 2. Zmiany dokumentów

1. Wersja ma **rodzaj zmiany**, wybierany przy publikacji: `editorial` (bez powiadomienia), `notice`
   (powiadomienie, bez ponownej akceptacji), `reacceptance` (wymaga ponownej akceptacji).
2. Wersja ma **datę ogłoszenia** i **datę wejścia w życie**. Minimalny okres uprzedzenia jest wartością
   konfiguracyjną, nie stałą w kodzie (P2B daje łowiskom 15 dni; TODO-3).
3. **Zawarte umowy się nie zmieniają.** Rezerwacja ma zamrożone warunki (ADR-026), więc nowa wersja
   dotyczy wyłącznie przyszłych działań.
4. **Skutek braku ponownej akceptacji:**
   - **dokument Fisherya:** ekran akceptacji **po zalogowaniu** (decyzja autora). Do czasu akceptacji
     użytkownik ma dostęp tylko do podglądu, rezygnacji z rezerwacji, eksportu danych i usunięcia
     konta. Odmowa oznacza rozwiązanie umowy;
   - **dokument łowiska:** akceptacja w kroku kolejnego zakupu albo rezerwacji w tym łowisku.
     Istniejące rezerwacje działają dalej.
5. **Adresaci powiadomienia** to osoby, które zaakceptowały poprzednią wersję (dla łowiska: powiązani
   wędkarze). Powiadomienie jest wiadomością techniczną.

Punkty 1–3 to dane, które powstają w 049. Ekran akceptacji po zalogowaniu, powiadomienia i blokada
zakupu to **osobne, przyszłe zadania**.

### 3. Zgody marketingowe

**Bez konfiguracji po stronie łowiska** (decyzja autora): nadawcy i kanały są stałe dla całego
portalu. Wędkarz zarządza nimi w profilu, w podmenu „Zgody”, w tabeli:

| Kanał | Marketing Fisherya | Marketing partnerów Fisherya | Marketing łowisk ▸ |
|---|---|---|---|
| E-mail | ☐ | ☐ | ☐ |
| SMS | ☐ | ☐ | ☐ |
| Push | ☐ | ☐ | ☐ |
| Telefon | ☐ | ☐ | ☐ |

- **Domyślnie wszystkie pola są puste.** Zaznaczone z góry pole nie jest ważną zgodą.
- **Marketing partnerów wysyła wyłącznie Fisherya, dane nie trafiają do partnerów** (decyzja autora).
  Przekazanie danych wymagałoby zgody wskazującej konkretnych odbiorców (TODO-3).
- **Marketing łowisk zapisuje się per łowisko** (decyzja autora). Każde łowisko jest odrębnym
  administratorem danych, a zgoda „od wszystkich łowisk”, także przyszłych, nie byłaby konkretna.
  Kolumna jest skrótem dla łowisk, z którymi wędkarz ma relację: zaznaczenie albo odznaczenie
  zmienia wszystkie naraz, a rozwinięcie (▸) pokazuje listę tych łowisk z osobnymi przełącznikami
  per kanał. W rejestrze każde łowisko ma własne zdarzenie. **Nowe łowisko nie dziedziczy zgody**:
  przy dowiązaniu konta pyta o nią z pustymi polami.
- **Push bez rozróżnienia web push i aplikacji** (decyzja autora). Zgoda dotyczy kanału, a subskrypcja
  urządzenia (przeglądarka, aplikacja) to osobny techniczny rekord z własnym pozwoleniem systemowym.
- **Telefon:** system nie dzwoni sam, więc operator widzi zgodę na kontakt telefoniczny w podglądzie
  wędkarza (zadanie 048) i to on jej przestrzega.
- **Każda wysyłka marketingowa pyta jedną usługę** („czy wolno nadawcy X wysłać wędkarzowi Y kanałem
  Z”) i ma link wypisu, który zapisuje zdarzenie `withdrawn` z kontekstem `unsubscribe_link`.
- **Łowisko widzi wyłącznie zgody wobec siebie** (ADR-024 pkt 2).

### 3a. Preferencje powiadomień technicznych

Osobna tabela w profilu, nazwana „Preferencje”, **wspólna dla całego systemu**: bez podziału na
wiadomości od Fisherya i od łowisk (decyzja autora).

| Kanał | Powiadomienia techniczne |
|---|---|
| E-mail | 🔒 zawsze |
| SMS | ☐ |
| Push | ☐ |

- Wiadomości techniczne wynikają z umowy, więc to **preferencja, nie zgoda**. Nie ma ich w rejestrze
  zgód. Przechowujemy wyłącznie stan bieżący (np. `user_notification_preferences`), bez historii
  dowodowej.
- **E-mail jest zablokowany**, bo kanał techniczny musi zawsze działać.
- **Wiadomość techniczna nie zawiera treści marketingowej.** Na tej granicy opiera się zablokowany
  e-mail.
- Telefonu tu nie ma: rozmowę techniczną (np. operator dzwoni w sprawie rezerwacji) prowadzi człowiek
  i system jej nie wysyła.

### 4. Zakres zadania 049

W 049 powstają:
- rejestr;
- dokumenty Fisherya w `documents` (zakres globalny, warianty językowe, rodzaj zmiany, daty);
- wymagane akceptacje przy rejestracji i dowiązaniu.

Zgody marketingowe i preferencje istnieją w 049 **tylko w schemacie i usłudze odczytu**. Kolejne
zadania to:
- tabele w profilu;
- pola zgód przy dowiązaniu;
- link wypisu;
- ekran ponownej akceptacji;
- powiadomienia.

## Decyzja
Decyzja: A z ustaleniami z rekomendacji wynikającymi z dyskusji
