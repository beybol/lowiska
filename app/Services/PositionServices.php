<?php

namespace App\Services;

use App\Enums\ServiceScope;
use App\Models\AdditionalService;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeValue;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * JEDYNE miejsce odpowiadające na pytanie „jakie usługi ma to stanowisko w danej dobie i czy
 * każda z nich jest dostępna — a jeśli nie, to dlaczego" (zadanie 020).
 *
 * Składa cztery rzeczy: aktywność usługi, zasięg (całe łowisko albo przypięcie), `is_required`
 * z przypięcia i wymagane cechy typu flaga. ⚠️ **Nie liczy dób ani zawieszeń sama** — doby daje
 * `FishingDayCalendar`, wpisy zawieszające cechy `PositionAvailability::attributeSuspensions()`.
 * Reguła przecięcia wpisu z dobą ma jeden dom (`dostepnosc.md` §5).
 *
 * ⚠️ **Wymagana cecha jest spełniona WYŁĄCZNIE wartością „tak"**. Brak wartości („nikt się nie
 * wypowiedział") liczy się jako brak — bezpieczniej nie sprzedać przyczepy tam, gdzie nikt nie
 * potwierdził wjazdu. Cecha usunięta ze słownika jest pomijana (`AdditionalService::requiredAttributes()`).
 *
 * ⚠️ Usługi, przypięcia i wartości cech wczytuje się RAZ na instancję, dla wszystkich stanowisk
 * łowiska — kalendarz pyta o kilkadziesiąt stanowisk w jednym renderze. To odczyt w obrębie
 * jednego pytania, nie bufor werdyktu (`dostepnosc.md` §2).
 *
 * To jest przyszłe wejście modułu rezerwacji do pytania o usługi stanowiska; warstwa oferty
 * (`StayOffer`) jeszcze z niego nie korzysta.
 */
final class PositionServices
{
    /** @var array<int, AdditionalService>|null */
    private ?array $services = null;

    /** @var array<int, array<int, bool>> pozycja → usługa → `is_required` */
    private array $pins = [];

    /** @var array<int, array<int, bool>> pozycja → cecha → wartość flagi */
    private array $flags = [];

    /** @var array<int, PositionAvailability> */
    private array $availability = [];

    public function __construct(private readonly Fishery $fishery) {}

    /**
     * @return array<int, PositionServiceStatus>
     */
    public function forNight(Position $position, FishingDay|CarbonInterface|string $night): array
    {
        return $this->forNights($position, $night, $night);
    }

    /**
     * Usługi stanowiska z dostępnością w ciągu dób — od doby `$firstNight` do `$lastNight` włącznie.
     *
     * ⚠️ Usługa jest niedostępna w zakresie, gdy brakuje jej cechy choćby w JEDNEJ dobie — lista
     * mówi wtedy, w których dobach (powód i daty ograniczenia).
     *
     * Kolejność: najpierw obowiązkowe, potem alfabetycznie.
     *
     * @return array<int, PositionServiceStatus>
     */
    public function forNights(
        Position $position,
        FishingDay|CarbonInterface|string $firstNight,
        FishingDay|CarbonInterface|string $lastNight,
    ): array {
        if ((int) $position->fishery_id !== (int) $this->fishery->getKey()) {
            throw new InvalidArgumentException(
                "Position {$position->id} does not belong to fishery {$this->fishery->id}."
            );
        }

        $statuses = [];
        $suspensions = null;

        foreach ($this->services() as $service) {
            $isRequired = $this->offeredOn($service, $position);

            if ($isRequired === null) {
                continue;
            }

            $problems = [];

            foreach ($service->requiredAttributes as $attribute) {
                $flag = $this->flags[(int) $position->getKey()][(int) $attribute->getKey()] ?? null;

                if ($flag === null) {
                    $problems[] = PositionServiceProblem::notSpecified($attribute);

                    continue;
                }

                if ($flag === false) {
                    $problems[] = PositionServiceProblem::absent($attribute);

                    continue;
                }

                $suspensions ??= $this->availabilityOf($position)->attributeSuspensions($firstNight, $lastNight);

                foreach ($suspensions as $block) {
                    if ((int) $block->position_attribute_id === (int) $attribute->getKey()) {
                        $problems[] = PositionServiceProblem::suspended($attribute, $block);
                    }
                }
            }

            $statuses[] = new PositionServiceStatus($service, $isRequired, $problems);
        }

        usort($statuses, static fn (PositionServiceStatus $a, PositionServiceStatus $b): int => [
            $a->isRequired ? 0 : 1, mb_strtolower($a->service->name), (int) $a->service->getKey(),
        ] <=> [
            $b->isRequired ? 0 : 1, mb_strtolower($b->service->name), (int) $b->service->getKey(),
        ]);

        return $statuses;
    }

