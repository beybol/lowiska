# ADR-023 — Warianty rozmiarów zdjęć łowiska

- **Status:** accepted
- **Data:** 2026-10-01
- **Zadanie:** [036 — Galeria zdjęć łowiska i warianty rozmiarów](../tasks/036-galeria-i-warianty-zdjec.md)

## Kontekst

Zdjęcia łowiska to `fisheries.gallery_images` (JSON, tablica ścieżek) i `fisheries.map_image_path`.
Wgrywa je `FileUpload` Filamenta (JPEG, PNG lub WebP do 32 MB, `upload_max_filesize`) na dysk
uploadów: lokalnie `public`, na Cloud Run `gcs` z widocznością publiczną (`panel-admina.md` §1).
Wariantów i miniatur nie ma, więc portal musiałby serwować kilkumegabajtowe oryginały z telefonu
na karcie strony głównej, w nagłówku łowiska i na telefonie.

Ograniczenia środowiska:
- **Kolejka na Cloud Run to `sync`** (ADR-004): nie ma workera. Praca „po odpowiedzi” jest
  niewiarygodna, bo przy rozliczaniu za żądania Cloud Run dławi procesor po wysłaniu odpowiedzi.
- Instancja schodzi do zera i nie ma trwałego dysku lokalnego.
- Obraz PHP ma GD i `exif`; nie ma Imagick, libvips ani FFI.
- Podgląd pełnoekranowy (PhotoSwipe, R2 zadania 036) potrzebuje szerokości i wysokości zdjęcia przed
  jego pobraniem; `srcset` musi podawać **prawdziwe** szerokości plików.
- **Zdjęcia z telefonu niosą metadane EXIF, w tym GPS** i orientację. Oryginał w publicznym buckecie
  ujawnia położenie, a zdjęcie bez obrotu według EXIF wychodzi przewrócone.
- **Danych nie trzeba przenosić** (decyzja autora z 01.10.2026): zdjęcia Klasztornego i Łopienna
  można wgrać ponownie, więc zmiana modelu danych jest na tym etapie tania.

Wymagania autora dla mechanizmu (01.10.2026), wiążące dla każdej opcji:
1. **Jedna usługa adresów zdjęć** — widok pyta o „zdjęcie X w szerokości N” i nigdy nie składa ścieżki.
2. **Brakujący wariant powstaje przy wyświetleniu**, synchronicznie w żądaniu, pod blokadą (dwa żądania
   nie liczą tego samego). Ścieżką podstawową pozostaje generowanie przy zapisie, więc to stan awaryjny.
3. **Bez powiększania:** z oryginału 800 px nie powstaje 960 ani 1920. Żądanie 1920 zwraca największy
   dostępny wariant, a `srcset` wymienia tylko istniejące szerokości.
4. **Największy wariant ma najwyżej 1920 px** po dłuższym boku (WebP, bez metadanych).
5. **Oryginał nigdy nie trafia do HTML-a portalu** (waga i GPS).

Dlaczego to ADR, a nie rozstrzygnięcie w zadaniu:
- **Zasięg:** mechanizm obsłuży każde zdjęcie portalu, także przyszłe (zdjęcia stanowisk, logotypy),
  i każdy widok, który je renderuje.
- **Koszt odwrócenia:** po starcie produkcyjnym zmiana oznacza inny układ plików w buckecie, inny model
  danych i przeniesienie wszystkich zdjęć; opcja z libvips zmienia też oba obrazy Dockera.
- **Uzasadnienie warte zapamiętania:** dlaczego generowanie odbywa się w żądaniu (ADR-004 i koszty
  platformy) i dlaczego oryginał jest ukryty (GPS) — tego nie widać w kodzie.

## Alternatywy

### Opcja A — własna usługa wariantów na libvips, dane w obecnym modelu
`gallery_images` zostaje tablicą ścieżek dla `FileUpload`. Nowa kolumna JSON (np. `fisheries.image_meta`)
trzyma dla każdej ścieżki wymiary oryginału i **faktyczne** szerokości wygenerowanych wariantów; to ona,
a nie bucket, mówi, co istnieje (sprawdzanie pliku w GCS to zapytanie HTTP na zdjęcie przy każdym renderze).
Własna usługa w `app/Services/`: generuje warianty przy zapisie łowiska, przy wyświetleniu uzupełnia braki
pod blokadą (`Cache::lock`), usuwa warianty zdjęć zdjętych z galerii. Skalowanie przez **libvips**
(`jcupitt/vips` przez FFI): obrót według EXIF, usunięcie metadanych, WebP.

