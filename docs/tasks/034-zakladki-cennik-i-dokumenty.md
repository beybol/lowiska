# 034 — Strona łowiska: zakładki „Cennik" i „Dokumenty"

> **Etap 2, zadanie 3** ([roadmapa](../project/ROADMAPA.md)). Założone 30.09.2026 przy planowaniu
> implementacji (uzupełnienie makiet v3, które tych zakładek nie rysują). Wymaga 032.

## Opis problemu

W v3 cennik i regulamin są sekcjami zakładki „Szczegóły": cennik jako prosta tabela, dokumenty
jako jeden regulamin. To za mało:
- **cennik** ma kilka składowych (stawka za łowiącego, osoba towarzysząca, dopłaty pod nazwami
  łowiska z warunkami, obniżka przedsprzedażowa, usługi z jednostkami, zasięgiem i wymaganymi
  cechami, usługi obowiązkowe) i opcji, które wędkarz musi zrozumieć przed telefonem;
- **dokumenty** to nie tylko regulamin, ale też polityka prywatności łowiska i dokument, który
  łowisko samo nazwie (np. zasady biwakowania, instrukcja dojazdu nocą).

## Wymagania

- **Makieta dwóch zakładek** (desktop i 390 px) dopisana do `docs/project/mockups/portal-v3/portal-v3.html`
  przed implementacją, zatwierdzona przez autora.
- **Zakładki w tym samym dokumencie HTML co 032**, jako kolejne pozycje `PortalRoutes::FISHERY_TABS`
  (R6). Z „Szczegółów" (032) nic nie ubywa, bo cennika i dokumentów już tam nie ma.
