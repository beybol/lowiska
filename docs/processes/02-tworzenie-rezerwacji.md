# Tworzenie rezerwacji i kolejność reguł

## W skrócie

Rezerwację zakłada obsługa łowiska — z kalendarza albo pełnym formularzem. Zanim system ją zapisze, sprawdza
termin w **stałej kolejności**. Kolejność ma znaczenie: odmowa podaje **pierwszy** napotkany powód, więc
najpierw sprawdzane są przyczyny najtrwalsze (te, których nie da się „przeczekać").

Trzy rodzaje odpowiedzi:

- **Twarda odmowa — nie da się obejść nigdy:** stanowisko już zajęte w tym terminie, za dużo osób na stanowisku,
  łowisko bez ustawionej doby, brak wolnych egzemplarzy usługi (np. wszystkie łódki wypożyczone).
- **Odmowa, którą obsługa może świadomie przekroczyć z powodem:** termin poza sezonem, blokada stanowiska,
  stanowisko wycofane, za krótki albo za długi pobyt, przerwany pakiet weekendowy, termin za daleko w przyszłości,
  usługa nieoferowana na tym stanowisku. Powód zapisuje się z nazwiskiem i godziną.
- **Brak ceny** — termin bez stawki w cenniku: obsługa może wpisać **cenę uzgodnioną** (też z powodem).

Wędkarz kupujący sam w portalu (etap 4) nie ma żadnych wyjątków — dostaje odmowę.

Po zapisie rezerwacja ma **zamrożone warunki**: cenę z rozbiciem, politykę zwrotu, wersje dokumentów i dane
kontaktowe z tej chwili. Późniejsza zmiana cennika czy regulaminu jej nie zmienia.

Dwie rezerwacje tego samego stanowiska na ten sam termin są niemożliwe — także wtedy, gdy dwie osoby klikną
„Zapisz" w tej samej sekundzie (system zakłada blokadę na stanowisko na czas zapisu).

## Kolejność sprawdzania

```mermaid
flowchart TD
    S["Obsługa: stanowisko, przyjazd, liczba dób,<br/>skład, usługi, dane wędkarza"] --> L["Blokada stanowiska na czas zapisu<br/>(równoległy zapis czeka)"]
    L --> D{"1. Łowisko ma ustawioną dobę<br/>dla każdego dnia pobytu?"}
    D -- "nie" --> R1["Odmowa: brak doby"]
    D -- "tak" --> O{"2. Któraś doba zajęta<br/>przez inną rezerwację?"}
    O -- "tak" --> R2["Odmowa: stanowisko zarezerwowane<br/>(bez wyjątków)"]
    O -- "nie" --> C{"3. Skład mieści się<br/>w pojemności stanowiska?"}
    C -- "nie" --> R3["Odmowa: za dużo osób"]
    C -- "tak" --> OF["4. Oferta (sprzedawalność) — patrz niżej"]
    OF --> OFQ{"Sprzedawalny?"}
    OFQ -- "tak" --> P{"5. Cena z cennika?"}
    OFQ -- "nie, reguła sprzedaży / blokada / wycofanie" --> EX{"Kanał obsługi<br/>i podany powód?"}
    EX -- "nie" --> R4["Odmowa z powodem reguły<br/>(formularz prosi o powód)"]
    EX -- "tak" --> EXOK["Wyjątek zapisany w warunkach"] --> P
    P -- "jest" --> SV
    P -- "brak" --> AG{"Cena uzgodniona z powodem?"}
    AG -- "nie" --> R5["Odmowa: brak ceny w cenniku"]
    AG -- "tak" --> SV
    SV{"6. Usługi dodatkowe"} --> SV1{"Usługa dostępna<br/>na stanowisku i w terminie?"}
    SV1 -- "nie" --> SV2{"Powód wyjątku?"}
    SV2 -- "nie" --> R6["Odmowa: usługa niedostępna"]
    SV2 -- "tak" --> STOCK
    SV1 -- "tak" --> STOCK{"Wolne egzemplarze?"}
    STOCK -- "nie" --> R7["Odmowa: brak egzemplarzy<br/>(bez wyjątków)"]
    STOCK -- "tak" --> W["7. Zapis: rezerwacja, zajęte doby,<br/>egzemplarze usług, zamrożone warunki"]
    W --> MAIL["Mail z prośbą o potwierdzenie<br/>(gdy jest adres) — proces 1"]
```

### Krok 4 — co znaczy „sprzedawalny"

Warstwa oferty sprawdza najpierw **każdą dobę osobno**, potem **cały pobyt**:

```mermaid
flowchart LR
    subgraph doba["Każda doba pobytu"]
      direction TB
      a1["Stanowisko wycofane?"] --> a2["Doba w okresie sprzedaży?"] --> a3["Blokada stanowiska<br/>na tej dobie?"] --> a4["Zajęta?"]
    end
    subgraph pobyt["Cały pobyt"]
      direction TB
      b1["Pakiet (weekend, cały termin)<br/>objęty w całości?"] --> b2["Długość: min. / maks. liczba dób"] --> b3["Horyzont sprzedaży,<br/>a w nim minimum przedsprzedaży"]
    end
    doba --> pobyt --> c["Cena (proces 6)"]
```

Zawieszenie cechy stanowiska (np. „prąd chwilowo nie działa") **nie blokuje** sprzedaży — jest tylko
informacją.

## Dla zespołu

| Krok | Gdzie | Decyzja |
|---|---|---|
| Jedyna ścieżka zapisu, blokady, kolejność 1–7 | `ReservationBooking::write()` | ADR-025 pkt 4, ADR-026 |
| Zajętość sprawdzana wprost, przed ofertą | `PositionAvailability::isOccupied()` | ADR-025 |
| Sprzedawalność doby i pobytu | `PositionAvailability`, `StaySellability` (przez `StayOffer`) | ADR-012, ADR-013, ADR-015 |
| Wyjątki operatora | `ReservationTermsSnapshot::exception()`, `ReservationSource::allowsExceptions()` | ADR-026 pkt 8, ADR-027 pkt 7 |
| Usługi i limit egzemplarzy | `StayOffer::services()`, `ServiceStock::remaining()` | zadanie 020, 047 |
| Formularze | `ReservationFormSchema`, `ReservationFormSubmission` | zadanie 041, 047 |

Konwencje: [`dostepnosc.md`](../conventions/dostepnosc.md) §2–§6, [`rezerwacje.md`](../conventions/rezerwacje.md) §1.
