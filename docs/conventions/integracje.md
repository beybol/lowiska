# Konwencje: integracje zewnętrzne

Obowiązuje przy zmianach w `app/Services/CSOService.php`, `app/Rules/IbanValidation.php`,
`app/Services/SharedFormComponents.php`, `app/Mail/**`, `resources/views/emails/**` i `resources/views/components/mail/**`.

Zadania źródłowe: 001, 012, 013, 040.

⚠️ **Granica między integracją a formularzem.** `CSOService` odpytuje rejestr GUS i **nie zna
Filamenta**. `SharedFormComponents::fetchDataFromCSO()` jest klejem formularza: przyjmuje
`Get`/`Set`, waliduje wejście, ustawia stan i komunikaty. Nie przenoś tej drugiej do
`CSOService` — nazwa kusi, ale wciągnęłaby zależność od Filamenta do klasy integracyjnej.

⚠️ **`app/Helpers/` nie istnieje od zadania 013.** Dawny `Helper` został rozbity na klasy
w `app/Services/`: `FisheryAccess` (bramki dostępu), `FisheryNavigation` (adresy i okruszki),
`SharedFormComponents` (pola formularzy), `DictionaryOptions` (listy słownikowe),
`AdditionalServiceSync`, `OwnerRoleProvisioner`. Bramki opisuje
[`autoryzacja.md`](autoryzacja.md) §4, adresy sekcji — [`panel-wlasciciela.md`](panel-wlasciciela.md) §2.

---

## 1. Konfiguracja tylko przez `config()`

- **Kod w `app/` czyta ustawienia wyłącznie przez `config()`, nigdy przez `env()`.** `env()`
  wolno wywoływać jedynie wewnątrz plików `config/**`. Powód: `php artisan config:cache` —
  standardowy krok obrazu produkcyjnego — przestaje ładować `.env` po zbudowaniu pamięci
  podręcznej konfiguracji, a `env()` wywołane poza `config/` zaczyna po cichu zwracać `null`.
- Klucz rejestru GUS: `config('services.cso.key')` (`CSOService::fetchAddress()`).
- Adres administratora: `config('app.admin_email')` (`SharedFormComponents::fetchDataFromCSO()`).
- **Nazwy zmiennych środowiskowych zapisujemy w całości WIELKIMI literami** (`CSO_KEY`,
  `ADMIN_EMAIL`) — konwencja obowiązująca w całym `.env`; wcześniejsze `CSO_Key` było wyjątkiem
  i zostało wyrównane. Zmiana nazwy zmiennej wymaga korekty w `.env`, `.env.example` **oraz**
  w konfiguracji wdrożenia (sekrety w GCP).
- ⚠️ **Strażnikiem tej reguły jest test `tests/Unit/NoEnvInAppTest.php`**, nie statyczna analiza —
  skanuje katalog `app/` w poszukiwaniu wywołania `env(`. Nie dziedziczy po `Tests\TestCase`
  (nie potrzebuje aplikacji ani bazy). Nie wracaj do wprowadzania PHPStan/Larastan tylko po to,
  by zdublować to, co już egzekwuje ten test — to osobna decyzja z własnym kosztem
  (nowa zależność w `composer.json`, poziom ścisłości, komenda w workflow).

---

## 2. Maile — wyłącznie przez wspólny layout i komponenty

Zadanie źródłowe: 040.

- **Każda wiadomość aplikacji dziedziczy z [`App\Mail\PortalMailable`](../../app/Mail/PortalMailable.php)**
  i ma DWA widoki: HTML na komponentach `x-mail.*` (`resources/views/components/mail/`: `layout`, `button`,
  `notice`, `details`, `fishery-message`, `text`) oraz wariant tekstowy na `emails.text.layout`. Klasa bazowa
  daje temat z prefiksem marki („Fisherya · nazwa łowiska — temat"), multipart, nagłówek `X-Fisherya-Mail`
  z kluczem wiadomości i język odbiorcy (jeden z `PortalRoutes::LOCALES`, inaczej bieżący).
- ⚠️ **Kolory i czcionka mają jeden dom — `App\Mail\MailTheme`** (tokeny „Głębi" z `portal.css` przepisane jako
  literały, bo poczta nie czyta arkuszy ani zmiennych CSS). Styl wpisany w widok wiadomości albo nowy kolor
  w komponencie to drugi dom tej samej stałej. Komponenty piszą style **inline**, tabelami, bez Flexboxa,
  Grida i klas Tailwinda.
- **Powiadomienia też idą przez layout:** `VerifyEmail::toMailUsing()` (`AppServiceProvider`) i
  `SendTwoFactorCode::toMail()` zwracają `PortalMailable` z `->to(...)`, nie `MailMessage` — domyślny szablon
  Laravela nie jest używany.
- **Tekst operatora w mailu** (komunikat łowiska) wyłącznie przez `x-mail.fishery-message` — escapuje treść
  i zachowuje akapity. Wariant tekstowy wypisuje wartości bez escapowania (`{!! !!}`), bo to czysty tekst.
- **Logo i linki z `config('app.url')`**, nie z żądania — mail czyta się poza aplikacją (`MailTheme::logoUrl()`).
- **Nowa wiadomość → wpis w [`App\Mail\MailPreviews`](../../app/Mail/MailPreviews.php)** z danymi przykładowymi
  bez zapisu w bazie. Podgląd: `/dev/mail-preview` (HTML i `…/text`), wyłącznie w `local` i `testing` — trasa
  nie istnieje gdzie indziej, a kontroler sprawdza środowisko drugi raz. `MailTemplateTest` renderuje oba
  warianty każdej wiadomości z rejestru.

Przykład nowej wiadomości:

```php
class ReservationCancelledMail extends PortalMailable
{
    public function __construct(Fishery $fishery, public readonly Reservation $reservation, ?string $locale = null)
    {
        parent::__construct($fishery, $locale);
    }

    public static function key(): string { return 'reservation-cancelled'; }
    protected function subjectLine(): string { return __('Your reservation was cancelled'); }
    protected function htmlView(): string { return 'emails.reservation-cancelled'; }
    protected function textView(): string { return 'emails.reservation-cancelled-text'; }
}
```

```blade
<x-mail.layout :title="$subjectLine" :fishery="$fishery" :reason="$reason">
    <x-mail.text>{{ __('...') }}</x-mail.text>
    <x-mail.details :rows="[__('Position') => $reservation->position->name]" />
    <x-mail.button :href="$url">{{ __('...') }}</x-mail.button>
</x-mail.layout>
```
