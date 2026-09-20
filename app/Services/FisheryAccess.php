<?php

namespace App\Services;

use App\Models\Fishery;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Bramki dostępu i widoczność łowisk.
 *
 * ⚠️ Ta klasa niesie NIEZMIENNIK BEZPIECZEŃSTWA panelu właściciela. Trzy warstwy,
 * których nie wolno zdejmować pojedynczo, opisuje `docs/conventions/autoryzacja.md` §4.
 *
 * ⚠️ `isOwnerPanel()` i `isAdminPanel()` mieszkają TUTAJ, choć pierwszą wołają też
 * formularze. `scopeToOwnedFisheries()` jest fail-closed (`! isAdminPanel()`) i obie
 * połowy tego niezmiennika muszą reagować identycznie na nierozpoznany panel —
 * rozdzielone między klasy zaczęłyby się rozjeżdżać.
 */
final class FisheryAccess
{
    /**
     * Klucz pamięci podręcznej `findFishery()` w kontenerze.
     *
     * ⚠️ Celowo w kontenerze, a nie we `właściwości static` — kontener jest odtwarzany
     * na każde żądanie **i na każdy test**, a statyczna tablica przeżywałaby
     * `RefreshDatabase` i podawała kolejnemu testowi model z wyczyszczonej tabeli.
     */
    private const FISHERY_CACHE = 'lowiska.fishery_cache';

    /**
     * Łowisko po ID, z memoizacją w obrębie żądania.
     *
     * ⚠️ Renderowanie strony podrzędnej sięga po ten sam wiersz cztery razy (bramka
     * dostępu, adres sekcji, okruszki, hydratacja pola `fishery_id`), a pola adresu
     * w kreatorze są `live()`, więc powtarza się to przy każdym renderze Livewire.
     */
    public static function findFishery(
        int|string|null $fisheryId,
        ?bool $scopedToCurrentUser = null,
    ): ?Fishery {
        // ⚠️ Domyślnie ZAWĘŻONE. Wariant nieograniczony trzeba wybrać świadomie.
        // Powód: okruszki i tytuły stron `Create*` czytają `?fishery` wprost z żądania
        // (bramka z `mount()` nie biegnie przy kolejnych żądaniach Livewire), więc przy
        // domyślnie szerokim wyszukiwaniu `POST /livewire/update?fishery=<cudze>` zwracał
        // NAZWĘ cudzego łowiska i link do jego huba — jedyna ścieżka odczytu omijająca
        // wszystkie trzy warstwy z `docs/conventions/autoryzacja.md` §4.
        $scopedToCurrentUser ??= ! self::isAdminPanel();

        if (! is_numeric($fisheryId)) {
            return null;
        }

        $fisheryId = (int) $fisheryId;

        // Wariant zawężony do użytkownika trzyma się pod osobnym kluczem — te dwa
        // nie mogą się nawzajem podmieniać, bo drugi jest bramką dostępu.
        // ⚠️ W kluczu jest też ID użytkownika: kontener przeżywa WIELE żądań HTTP
        // w obrębie jednego testu (aplikacja wstaje w `setUp()`, nie przy każdym
        // `$this->get()`), więc test wchodzący najpierw jako A, potem jako B na to
        // samo łowisko dostałby z cache'u wpis A i przeszedłby na zielono mimo
        // zepsutej bramki.
        $cacheKey = $fisheryId.($scopedToCurrentUser ? ':own:'.auth()->id() : '');

        /** @var \ArrayObject<string, Fishery|null> $cache */
        $cache = app()->bound(self::FISHERY_CACHE)
            ? app(self::FISHERY_CACHE)
            : tap(new \ArrayObject, fn (\ArrayObject $fresh) => app()->instance(self::FISHERY_CACHE, $fresh));

        if ($cache->offsetExists($cacheKey)) {
            return $cache->offsetGet($cacheKey);
        }

        $query = Fishery::query();

        if ($scopedToCurrentUser) {
            $query->forCurrentUser();
        }

        $cache->offsetSet($cacheKey, $fishery = $query->find($fisheryId));

        return $fishery;
    }