**Zalety:**
- libvips jest **4–8× szybszy od GD i zużywa kilka razy mniej pamięci** (przetwarza strumieniowo), co ma
  znaczenie dokładnie tam, gdzie autor chce generować w żądaniu wędkarza.
- Pełna kontrola: rozmiary 480 / 960 / 1920, zasady 1–5 z kontekstu wprost w kodzie.
- Formularz i dziennik zmian łowiska bez zmian (`gallery_images` dalej jest polem modelu).

**Wady:**
- Najwięcej własnego kodu: metadane, sieroty, blokady, komenda przetwarzająca, `srcset`, kolejność.
- **Zmiana obu obrazów Dockera** (dev i `prod`/FrankenPHP): libvips i rozszerzenie FFI, `ffi.enable`
  w `prod.ini`. FFI pozwala PHP wołać dowolną bibliotekę C, więc konfigurację trzeba zawęzić
  (`ffi.enable=preload` zamiast `true`).
- Oryginał w publicznym buckecie z GPS — trzeba go zapisywać jako prywatny albo nadpisywać kopią bez
  metadanych.

### Opcja B — `spatie/laravel-medialibrary` z oficjalnym pluginem Filamenta
Model danych przechodzi na tabelę `media` (polimorficzną): `Fishery` implementuje `HasMedia`, kolekcje
`gallery` i `map`. Formularz używa `SpatieMediaLibraryFileUpload` (plugin
`filament/spatie-laravel-media-library-plugin` 5.x, zgodny z Filamentem 5; biblioteka 11.x wspiera
Laravel 13). Warianty to **konwersje** (`nonQueued()`, więc powstają w żądaniu zapisu, zgodnie z ADR-004),
a `srcset` daje `withResponsiveImages()` z własnym kalkulatorem szerokości (480 / 960 / 1920, tylko
mniejsze od oryginału). Wymagania 1–2 realizuje cienka usługa nad biblioteką: czyta `generated_conversions`
i przy braku wywołuje generowanie pod blokadą. Oryginały trafiają na **dysk prywatny**, a konwersje na
publiczny (`conversions_disk`), co załatwia wymaganie 5 i problem GPS u źródła.

**Zalety:**
- Większość mechaniki jest gotowa i przetestowana: kolejność (`order_column`, przeciąganie w pluginie),
  śledzenie wygenerowanych konwersji, sprzątanie plików razem z rekordem, komenda
  `media-library:regenerate`, `srcset`, podglądy w panelu.
- **Model wielokrotnego użytku:** zdjęcia stanowisk, logotypy czy dokumenty PDF to kolejne kolekcje,
  bez nowych kolumn i bez powtarzania mechaniki.
- **Opisy zdjęć mają gdzie zamieszkać** (dziś wyłączone, R4 zadania 036): każde zdjęcie ma własny wiersz
  i identyfikator, więc opis to `custom_properties` albo kolumna we własnym modelu `Media` (patrz
  „Droga rozwoju: opisy zdjęć”). W opcji A zdjęcie jest tylko ścieżką w tablicy, bez identyfikatora.
- Przy decyzji „bez przenoszenia danych” zmiana modelu kosztuje tylko przepięcie formularza, widoków,
  `FisheryPublicationReadiness` i fabryk.

**Wady:**
- **Nie działa z libvips.** Konwersje idą przez `spatie/image`, który (według naszej wiedzy, do potwierdzenia
  przy implementacji) obsługuje wyłącznie GD i Imagick. Skalowanie w żądaniu będzie więc wolniejsze
  i bardziej pamięciożerne niż w opcji A; trzeba sprawdzić `memory_limit` (GD trzyma zdekodowaną bitmapę,
  ok. 50 MB przy 12 MP).
- Zmiany galerii przestają być zmianą pól `Fishery`, więc dziennik zmian (`dziennik-zmian.md`) ich nie
  zobaczy bez osobnego logowania na modelu `Media`.
- Biblioteka narzuca układ ścieżek i tabelę ogólnego przeznaczenia; przy nietypowych potrzebach trzeba
  znać jej punkty rozszerzeń (`PathGenerator`, `WidthCalculator`).
- Tabela polimorficzna: brak kluczy obcych i kaskad w bazie, spójność pilnuje biblioteka. Odejście od niej
  po starcie produkcyjnym oznacza przeniesienie danych i plików.
