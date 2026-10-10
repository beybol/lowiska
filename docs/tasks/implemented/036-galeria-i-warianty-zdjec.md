# 036 — Galeria zdjęć łowiska i warianty rozmiarów

> **Etap 2, zadanie 5 — ostatnie w ścieżce** ([roadmapa](../project/ROADMAPA.md)). Założone 30.09.2026.
> Do tego czasu strona łowiska (032) i karty strony głównej (031) pokazują zaślepki z makiet.

## Opis problemu

Zasady działania galerii nigdzie nie są opisane. Stan w kodzie: `fisheries.gallery_images` to
tablica ścieżek w JSON, pliki leżą w Google Cloud Storage, **nie ma miniatur ani wariantów** —
portal musiałby serwować oryginały (często kilka MB ze zdjęcia z telefonu), także na karcie
strony głównej i na telefonie. Ten sam problem ma mapa łowiska (`map_image_path`).

## Wymagania

### Warianty zdjęć — wg [ADR-023](../adr/ADR-023-warianty-rozmiarow-zdjec.md)
- Mechanizm rozmiarów dla zdjęć galerii i mapy łowiska zgodnie z Decyzją ADR-023, z wymaganiami
  wiążącymi każdą opcję: jedna usługa adresów zdjęć; brakujący wariant generowany przy wyświetleniu,
  synchronicznie pod blokadą; bez powiększania (żądanie większego rozmiaru zwraca największy dostępny);
  największy wariant do 1920 px; oryginał nigdy w HTML-u portalu (waga, GPS z EXIF).
- **Skalowanie w przeglądarce przed wysłaniem** (FilePond: do 2560 px, `contain`, bez powiększania) —
  optymalizacja typowej ścieżki; serwer nadal sam obraca według EXIF i usuwa metadane (ADR-023).
- **Bez przenoszenia** już wgranych zdjęć (decyzja autora z 01.10.2026) — Klasztorne i Łopienno wgrywa się
  ponownie po wdrożeniu.
- `srcset` / `sizes`, `loading="lazy"` poza pierwszym ekranem, wymiary albo proporcje w HTML (bez skoku
  układu).

### Zasady galerii
- **Kolejność i okładka:** kolejność z panelu, pierwsze zdjęcie jest okładką (także na karcie
  strony głównej). Pole galerii dostaje `->reorderable()` (R1).
- **Nagłówek strony łowiska (desktop):** 3 i więcej zdjęć — 1 duże + 2 małe, etykieta „Wszystkie
  zdjęcia · N"; 2 zdjęcia — po połowie; 1 — pełna szerokość; **0 — nagłówka ze zdjęciami nie ma**,
  a karta na stronie głównej ma neutralne tło motywu, bez zaślepki udającej zdjęcie (R3).
- **Podgląd pełnoekranowy** po kliknięciu: strzałki, klawiatura, przesuwanie palcem, licznik
  „3 / 12", zamknięcie klawiszem Esc, focus wraca na miejsce — **PhotoSwipe**, podpięty wyłącznie
  w układzie portalu (R2).
- **Telefon:** sekcja „Zdjęcia" na końcu strony — 2 kafelki z „+N", ten sam podgląd.
- **Tekst alternatywny:** `alt` = „{nazwa łowiska} — zdjęcie N", bez podpisów widocznych i bez nowych
  pól (R4).
- **Mapa łowiska:** wariant rozmiaru + ten sam podgląd pełnoekranowy (powiększenie na telefonie).

## Kryteria akceptacji

- [ ] ADR-023 z wypełnioną Decyzją.
- [ ] Portal nie serwuje oryginałów nigdzie; zdjęcie mniejsze niż 1920 px nie jest powiększane,
      a brakujący wariant powstaje przy pierwszym wyświetleniu.
- [ ] Zdjęcie pionowe z telefonu wychodzi we właściwej orientacji, a warianty nie mają metadanych GPS.
- [ ] Układ nagłówka dla 0, 1, 2 i ≥ 3 zdjęć; podgląd pełnoekranowy z klawiaturą i gestami.
- [ ] Kolejność zdjęć zmieniona w panelu zmienia okładkę na karcie i w nagłówku.
- [ ] Ręczna weryfikacja (albo test przeglądarkowy z 035) na telefonie i desktopie.
- [ ] Pełny pakiet testów zielony (T3).

## Zakres testów

