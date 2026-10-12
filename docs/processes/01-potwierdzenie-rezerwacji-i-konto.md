# Potwierdzenie rezerwacji i połączenie z kontem

## W skrócie

Gdy obsługa łowiska zapisze rezerwację z adresem e-mail wędkarza, wędkarz dostaje mail z dwoma przyciskami:
**„Potwierdź rezerwację"** i **„Zrezygnuj / to nie ja"**.

- **Potwierdzenie nie wymaga konta.** Jedno kliknięcie wystarcza — kliknięcie dowodzi, że wędkarz ma dostęp do
  skrzynki, a tego potrzebuje łowisko.
- **Połączenie z kontem jest dobrowolne** i następuje dopiero po potwierdzeniu. Daje wędkarzowi historię
  rezerwacji w jednym miejscu, a łowisku — aktualne dane z konta wędkarza.
  - Wędkarz **ma już konto** na ten adres → musi się na nie **zalogować** (hasło albo Google, potem kod z maila).
  - Wędkarz **nie ma konta** → zakłada je (hasłem albo przez Google) **na adres z rezerwacji**.
  - Przy zakładaniu konta akceptuje dokumenty Fisherya i łowiska, przy łączeniu istniejącego konta — tylko
    dokumenty łowiska.
  - Wędkarz zalogowany na **inne konto** dostaje odmowę i propozycję wylogowania.
  - **„Nie teraz"** — rezerwacja zostaje potwierdzona, a link z maila pozwala połączyć konto przez 14 dni.
- **Łowisko** zawsze widzi ten sam komunikat „wysłano prośbę o potwierdzenie" — nie dowiaduje się, czy adres ma
  konto w portalu. W panelu widzi osobno **status rezerwacji** (np. potwierdzona) i **dane wędkarza**
  („wpisane ręcznie" albo „potwierdzone konto").
- **„To nie ja"** cofa prośbę, rezerwacja trafia do „Do kontaktu" (nie jest anulowana). **„Zrezygnuj"** anuluje
  rezerwację ze zwrotem według polityki zwrotu.

Dlaczego tak: link z maila mógł trafić w niepowołane ręce (przekazany mail, podgląd w skrzynce). Dlatego link
potwierdza rezerwację, ale **nie daje dostępu do konta** — do tego trzeba się zalogować.

## Przebieg

```mermaid
flowchart TD
    A["Obsługa zapisuje rezerwację z adresem e-mail"] --> B{"Łowisko wymaga potwierdzeń?"}
    B -- "tak" --> C["Rezerwacja niepotwierdzona,<br/>z terminem wygaśnięcia"]
    B -- "nie" --> C2["Rezerwacja potwierdzona"]
    C --> M["Mail do wędkarza:<br/>Potwierdź rezerwację / Zrezygnuj, to nie ja"]
    C2 --> M
    M --> L{"Co klika wędkarz?"}
    L -- "To nie ja" --> NM["Prośba cofnięta,<br/>rezerwacja → Do kontaktu"]
    L -- "Zrezygnuj" --> RS["Rezerwacja anulowana,<br/>zwrot wg polityki"]
    L -- "Potwierdź rezerwację" --> V{"Link ważny?<br/>(14 dni, ten sam adres)"}
    V -- "nie" --> X["Komunikat: link wygasł / nieważny"]
    V -- "tak" --> OK["Rezerwacja potwierdzona<br/>(bez konta)"]
    OK --> INV{"Połączyć z kontem?"}
    INV -- "Nie teraz" --> LATER["Koniec. Link działa dalej do 14 dni"]
    INV -- "Połącz konto" --> S{"Kim jest wędkarz?"}
    S -- "ma konto na ten adres,<br/>niezalogowany" --> LOGIN["Logowanie: hasło albo Google<br/>+ kod z maila"]
    LOGIN --> BACK["Powrót na stronę łączenia"]
    S -- "nie ma konta" --> REG["Zakłada konto hasłem albo przez Google<br/>na adres z rezerwacji<br/>+ zgody Fisherya i łowiska"]
    S -- "zalogowany na właściwe konto" --> BACK
    S -- "zalogowany na inne konto" --> WRONG["Odmowa + Wyloguj się"]
    WRONG --> S
    BACK --> CONS["Zgody łowiska<br/>(+ wybór telefonu, gdy różny)"]
    REG --> LINKED
    CONS --> LINKED["Konto połączone z łowiskiem.<br/>Łowisko widzi: potwierdzone konto"]
```

## Stan wędkarza w łowisku

```mermaid
stateDiagram-v2
    [*] --> DaneOdLowiska: rezerwacja bez adresu e-mail
    [*] --> ProsbaWyslana: rezerwacja z adresem e-mail
    ProsbaWyslana --> ProsbaWyslana: wędkarz potwierdził rezerwację (bez konta)
    ProsbaWyslana --> PotwierdzoneKonto: wędkarz połączył konto
    ProsbaWyslana --> DaneOdLowiska: „To nie ja” albo zmiana adresu przez obsługę
    ProsbaWyslana --> DaneOdLowiska: link wygasł (14 dni)
    PotwierdzoneKonto --> [*]
```

W panelu „Prośba wysłana" i „Wpisane ręcznie" wyglądają tak samo — **„Wpisane ręcznie"**. Dopiero połączenie
konta zmienia to na **„Potwierdzone konto"**.

## Dla zespołu

| Krok | Gdzie | Decyzja |
|---|---|---|
| Wysyłka prośby, limity | `ReservationConfirmation::afterBooking()` → `AnglerLinking::requestConfirmation()` | ADR-024 pkt 8 |
| Potwierdzenie bez konta | `ReservationLinkController::confirmSubmit()` → `AnglerLinking::confirmReservations()` | ADR-024 akt. 2026-10-11 |
| Znacznik łączenia i powrót po logowaniu | `AnglerLinkSession`, `PanelHome::redirectAfterSignIn()`, `TwoFactorController` | zadanie 049, R2 |
| Strona łączenia (tryby) | `ReservationLinkController::account()` / `accountSubmit()` | zadanie 049 |
| Połączenie / konto z linku | `AnglerLinking::link()` / `registerAndLink()` | ADR-024 akt., ADR-028 |
| Zgody | `ConsentRegistry` | ADR-028 |
| Rezygnacja, „to nie ja" | `AnglerLinking::resign()` / `notMe()` | zadanie 043, 047 |

Konwencje: [`rezerwacje.md`](../conventions/rezerwacje.md) §3, [`strona-publiczna.md`](../conventions/strona-publiczna.md) §8,
[`autoryzacja.md`](../conventions/autoryzacja.md) §6. Testy: `AnglerLinkingTest`, `AccountLinkingConsentsTest`.
