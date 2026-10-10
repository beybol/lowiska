# Konwencje: rezerwacje — ekrany operatora i przepływy wędkarza

Obowiązuje przy zmianach w `app/Filament/Resources/ReservationResource/**`,
`app/Filament/Resources/ReservationResource.php`, `FisheryResource\Pages\ManageReservations`,
warstwie „Rezerwacje" kalendarza, `app/Services/Reservation*` (poza samą ścieżką zapisu)
i w mailach o rezerwacjach.

⚠️ **Rdzeń — model, sloty, jedna ścieżka zapisu `ReservationBooking`, zamrożone warunki — opisuje
[`dostepnosc.md`](dostepnosc.md) §6** (oś „czy wolno sprzedać") i [`cennik.md`](cennik.md) (warunki
i zwrot). Ten plik niesie reguły EKRANÓW i PRZEPŁYWÓW, które na tym rdzeniu stoją.

Zadania źródłowe: 041. Decyzje: ADR-024…027.

---

## 1. Lista, formularz i szczegóły (zadanie 041)

- **„Rezerwacje" (`ManageReservations`) stoją w sub-nawigacji ZARAZ ZA „Kalendarzem"** — z kalendarza
  się je zakłada (042). Strona to `ManageRelatedRecords` po relacji `reservations`, tabela deleguje do
  `ReservationResource::table()`, zakładki: „Bieżące i nadchodzące" (domyślna), „Do kontaktu", „Wszystkie".
- **Plakietka** = niepotwierdzone, którym termin potwierdzenia mija w ciągu 24 h (`Reservation::expiringWithin()`),
  plus „Do kontaktu" (`Reservation::toContact()`); liczona w `badgeFor()` z rekordu sub-nawigacji.
- **`ReservationResource` jest zasobem podrzędnym łowiska bez pozycji w głównej nawigacji** — istnieje,
  bo polityka odpowiada zasobowi (`autoryzacja.md` §5). Strony: samodzielna lista (`?fishery=`, deep-link),
  **formularz C jako PEŁNA strona** (`CreateReservation`, `Grid` + `Section`, parametry `position`, `from`,
  `nights` z adresu) i **szczegóły D** (`ViewReservation`, infolista z zamrożonymi warunkami i historią
  z kanału `reservations`). Panel właściciela rejestruje zasób jawnie w `OwnerPanelProvider`.
- ⚠️ **Formularz C zapisuje przez `ReservationBooking`, nie `Model::create()`** — `handleRecordCreation()`
  woła serwis, a odmowa wraca powiadomieniem i `halt()` (formularz zostaje). Stanowisko, relacja wędkarza
  i łowisko przychodzą od klienta, więc przechodzą przez zawężenie do łowiska (`forceVerifiedFishery()`,
  zapytania z `where('fishery_id', …)`). Podgląd oferty w formularzu pyta wyłącznie `StayOffer`.
- ⚠️ **Świadome odstępstwo od „akcje prowadzą na pełne strony" (§2): krótkie akcje na rezerwacji są
  MODALAMI** — edycja, anulacja, potwierdzenie, status płatności, „Oznacz jako załatwione"
  ([`ReservationActions`](../../app/Filament/Resources/ReservationResource/ReservationActions.php)),
  współdzielone przez listę, szczegóły i kalendarz. Warunek odstępstwa: rekord z zawężonego zapytania,
  polityka pytana w `visible()` **i** w `action()`, zapis przez serwis, który sam pilnuje łowiska.
  Nowa akcja na rezerwacji spełnia te trzy warunki albo idzie na pełną stronę.
- **Anulacja pobytu w toku** wymaga zaznaczenia potwierdzenia w modalu (walidacja `accepted`); akcja
  zbiorcza takie pobyty pomija. Zwrot: z warunków albo wyjątek z powodem. Po anulacji mail do wędkarza
  (`ReservationMailer`); brak adresu albo błąd wysyłki → „Do kontaktu".
- **Wyszukiwanie wędkarza wyłącznie w relacjach TEGO łowiska** (ADR-024): tabela po zawężonym zapytaniu,
  podpowiedzi w formularzu z `fishery_anglers` łowiska — nigdy z `users`.