- **Tier:** T3 — pełny pakiet
- **Uruchamiamy:** `docker compose exec app php artisan test`
- **Uzasadnienie:** migracje (tabela `media`, usunięcie kolumn zdjęć), `composer.json`, `Dockerfile`
  i `docker/**` — wyzwalacze T3; do tego zmiana formularza łowiska wspólnego dla obu paneli.

## Zakres wyłączeń

- Wideo, zdjęcia stanowisk, zdjęcia wgrywane przez wędkarzy.
- Klikalna mapa stanowisk.
- Podpisy i opisy zdjęć (R4).

## Zmiany dokumentacji

- [ ] `docs/adr/ADR-023` — Decyzja (autor).
- [ ] `docs/conventions/panel-admina.md` §1 — wgrywanie, kolejność, generowanie wariantów przy zapisie.
- [ ] `docs/conventions/panel-wlasciciela.md` — jw., jeśli formularz łowiska właściciela go dotyczy.
- [ ] `docs/conventions/strona-publiczna.md` — zasady galerii i obrazów w portalu (jedna funkcja adresu
      wariantu, układ nagłówka, PhotoSwipe tylko w układzie portalu).
- [ ] `docs/operations/` — komenda przetwarzająca istniejące zdjęcia przy wdrożeniu; `memory_limit`,
      jeśli trzeba go podnieść.
- [ ] `CHANGELOG.md` — wpis.

## Ograniczenia techniczne

- Pliki w GCS (`spatie/laravel-google-cloud-storage`); kolejka na Cloud Run to `sync` (ADR-004).
- Skalowanie przez **libvips** (FFI) w obu obrazach Dockera (R6); `upload_max_filesize = 32M`.
- Portal bez Alpine i Livewire (ADR-022); skrypty portalu przez Vite (ADR-020).

## Rozstrzygnięcia

- **R1. Kolejność ustawia operator przeciąganiem.** Dziś `FileUpload::make('gallery_images')` nie ma
  `reorderable()`, więc kolejności nie da się zmienić; zadanie ją dodaje (w polu wynikającym z Decyzji
  ADR-023). Pierwsze zdjęcie jest okładką.
- **R2. Podgląd pełnoekranowy na PhotoSwipe** — decyzja autora. Gesty, przybliżanie dwoma palcami
  (mapa na telefonie), obsługa klawiatury i fokusu są gotowe; ładowany wyłącznie w układzie portalu.
  To wybór komponentu jednego ekranu (odwracalny wymianą jednego modułu), więc rozstrzygnięcie, nie ADR.
- **R3. Brak zdjęć = brak nagłówka zdjęć**, karta na stronie głównej z neutralnym tłem motywu —
  decyzja autora, zgodna z zasadą „puste pole się nie pokazuje" (`strona-publiczna.md` §1).
- **R4. `alt` generowany: „{nazwa łowiska} — zdjęcie N"** — decyzja autora; bez nowych pól. Mapa zachowuje
  dzisiejszy `alt`.
- **R5. Oryginał oczyszczany w tym samym buckecie, nie na dysku prywatnym** — decyzja autora z 01.10.2026.
  Bucket na Cloud Run ma jednolity dostęp (UBLA) z publicznym odczytem, a blokada w Caddy nie obejmuje plików
  z `storage.googleapis.com`. Przy dodaniu: obrót według EXIF, najwyżej 2560 px, zapis bez metadanych (GPS).
- **R6. libvips od razu, bez pomiarów** — decyzja autora: `spatie/image` ma sterownik Vips, więc kryterium
  wydajności z pomiarem i droga wyjścia przez Imagick są zbędne. Zmiana obu obrazów Dockera i CI (`ffi`).
- **R7. Dziennik zmian** — dodanie i usunięcie zdjęcia logowane jawnie na łowisku; kolejność bez wpisu
  (zmienia układ, nie treść).

## Powiązane ADR-y

- [ADR-023 — Warianty rozmiarów zdjęć łowiska](../adr/ADR-023-warianty-rozmiarow-zdjec.md) — opcja B (medialibrary), z aktualizacją z 01.10.2026 (libvips, oczyszczanie oryginału).
- [ADR-004](../adr/ADR-004-wykonywanie-kolejki-na-cloud-run.md) (kolejka `sync`), [ADR-020](../adr/ADR-020-tailwind-portalu-i-potok-zasobow.md), [ADR-022](../adr/ADR-022-mechanizm-interakcji-portalu.md).