- `Media` nie ma miękkiego usuwania: usunięcie zdjęcia od razu kasuje pliki. Jeśli coś miałoby kiedyś
  trwale wskazywać na zdjęcie (np. zgłoszenie treści, DSA), trzeba to obsłużyć osobno.
- Nowe zależności w `composer.json` (biblioteka, plugin, `spatie/image`) i migracja tabeli `media`.

### Opcja C — przeskalowanie w locie (Glide)
Trasa Laravel skaluje zdjęcie przy pierwszym żądaniu danego rozmiaru i zapisuje wynik w buckecie.

**Zalety:**
- Brak generowania przy zapisie; nowy rozmiar to zmiana w widoku.

**Wady:**
- Każde pierwsze wyświetlenie płaci skalowaniem, także na zimnym starcie — to, co w opcjach A i B jest
  stanem awaryjnym, tu jest ścieżką podstawową.
- Wymaga podpisanych adresów (inaczej każdy zamawia dowolny rozmiar i obciąża instancję).

### Opcja D — usługa obrazów lub CDN z transformacją
Cloudflare Images, imgix albo Cloud CDN z transformacją.

**Zalety:**
- Najlepsza wydajność i formaty bez kodu w aplikacji.

**Wady:**
- Koszt stały i nowa infrastruktura w `gcp-foundation`, sprzeczne z polityką „no always-on” (ADR-004);
  zależność od dostawcy w każdym adresie obrazka.

## Rekomendacja

**Opcja B (medialibrary) na sterowniku GD**, z cienką usługą realizującą wymagania 1–5.

Uzasadnienie: autor zgodził się na brak przenoszenia danych, więc znika jedyny duży koszt zmiany modelu.
W zamian odpada większość kodu, który w opcji A trzeba napisać i utrzymywać samemu (kolejność, śledzenie
wariantów, sieroty, regeneracja, `srcset`, podglądy w panelu), a model od razu obsłuży przyszłe zdjęcia
stanowisk. Rozdzielenie dysków załatwia GPS przez konfigurację, nie przez własny kod.

Utrata libvips jest realnym kosztem, ale ograniczonym: przy konwersjach `nonQueued()` generowanie
przy wyświetleniu jest rzadkim stanem awaryjnym, a nie ścieżką podstawową. Koszt GD płaci operator
przy zapisie, a wędkarz tylko wyjątkowo. Gdyby pomiar na zdjęciach z telefonu pokazał, że to za wolno,
drogą wyjścia jest Imagick w obrazie Dockera — w ramach tej samej biblioteki, bez zmiany modelu.

**Opcja A** jest właściwa, jeśli priorytetem jest szybkość generowania w żądaniu wędkarza i pełna kontrola
nad plikami, kosztem większej ilości kodu i zmian w obu obrazach Dockera.

Konsekwencje dla implementacji (opcja B):
- `Fishery` implementuje `HasMedia`; kolekcje `gallery` (wiele, kolejność) i `map` (pojedyncza).
  Kolumny `gallery_images` i `map_image_path` znikają; zdjęć nie przenosimy, wgrywa się je ponownie.
- Konwersje 480 / 960 / 1920 px WebP, `fit` bez powiększania, `nonQueued()`; responsive images z własnym
  kalkulatorem szerokości.
- Oryginał na dysku prywatnym, konwersje na publicznym; obrót według EXIF i usunięcie metadanych
  w konwersjach — **do weryfikacji testem na zdjęciu z telefonu**.
- Usługa adresów: najbliższa istniejąca szerokość ≤ żądanej, inaczej największa; brak jakiejkolwiek
  konwersji → generowanie pod blokadą w żądaniu. Wymiary dla PhotoSwipe z największej konwersji.
- Dziennik zmian: decyzja w zadaniu, czy `Media` dostaje logowanie.
- T3 zadania 036 (migracja, `composer.json`).

### Skalowanie w przeglądarce — wspólne dla opcji A i B

Pole uploadu (FilePond w Filamencie) zmniejsza zdjęcie **przed wysłaniem**: `imageResizeTargetWidth(2560)`,
`imageResizeTargetHeight(2560)`, `imageResizeMode('contain')`, `imageResizeUpscale(false)`. Serwer dekoduje
wtedy ok. 5 MP zamiast 12 MP (2–3× mniej pracy i pamięci), a upload jest kilka razy mniejszy, co ma znaczenie
przy słabym zasięgu nad wodą. Obraz przechodzi przez canvas, więc metadane EXIF (w tym GPS) prawdopodobnie
znikają, a orientację obsługuje FilePond — **do weryfikacji przy implementacji**.

