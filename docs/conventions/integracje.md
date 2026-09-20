# Konwencje: integracje zewnętrzne

Obowiązuje przy zmianach w `app/Services/CSOService.php`, `app/Rules/IbanValidation.php`,
`app/Services/SharedFormComponents.php`.

Zadania źródłowe: 001, 012, 013.

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