- **Zakładka „Cennik"** przedstawia składowe ceny i opcje w przystępny sposób:
  - stawki za łowiącego z okresami obowiązywania oraz kwota za osobę towarzyszącą;
  - dopłaty pod nazwami łowiska z warunkiem w języku wędkarza („przy jednym łowiącym, czw–nd");
  - przedsprzedaż (okno, minimum, obniżka), gdy jest otwarta albo nadchodzi;
  - usługi dodatkowe z jednostką (za dobę / za pobyt), zasięgiem (całe łowisko / wybrane
    stanowiska) i wymaganymi cechami; usługi obowiązkowe wyróżnione;
  - **bez przykładu wyceny** (R2); przycisk `[data-tab-open]` „Sprawdź cenę terminu" przełącza
    na zakładkę z kalendarzem;
  - **horyzont: od dziś bez górnej granicy** (R3); stawki zakończone, zawieszone i martwe się
    nie pokazują.
  - Źródło: warstwa cennika i usług (`price_rules`, `additional_services`, `PositionServices`).
    **Portal nie liczy po swojemu** (R4, R5).
- **Zakładka „Dokumenty"**: dla każdego rodzaju **wyłącznie wersja obowiązująca dziś**
  (`FisheryDocuments::current()`, ADR-017), w kolejności: regulamin, polityka prywatności, inne.
  Każda pozycja to nagłówek rodzaju, tytuł wersji, data „obowiązuje od" i treść do wglądu (R8),
  bez akceptacji (etap 4).
  - Rodzaj bez wersji obowiązującej → **jego sekcja się nie pokazuje** (R7).
  - Łowisko bez żadnego dokumentu obowiązującego → zakładka zostaje i mówi to wprost (R7).
  - Wersje zaplanowane się **nie pokazują** (R1).
- **Nowy rodzaj dokumentu „Inne"** (`DocumentType::Other`, R9), z własnym tytułem wersji. Działa
  jak pozostałe rodzaje: jedna obowiązująca wersja na łowisko, ta sama mechanika wersji i szablonów
  w obu panelach.

## Kryteria akceptacji

- [ ] Makieta zakładek zatwierdzona.
- [ ] Cennik Klasztornego i Łopienna na stronie zgadza się z konfiguracją (stawki, dopłata
      Łopienna z warunkiem, prysznic, łódka, przyczepa z wymaganą cechą).
- [ ] Stawka zakończona, zawieszona albo martwa od dziś nie pokazuje się w cenniku. Stawka
      przyszła pokazuje się ze swoim okresem.
- [ ] Dokumenty: dla każdego rodzaju wyłącznie wersja obowiązująca. Wersja zaplanowana
      i archiwalna się nie pokazują. Rodzaj bez wersji obowiązującej nie ma sekcji. Łowisko bez
      żadnego dokumentu ma zakładkę z komunikatem.
- [ ] Rodzaj „Inne" działa w panelu właściciela i admina (zakładka na liście wersji, szablony)
      i nie zmienia warunków publikacji łowiska (wymagany nadal wyłącznie regulamin).
- [ ] Treść dokumentów przechodzi przez `Str::sanitizeHtml()`.
- [ ] **Ręczna weryfikacja** na telefonie (390 px), tablecie i desktopie, w obu językach.
- [ ] Testy w zadeklarowanym zakresie zielone; **pełny pakiet odroczony** na `/review-implementation`.

## Zakres testów

- **Tier:** T2 — zależności
- **Uruchamiamy:** `docker compose exec app php artisan test --filter="FisheryPricingTab|FisheryDocumentsTab|FisheryDocumentsTest|DocumentTemplateTest|FisheryPublicationTest|PricingConfigurationAudit|PortalFisheryPage"`
- **Uzasadnienie:** nowe widoki portalu na istniejących danych plus nowy przypadek enuma
  `DocumentType`, którego używają panel dokumentów, szablony, reguła daty i gotowość publikacji.
  Bez migracji (`documents.type` i `document_templates.type` to `string`), polityk i providerów
  paneli, więc bez wyzwalaczy T3. Pełny pakiet jest odroczony na `/review-implementation`.

## Zakres wyłączeń

- Akceptacja dokumentów przez wędkarza i polityka zwrotu (etap 4, koszyk).
- Usługi w sumie ceny (etap 4).
- Kalkulator ceny pobytu i przykład wyceny, bo kalendarz (033) już pokazuje cenę pobytu z rozbiciem.
- Kilka jednocześnie obowiązujących dokumentów jednego rodzaju (R9).
- Zapowiedź wersji zaplanowanej (R1).

## Zmiany dokumentacji

- [ ] `docs/project/mockups/portal-v3/`: makieta zakładek; README §1 (lista zakładek).
- [ ] `docs/conventions/strona-publiczna.md` §5: zakładki „Cennik" i „Dokumenty" (kotwice,
      reguły widoczności sekcji dokumentów); §1: odsyłacz do odczytu cennika.
- [ ] `docs/conventions/cennik.md` §5: odczyt cennika dla portalu jako drugi jawny wyjątek obok
      `PriceFrom` (R4), filtr „żywa od dziś" w `PricingConfigurationAudit` (R5).
- [ ] `docs/conventions/panel-wlasciciela.md` §9: trzeci rodzaj „Inne" (zakładka, nie wpływa na
      gotowość publikacji).
- [ ] `docs/adr/ADR-017`: sekcja „Aktualizacja" z jednym zdaniem: rodzaj „Inne" dodany w 034
      zgodnie z regułą „kolejny rodzaj bez nowej mechaniki".
- [ ] `CHANGELOG.md`: wpis.

## Ograniczenia techniczne

- ADR-017 (dokumenty i wersje), ADR-014/015 (cennik, warstwa oferty), zadanie 020 (usługi, jednostki).
- Treści operatora jednojęzyczne (D8): tytuły dokumentów, nazwy dopłat i usług po polsku także w EN.
- Bez skryptu wszystkie panele zakładek widoczne jeden pod drugim (`strona-publiczna.md` §5).

## Rozstrzygnięcia