⚠️ **Serwer nie może na tym polegać.** Skalowanie w przeglądarce da się ominąć (żądanie z pominięciem
formularza), więc serwer nadal przyjmuje dowolny dozwolony plik do 32 MB, sam obraca według EXIF i usuwa
metadane. Skalowanie w przeglądarce to optymalizacja typowej ścieżki, nie zabezpieczenie.

### Droga rozwoju: opisy zdjęć

Opisy są dziś wyłączone (R4 zadania 036: `alt` generowany). Gdy wejdą, w opcji B są trzy drogi, od najprostszej:
1. **`custom_properties`** zdjęcia (`['caption' => '…']`) — wystarcza dla jednego tekstu do wyświetlenia;
   treści operatora są jednojęzyczne (D8), więc bez tłumaczeń.
2. **Własny model `Media`** (`media_model` w konfiguracji biblioteki) z kolumnami dodanymi migracją
   (`caption`, `alt`) — gdy opis ma być wyszukiwany, walidowany albo moderowany.
3. **Osobna tabela** z kluczem obcym do `media.id` — gdy opis stanie się osobnym bytem (np. z historią zmian).

W obu opcjach edycja opisu przy każdym zdjęciu to własny widok: Filament nie ma gotowego pola na metadane
pojedynczego pliku.

### Kryterium wydajności i droga wyjścia

Wydajność nie jest pomijana, tylko **mierzona**. Upload zdjęć jest rzadki (charakter systemu jest daleki
od przetwarzania zdjęć), ale musi mieścić się w progach, mierzonych na **stagingu na Cloud Run** na zdjęciach
z telefonu:
- zapis formularza z **10 nowymi zdjęciami: poniżej 20 s**;
- uzupełnienie brakującego wariantu przy wyświetleniu: **poniżej 2 s na zdjęcie**.

Przekroczenie progu uruchamia drogę wyjścia: **Imagick w obrazie Dockera** (ten sam `spatie/image`, bez
zmiany modelu). Szacunek przed pomiarem: GD bez skalowania w przeglądarce to ok. 3–4 s na zdjęcie 12 MP
(każda konwersja dekoduje oryginał osobno), ze skalowaniem w przeglądarce ok. 10–15 s na 10 zdjęć.

## Decyzja
Decyzja: B

---

## Aktualizacja (2026-10-01) — ustalenia przy implementacji zadania 036

Trzy rozstrzygnięcia autora podjęte w trakcie implementacji; zmieniają konsekwencje opcji B, nie samą decyzję.

1. **Opcja B działa z libvips — wada „nie działa z libvips” była błędna.** `spatie/image` 3.9 ma sterownik
   Vips, a medialibrary przyjmuje `image_driver = vips`. Silnikiem jest więc **libvips od razu**
   (`IMAGE_DRIVER=vips`, `jcupitt/vips` przez FFI, `libvips-tools` i rozszerzenie `ffi` w obu obrazach Dockera,
   `ffi.enable = true` w `docker/php/*.ini`). **Kryterium wydajności z pomiarem zostaje zdjęte** — przy libvips
   pomiar nie ma czego rozstrzygać, a droga wyjścia przez Imagick traci sens.
2. **Oryginały nie trafiają na dysk prywatny, tylko są oczyszczane.** Na Cloud Run jest jeden bucket z jednolitym
   dostępem (UBLA) i publicznym odczytem — pojedynczego pliku nie da się w nim ukryć, a prywatny bucket wymaga
   zmiany w `gcp-foundation`. Blokada w Caddy nie pomoże: pliki idą z `storage.googleapis.com`, z pominięciem
   aplikacji. Dlatego przy dodaniu (`SanitizingMediaFilesystem`, wpięta w kontenerze, więc obejmuje każdą drogę
   dodania pliku) oryginał jest obracany według EXIF, zmniejszany do 2560 px i zapisywany **bez metadanych**
   (`strip` — sterownik Vips z `spatie/image` zachowuje EXIF przy zapisie). Warianty powstają z oczyszczonego
   pliku. Dysk jest konfigurowalny (`MEDIA_DISK`, domyślnie dysk uploadów Filamenta), więc przejście na
   prywatny bucket to później zmiana konfiguracji.
3. **Dziennik zmian:** dodanie i usunięcie zdjęcia zapisuje się jawnie na łowisku (`FisheryMediaActivity`,
   format `old`/`attributes`); zmiana kolejności się nie loguje.