    /**
     * Na ilu z podanych stanowisk usługa jest martwa, bo brakuje im wymaganej cechy — bez
     * ograniczeń czasowych (te pokazuje kalendarz). Ostrzeżenie akcji „Przypnij usługę".
     *
     * @param  iterable<int, Position>  $positions
     */
    public static function countLackingRequiredAttributes(AdditionalService $service, iterable $positions): int
    {
        $attributeIds = $service->requiredAttributes()->pluck('position_attributes.id')->map(fn ($id): int => (int) $id)->all();
        $positionIds = collect($positions)->map(fn (Position $position): int => (int) $position->getKey())->all();

        if ($attributeIds === [] || $positionIds === []) {
            return 0;
        }

        $satisfied = PositionAttributeValue::query()
            ->whereIn('position_id', $positionIds)
            ->whereIn('position_attribute_id', $attributeIds)
            ->where('value_flag', true)
            ->get(['position_id', 'position_attribute_id'])
            ->groupBy('position_id')
            ->map(fn ($values): int => $values->count());

        return count(array_filter(
            $positionIds,
            fn (int $id): bool => ($satisfied[$id] ?? 0) < count($attributeIds),
        ));
    }

    /**
     * `null` = usługa nie jest na tym stanowisku oferowana; inaczej — czy jest obowiązkowa.
     */
    private function offeredOn(AdditionalService $service, Position $position): ?bool
    {
        if ($service->scope === ServiceScope::WholeFishery) {
            return false;
        }

        return $this->pins[(int) $position->getKey()][(int) $service->getKey()] ?? null;
    }

    /**
     * @return array<int, AdditionalService>
     */
    private function services(): array
    {
        if ($this->services !== null) {
            return $this->services;
        }

        /** @var array<int, AdditionalService> $services */
        $services = $this->fishery->additionalServices()
            ->isActive()
            ->with('requiredAttributes')
            ->get()
            ->all();

        $this->services = $services;
        $serviceIds = array_map(fn (AdditionalService $service): int => (int) $service->getKey(), $services);

        if ($serviceIds === []) {
            return $this->services;
        }

        foreach (DB::table('additional_service_position')->whereIn('additional_service_id', $serviceIds)->get() as $pin) {
            $this->pins[(int) $pin->position_id][(int) $pin->additional_service_id] = (bool) $pin->is_required;
        }

        $attributeIds = collect($services)
            ->flatMap(fn (AdditionalService $service) => $service->requiredAttributes)
            ->map(fn (PositionAttribute $attribute): int => (int) $attribute->getKey())
            ->unique()
            ->values()
            ->all();

        if ($attributeIds !== []) {
            $values = PositionAttributeValue::query()
                ->whereIn('position_attribute_id', $attributeIds)
                ->whereIn('position_id', $this->fishery->positions()->select('id'))
                ->whereNotNull('value_flag')
                ->get(['position_id', 'position_attribute_id', 'value_flag']);

            foreach ($values as $value) {
                $this->flags[(int) $value->position_id][(int) $value->position_attribute_id] = (bool) $value->value_flag;
            }
        }

        return $this->services;
    }

    private function availabilityOf(Position $position): PositionAvailability
    {
        // Łowisko wstrzykujemy w relację, zamiast pozwolić jej się doczytać — ta sama
        // oszczędność co w `SaleCalendar::grid()`.
        if (! $position->relationLoaded('fishery')) {
            $position->setRelation('fishery', $this->fishery);
        }

        return $this->availability[(int) $position->getKey()] ??= new PositionAvailability($position);
    }
}
