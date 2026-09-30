# ADR-021 — Schemat adresów portalu wędkarza

- **Status:** accepted
- **Data:** 2026-09-30
- **Zadanie:** [031 — Szkielet portalu, strona główna i strony statyczne](../tasks/031-szkielet-portalu-i-strona-glowna.md)

## Kontekst

Zadanie 031 zakłada pierwsze publiczne trasy portalu: stronę główną, strony informacyjne i stronę
łowiska. Kształt adresów wstępnie rozstrzygnęło 027 (pkt 9.3, portal-v3 §6.1) z zastrzeżeniem, że
decyzja spełnia kryterium ADR i ma zostać zapisana **przed pierwszą trasą łowiska**:

- **Zasięg:** każda strona portalu (etapy 1–4), mapa strony, `hreflang`, canonical, linki w e-mailach
  i w przyszłych stronach regionów — oraz walidacja sluga łowiska, jeśli krótki adres dzieli przestrzeń
  z trasami aplikacji.
- **Koszt odwrócenia:** krótki adres łowiska trafi na banery i ulotki łowisk — po wydrukowaniu nie da się
  go zmienić bez utraty ruchu. Adresy kanoniczne zbierają pozycję w wyszukiwarkach; zmiana schematu to
  przekierowania na zawsze.
- **Uzasadnienie niewynikające z kodu:** dlaczego województwo jest w adresie, a routing go ignoruje;
  dlaczego krótki adres stoi wprost pod domeną i czym jest chroniony przed kolizją z trasami.

Dane, na których schemat stoi, istnieją od zadania 030: `fisheries.slug` (unikalny w całym portalu,
stały, zmieniany wyłącznie przez admina, bez historii, format `^[a-z0-9]+(?:-[a-z0-9]+)*$` —
`PortalSlugs::PATTERN`) i `states.slug` (polski w obu językach, unikalny w obrębie kraju).

**Część wspólna wszystkich opcji** (bez sporu, rozstrzygnięta w 027):

| Adres | Odpowiedź |
|---|---|
| `/pl/{województwo}/{slug}`, `/en/{województwo}/{slug}` | **canonical** strony łowiska; `hreflang` między wersjami |
| `/{język}/{inne-województwo}/{slug}` | **301** na canonical — łowisko szukane **wyłącznie po slugu** |
| `/{język}/{województwo}` | strona regionu na później; do tego czasu 302 na stronę główną |
| `/` | 302 na `/pl` albo `/en` (cookie → `Accept-Language` → PL) |
| `/pl/dla-lowisk`, `/en/for-fisheries`, … | strony informacyjne — slug **w języku strony**, zdefiniowany w jednym miejscu |

- **Każda strona z treścią ma prefiks języka.** Poza nim żyją wyłącznie wejścia przekierowujące
  (`/`, krótki adres łowiska) i trasy techniczne (panele, logowanie, `robots.txt`, `sitemap.xml`).
