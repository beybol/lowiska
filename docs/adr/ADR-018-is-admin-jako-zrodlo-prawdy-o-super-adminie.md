# ADR-018 — `is_admin` jako jedyne źródło prawdy o super adminie

- **Status:** accepted
- **Data:** 2026-09-24
- **Zadanie:** [025 — `is_admin` jedynym źródłem prawdy o super adminie](../tasks/025-is-admin-zrodlem-prawdy-o-super-adminie.md)

## Kontekst

O tym, kto jest administratorem portalu, mówią dziś **dwa niezależne zapisy**:

- **`users.is_admin`** — w pierwotnym zamyśle konto z flagą ma dostęp do `/admin` i może w nim
  wszystko; flaga rozstrzyga dostęp do panelu (`User::canAccessPanel()`);
- **rola `super_admin` Shielda** z fizycznie przypisanymi uprawnieniami. Zadanie 008 odrzuciło
  `define_via_gate = true` z trzech powodów: (1) rola wyglądałaby w panelu Shielda na pustą,
  (2) nie dałoby się odebrać super adminowi pojedynczego uprawnienia, (3) dwa modele uprawnień
  (`super_admin` przez gate, `owner` przez realne uprawnienia) byłyby droższe w utrzymaniu.

Nikt nie trzyma tych dwóch zapisów w zgodzie. Każdy nowy zasób Filamenta dokłada uprawnienia,
których istniejąca rola `super_admin` nie dostaje sama — do czasu ręcznego `MakeAdmin` administrator
nie widzi nowego zasobu (ostatnio: „Szablony dokumentów", zadanie 021). `deploy.yml` nie uruchamia
ani `shield:generate`, ani `MakeAdmin` (luka odnotowana w zadaniu 008).

Operacyjnie istnieją — i na razie będą — **trzy** konta z `is_admin`, każde w praktyce super adminem.
Decyzja wiąże całą autoryzację panelu administratora i każdy przyszły zasób.

## Alternatywy

### Opcja A — `is_admin` źródłem prawdy, rola `super_admin` jej pochodną, synchronizowaną przy każdym wdrożeniu

Idempotentna komenda (`admins:sync`) generuje uprawnienia Shielda, nadaje roli `super_admin`
wszystkie, przypisuje rolę każdemu kontu z `is_admin = 1` i zdejmuje ją z kont bez flagi. `deploy.yml`
uruchamia ją po migracjach; `MakeAdmin` deleguje do niej nadawanie uprawnień.

**Zalety:**
- Jedna prawda (flaga), kierunek zależności jednoznaczny: flaga → rola.
- Zostaje model z zadania 008: realne uprawnienia, rola `super_admin` pełna w panelu Shielda, ten sam
  mechanizm co przy roli `owner` (powody 1 i 3 z 008 dalej obowiązują).
- Nowy zasób jest widoczny dla administratora od pierwszego żądania po wdrożeniu, bez ręcznych kroków.

**Wady:**
- Powód 2 z 008 przestaje obowiązywać: ręczne odebranie uprawnienia roli `super_admin` w Shieldzie
  jest cofane przy następnym wdrożeniu.
- Nowy krok w `deploy.yml` — kolejny element, który może zatrzymać wdrożenie.

### Opcja B — nowa flaga „super admin" na kontach, `is_admin` wyłącznie dostępem do panelu

**Zalety:**
- Pozwala w przyszłości mieć administratora z dostępem do panelu, ale bez pełnych praw.

**Wady:**
- Trzecia prawda o tym samym (flaga, nowa flaga, rola) przy trzech kontach, które i tak są pełnymi
  administratorami — dubluje `is_admin`, a problem synchronizacji roli zostaje.

### Opcja C — `define_via_gate = true` (Shield przepuszcza super admina przez `Gate::before()`)

**Zalety:**
- Brak synchronizacji uprawnień i kroku w `deploy.yml`; nowy zasób działa od razu.

**Wady:**
- Odwraca decyzję z zadania 008 we wszystkich trzech punktach: pusta rola w panelu Shielda, brak
  odbierania pojedynczych uprawnień i dwa modele uprawnień w jednej aplikacji.
- Nadal wymaga powiązania roli z flagą, jeśli rola ma odpowiadać `is_admin`.

## Rekomendacja

**Opcja A.** Usankcjonowanie stanu faktycznego (`is_admin` = super admin) usuwa drugą prawdę zamiast
dokładać trzecią, zachowuje model uprawnień z zadania 008 w dwóch z trzech punktów i zamyka lukę
„nowy zasób niewidoczny do ręcznego `MakeAdmin`". Utrata możliwości odbierania pojedynczych uprawnień
super adminowi jest przy trzech kontach operacyjnych kosztem akceptowalnym — pod warunkiem, że zapisze
się ją wprost w `autoryzacja.md`, a węższy administrator, gdy będzie potrzebny, powstanie jako
**osobna rola**, nie ograniczone `is_admin`.

## Decyzja
Decyzja: A

Uwaga: w przyszłości decyzja może zostać zmieniona jeśli zespół pracownikó fisherya się powiększy. 