    /**
     * Bramka dostępu do łowiska; **zwraca zweryfikowane ID**.
     *
     * ⚠️ Zwracaną wartość trzeba zapamiętać po stronie serwera i to JEJ używać przy
     * zapisie. `fishery_id` w formularzu jest polem `Hidden`, czyli danymi od klienta —
     * bramka w `mount()` sprawdza parametr `?fishery`, ale nic nie pilnowało, że
     * zapisywany rekord trafia do tego samego łowiska. Polityki zasobów podrzędnych
     * tego nie wyłapią, bo przy tworzeniu nie widzą rekordu nadrzędnego.
     */
    public static function assertFisheryAccessOrAbort(
        int|string|null $fisheryId = null,
    ): int {
        $fisheryId = $fisheryId ?? request()->get('fishery');

        if (! is_numeric($fisheryId)) {
            abort(404);
        }

        $fisheryId = (int) $fisheryId;

        $fishery = self::findFishery($fisheryId);

        if (! $fishery) {
            abort(404);
        }

        return $fisheryId;
    }

    /**
     * Zawęża zapytanie zasobu **podrzędnego wobec łowiska** do łowisk właściciela.
     *
     * ⚠️ Jedno miejsce dla całego niezmiennika widoczności tych zasobów. Wcześniej ta
     * sama reguła była przeklejona do `getEloquentQuery()` trzech zasobów — czwarty
     * zasób podrzędny dodany za pół roku po prostu by jej nie dostał i nic by nie pękło.
     * Reguła i jej trzy warstwy: `docs/conventions/autoryzacja.md` §4.
     *
     * Podzapytanie zamiast `whereHas` z domknięciem: Larastan nie rozwiązuje typu
     * w domknięciu `whereHas` (widzi `Builder<Model>`), więc scope `forCurrentUser()`
     * zgłaszałby się jako nieistniejąca metoda.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function scopeToOwnedFisheries(Builder $query): void
    {
        // ⚠️ Warunek jest fail-closed (`! isAdminPanel()`), a nie `isOwnerPanel()` —
        // tak samo jak bramka `assertFisheryAccessOrAbort()`. Obie połowy tego samego
        // niezmiennika muszą reagować identycznie na nierozpoznany kontekst panelu:
        // przy `isOwnerPanel()` nieznany panel oznaczałby BRAK zawężenia.
        if (self::isAdminPanel()) {
            return;
        }

        $query->whereIn(
            'fishery_id',
            Fishery::query()->forCurrentUser()->select('id'),
        );
    }

    /**
     * Autoryzuje łowisko zgłoszone w danych formularza i wpisuje je z powrotem.
     *
     * ⚠️ Jedyne miejsce, które decyduje, do jakiego łowiska trafia rekord podrzędny.
     * Polityki zasobów podrzędnych tego nie wyłapią — przy tworzeniu nie widzą rekordu
     * nadrzędnego, więc `PositionPolicy::create()` przepuszcza każdego właściciela.
     *
     * ⚠️ Bramka MUSI działać na wartości ze **zgłoszenia**, a nie na ID zapamiętanym
     * w `mount()`: Livewire utrwala między żądaniami wyłącznie właściwości publiczne,
     * a żądanie zapisu leci na `/livewire/update`, więc nie niesie ani `?fishery`,
     * ani niczego z `protected`. Zapamiętane ID było tam po prostu `null`.
     * Ta wersja jest bezstanowa: cokolwiek przyjdzie od klienta, musi przejść przez
     * `assertFisheryAccessOrAbort()`, które poza panelem admina zawęża do łowisk
     * bieżącego użytkownika.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forceVerifiedFishery(array $data): array
    {
        $data['fishery_id'] = self::assertFisheryAccessOrAbort(
            $data['fishery_id'] ?? request()->get('fishery'),
        );

        return $data;
    }

    public static function isOwnerPanel(): bool
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        return $panel?->getId() === 'owner';
    }

    private static function isAdminPanel(): bool
    {
        return Filament::getCurrentOrDefaultPanel()?->getId() === 'admin';
    }
}