- **Slug województwa po polsku w obu językach** — to nazwa własna („wielkopolskie").
- **Historii slugów nie prowadzimy:** ręczna zmiana sluga łowiska przez admina świadomie unieważnia
  stary adres, także krótki. Zmiana sluga województwa niczego nie psuje dzięki 301.
- Trasy stron statycznych rejestrowane **przed** trasą łowiska: `/pl/dokumenty-prawne/regulamin` ma ten
  sam kształt co `/{język}/{województwo}/{slug}`.

Spór dotyczy wyłącznie **krótkiego adresu łowiska** — tego, który łowisko drukuje na banerze.

## Alternatywy

### Opcja A — separator `/l/{slug}`

Krótki adres `fisherya.com/l/klasztorne` → 302 na canonical.

**Zalety:**
- Jedna trasa i jedna zastrzeżona litera; kolizja z trasami aplikacji jest niemożliwa z konstrukcji.
- Walidacja sluga nie musi znać tras aplikacji.

**Wady:**
- Dodatkowy segment na banerze; adres nie wygląda jak „własna strona" łowiska.
- Łowisko musi pilnować `/l/` przy druku — pominięty segment to 404.

### Opcja B — krótki adres wprost pod domeną (`/{slug}`), chroniony czterema warstwami

Krótki adres `fisherya.com/klasztorne` → 302 na canonical w języku z cookie, bez niego z `Accept-Language`,
domyślnie PL. Przestrzeń adresów pierwszego poziomu dzielą łowiska i trasy techniczne, więc kolizję
wyklucza się warstwowo:

1. **Kształt sluga** (`PortalSlugs::PATTERN`) — małe litery, cyfry, pojedyncze myślniki, **bez kropki**:
   żaden slug nie zderzy się z plikiem w `public/` (`robots.txt`, `sitemap.xml`, `favicon.ico`, każdy
   przyszły plik z rozszerzeniem) ani z trasą zaczynającą się od `_`. **Minimum 3 znaki**: żaden slug nie
   zderzy się z prefiksem języka (`pl`, `en`, każdy przyszły kod ISO 639-1) — a pod prefiksem żyją
   **wszystkie** strony z treścią, więc nowe strony informacyjne nigdy nie kolidują.
2. **Lista zastrzeżona wpisana na sztywno** (jedno miejsce, `PortalSlugs`): katalogi `public/` (`build`,
   `css`, `js`, `fonts`, `images`, `storage`, `vendor`), panele (`admin`, `owner`), trasy systemowe
   i uwierzytelniania (`api`, `login`, `logout`, `register`, `verify`, `verify-email`, `password`,
   `forgot-password`, `reset-password`, `confirm-password`, `profile`, `auth`, `dashboard`, `up`,
   `livewire`, `filament`) oraz nazwy zarezerwowane na przyszłość (`app`, `www`, `mail`, `static`,
   `assets`). Chroni nazwy, które **zajmiemy później** albo które obsługuje serwer **przed** Laravelem.
3. **Sprawdzenie routera przy nadawaniu i zmianie sluga** — pierwsze segmenty wszystkich
   zarejestrowanych tras (`Route::getRoutes()`, **nie** parsowanie `routes/web.php`: część tras
   rejestrują pakiety — Filament, Livewire, Breeze). Łapie to, czego lista nie przewidziała, np. prefiks
   Livewire z haszem (`livewire-4b1cb1fa`), zmienny między wersjami.
4. **Kontrola przy wdrożeniu** — komenda `portal:check-slugs` w `deploy.yml` po migracjach
   (obok `admins:sync`), **niezatrzymująca**: porównuje slugi w bazie z warstwami 1–3 **nowej** wersji
   aplikacji i wypisuje ostrzeżenie. Pilnuje kierunku, którego warstwy 1–3 nie widzą: nowa trasa
   dodana po wydrukowaniu banera łowiska.

**Mechanizm trasy:** krótki adres obsługuje **`Route::fallback()`**, nie trasa `/{slug}` na końcu
`routes/web.php`. Router Laravela bierze pierwsze dopasowanie, ale kolejności **względem tras pakietów**
nie kontrolujemy (zależy od bootowania providerów), a dopasowana trasa, która nie znajdzie łowiska,
zwraca 404 **zamiast przepuścić żądanie dalej** — `/{slug}` zarejestrowane przed Filamentem wyłączyłoby
panel. Fallback jest zawsze ostatni. Obsługuje: jeden segment zgodny z wzorcem → łowisko opublikowane
→ 302; adres z wielkimi literami → 301 na wersję małymi literami; wszystko inne → 404 portalu.

**Zalety:**
- Najkrótszy adres na banerze; wygląda jak własna strona łowiska — realna wartość dla operatora.
- Prefiks języka na całej treści sprawia, że rośnie wyłącznie przestrzeń tras technicznych — mała,
  rzadko zmieniana i w całości pod naszą kontrolą.
- SEO bez zmian wobec A: krótki adres i tak jest tylko przekierowaniem 302.

**Wady:**
- Walidacja sluga na stałe zależy od tablicy tras i zawartości `public/`; lista zastrzeżona wymaga
  dopisania przy każdej nowej trasie pierwszego poziomu (pilnuje tego warstwa 3 i 4, nie pamięć).
- Kolizja „nowa trasa kontra wydrukowany slug" jest **wykrywana, nie wykluczana** — ostrzeżenie przy
  wdrożeniu trzeba czytać. Rozwiązanie jest tanie: zmienić nazwę naszej trasy, nie sluga.
- Każde nieznane jednosegmentowe żądanie (boty: `/wp-admin`) kończy się tanim `exists` w bazie;
  kropki i ukośniki odrzuca wzorzec przed zapytaniem.

### Opcja C — subdomena łowiska (`klasztorne.fisherya.com`)

**Zalety:**
- Adres wygląda jak „własna strona" łowiska.

**Wady:**
- Wildcard DNS i certyfikat wieloznaczny; na Cloud Run — load balancer zamiast prostego mapowania domeny.
- Dzieli portal na osobne witryny w oczach wyszukiwarek.
- Ta sama kolizja w mniejszej skali (`www`, `api`, `panel`, `mail`…).
- Sesja, cookie języka i CSRF między subdomenami wymagają osobnej konfiguracji.

## Rekomendacja

**Opcja B.** Pierwotna rekomendacja (A, za 027) przeszacowała ryzyko kolizji: zakładała, że przyszłe strony
informacyjne dzielą przestrzeń z krótkim adresem. Przy prefiksie języka na całej treści dzielą ją
wyłącznie trasy techniczne, a cztery warstwy (kształt sluga, lista zastrzeżona, sprawdzenie routera,
kontrola przy wdrożeniu) plus `Route::fallback()` zamykają kolizje od obu stron. Zysk — adres
`fisherya.com/klasztorne` na banerze — jest dla łowiska realny i trwały.

Szczegóły, które rekomendacja przyjmuje (do potwierdzenia w Decyzji):
- **Kolizja przy nadawaniu automatycznym → sufiks** (`admin-2`), jak przy kolizji dwóch łowisk; **przy
  ręcznej zmianie przez admina → błąd walidacji** z nazwą zajętej trasy.
- **Bez drugiego wejścia `/l/{slug}`** — nic nie jest jeszcze wydrukowane, a dwa krótkie adresy to dwa
  kanały do utrzymania.
- **Istniejące slugi z 030** przechodzą kontrolę `portal:check-slugs` w migracji danych 031; kolizja
  dostaje sufiks jak przy nadawaniu automatycznym.

Konsekwencje dla implementacji 031:
- nazwy tras po angielsku, **slugi stron w języku strony, w jednym miejscu**; przełącznik języka prowadzi
  na odpowiednik bieżącej strony;
- trasa łowiska rozwiązuje rekord **po `fisheries.slug` z zakresem `published()`**; segment województwa
  porównuje z `state.slug` i przy niezgodności robi 301;
- wybór języka dla `/` i krótkiego adresu ma jedną implementację (cookie → `Accept-Language` → PL);
- `PortalSlugs` dostaje minimum 3 znaków, listę zastrzeżoną i sprawdzenie routera — jedna metoda
  „czy slug jest wolny", wołana przy nadawaniu, przy ręcznej zmianie i przez `portal:check-slugs`.

## Decyzja
Decyzja: B — ze szczegółami z rekomendacji (sufiks przy nadawaniu automatycznym, błąd walidacji przy
ręcznej zmianie, bez drugiego wejścia `/l/{slug}`). Slug bez kropki i co najmniej 3 znaki; lista nazw
zastrzeżonych wpisana na sztywno (katalogi `public/`, panele, trasy systemowe) obok sprawdzenia routera.