- **R1. Wersja zaplanowana dokumentu się nie pokazuje.** Decyzja autora: portal pokazuje wyłącznie
  to, co obowiązuje dziś. Zaplanowana wersja może się jeszcze zmienić albo zostać usunięta.
- **R2. Bez przykładu wyceny w cenniku.** Cenę konkretnego pobytu z rozbiciem z warstwy oferty
  pokazuje kalendarz (033). Cennik odsyła do niego przyciskiem `[data-tab-open]`.
- **R3. Horyzont cennika: od dziś bez górnej granicy.** Wędkarz planujący przyszły sezon widzi
  przyszłe ceny z ich okresami. Świadomie inny niż horyzont „ceny od" (`PriceFrom`), bo cennik
  opisuje ofertę, a „cena od" to jedna liczba na dziś.
- **R4. Dane zakładki składa jedna usługa portalu** (`App\Services\PortalPriceList`), wołana
  przez `PortalFisheryPage`. To jawny wyjątek od „portal pyta wyłącznie `StayOffer`", tak jak
  `PriceFrom` i lista usług stanowiska: cennik jest danymi łowiska, niezależnymi od daty i długości
  pobytu. Usługa nie ma własnych reguł. Tekst warunku dopłaty ma jeden dom (metoda obok
  `PriceRule::periodText()`). Przedsprzedaż bierze z `FisheryRulesSummary`, cenę usługi
  z `AdditionalService::priceLabelForVisitor()`, a zasięg i wymagane cechy z `PositionServices`
  i relacji usługi. Widok tylko formatuje.
- **R5. „Martwa" znaczy: nie wygrywa w żadnej dobie od dziś.** Liczy to `PricingConfigurationAudit`
  rozszerzony o opcjonalną datę początku analizy (domyślnie bez niej, więc kalendarz 019 bez zmian).
  Bez drugiej implementacji. Stawka zakończona przed dziś odpada tym samym warunkiem. Dopłaty
  zawieszone (`is_suspended`) się nie pokazują.
- **R6. Kotwice i kolejność zakładek:** „Mapa i terminy" → „Szczegóły" → „Cennik" (`#cennik` /
  `#pricing`) → „Dokumenty" (`#dokumenty` / `#documents`).
- **R7. Widoczność w zakładce „Dokumenty".** Decyzja autora: sekcja rodzaju bez wersji obowiązującej
  się nie pokazuje. Gdy nie ma żadnej, zakładka zostaje z komunikatem wprost („Łowisko nie
  opublikowało dokumentów"), zgodnie z pierwotnym kryterium akceptacji.
- **R8. Treść dokumentu jest w HTML-u, zwinięta w `<details>`.** Zostaje indeksowalna i działa
  bez skryptu. Tytuł i data są widoczne zawsze. Treść przechodzi przez `Str::sanitizeHtml()`
  (`PortalFisheryPage::html()`).
- **R9. Rodzaj „Inne": jeden dokument, bez zmiany modelu.** Decyzja autora z 01.10.2026: każdy
  rodzaj, także „Inne", ma najwyżej jedną wersję obowiązującą. Kilka dokumentów jednego rodzaju
  obowiązujących naraz wymagałoby tożsamości dokumentu niezależnej od rodzaju, czyli odwrócenia
  ADR-017; świadomie poza zakresem. `DocumentType::Other` (`other`) mieści się w regule ADR-017
  „kolejny rodzaj bez nowej mechaniki": kolumny są `string`, więc bez migracji. Panel bierze
  zakładki i opcje z `DocumentType::cases()`. `FisheryPublicationReadiness` nadal wymaga
  wyłącznie regulaminu.

## Powiązane ADR-y

- [ADR-017 — dokumenty i wersje dokumentów](../adr/ADR-017-dokumenty-i-wersje-dokumentow.md) (bez nowej decyzji; aktualizacja informacyjna)
- [ADR-014](../adr/ADR-014-cennik-jako-lista-regul-z-warunkami.md), [ADR-015](../adr/ADR-015-warstwa-oferty-pobytu.md)
