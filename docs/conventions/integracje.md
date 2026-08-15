# Konwencje: integracje zewnętrzne

Obowiązuje przy zmianach w `app/Services/CSOService.php`, `app/Rules/IbanValidation.php`,
`app/Helpers/**`.

Zadania źródłowe: 001.

---

## 1. Konfiguracja tylko przez `config()`

- **Kod w `app/` czyta ustawienia wyłącznie przez `config()`, nigdy przez `env()`.** `env()`
  wolno wywoływać jedynie wewnątrz plików `config/**`. Powód: `php artisan config:cache` —
  standardowy krok obrazu produkcyjnego — przestaje ładować `.env` po zbudowaniu pamięci
  podręcznej konfiguracji, a `env()` wywołane poza `config/` zaczyna po cichu zwracać `null`.
- Klucz rejestru GUS: `config('services.cso.key')` (`CSOService::fetchAddress()`).
- Adres administratora: `config('app.admin_email')` (`Helper::fetchDataFromCSO()`).
- **Nazwy zmiennych środowiskowych zapisujemy w całości WIELKIMI literami** (`CSO_KEY`,
  `ADMIN_EMAIL`) — konwencja obowiązująca w całym `.env`; wcześniejsze `CSO_Key` było wyjątkiem
  i zostało wyrównane. Zmiana nazwy zmiennej wymaga korekty w `.env`, `.env.example` **oraz**
  w konfiguracji wdrożenia (sekrety w GCP).
- ⚠️ **Strażnikiem tej reguły jest test `tests/Unit/NoEnvInAppTest.php`**, nie statyczna analiza —
  skanuje katalog `app/` w poszukiwaniu wywołania `env(`. Nie dziedziczy po `Tests\TestCase`
  (nie potrzebuje aplikacji ani bazy). Nie wracaj do wprowadzania PHPStan/Larastan tylko po to,
  by zdublować to, co już egzekwuje ten test — to osobna decyzja z własnym kosztem
  (nowa zależność w `composer.json`, poziom ścisłości, komenda w workflow).
