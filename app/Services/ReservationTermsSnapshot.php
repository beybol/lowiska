<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\TermsExceptionKind;
use App\Models\Position;
use App\Models\PositionAttributeValue;
use App\Models\ReservationTerms;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * JEDYNE miejsce składające zamrożone warunki rezerwacji (ADR-026) w kształt `reservation_terms`.
 *
 * ⚠️ Wskazanie tam, gdzie jest wersja (dokumenty, ADR-017), kopia tam, gdzie wersji nie ma (rozbicie
 * ceny, polityka zwrotu, parametry stanowiska, usługi). Kształt JSON-ów opisuje
 * `ReservationTerms::SCHEMA_VERSION` — zmiana kształtu podnosi wersję.
 */
final class ReservationTermsSnapshot
{
    /**
     * @param  array<int, FishingDay>  $nights
     * @param  array<int, array<string, mixed>>  $exceptions
     * @param  array{name: ?string, email: ?string, phone: ?string}  $contact
     * @return array<string, mixed>
     */
    public static function build(
        Position $position,
        array $nights,
        int $anglers,
        int $companions,
        ?StayPriceBreakdown $breakdown,
        StayServicesQuote $services,
        ?int $agreedTotalCents,
        array $exceptions,
        array $contact,
    ): array {
        $fishery = $position->fishery;
        $stayTotal = $breakdown?->totalInCents();
        $servicesTotal = $services->totalInCents();
        $computed = $stayTotal === null ? null : $stayTotal + $servicesTotal;

        return [
            'schema_version' => ReservationTerms::SCHEMA_VERSION,
            'currency' => $fishery->currency?->name,
            'anglers' => $anglers,
            'companions' => $companions,
            'stay_total_cents' => $stayTotal,
            'services_total_cents' => $servicesTotal,
            'computed_total_cents' => $computed,
            'agreed_total_cents' => $agreedTotalCents,
            'total_cents' => $agreedTotalCents ?? (int) $computed,
            'price_breakdown' => $breakdown === null ? null : self::breakdown($breakdown),
            'refund_policy' => (new RefundPolicy($fishery))->tiers(),
            'position_snapshot' => self::position($position, $nights),
            'services' => self::services($services),
            'documents' => self::documents($position),
            'exceptions' => $exceptions,
            'contact_snapshot' => $contact,
        ];
    }

    /**
     * Wpis wyjątku — zawsze z powodem, autorem i chwilą (ADR-026 pkt 7–8).
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function exception(TermsExceptionKind $kind, string $reason, ?User $actor, array $details = []): array
    {
        return array_merge([
            'kind' => $kind->value,
            'reason' => $reason,
            'by' => $actor?->getKey(),
            'by_name' => $actor?->name,
            'at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ], $details);
    }

    /**
     * @return array<string, mixed>
     */
    private static function breakdown(StayPriceBreakdown $breakdown): array
    {
        $nights = [];

        foreach ($breakdown->nights as $night) {
            $items = [];

            foreach ($night->items as $item) {
                $items[] = [
                    'role' => $item->role->value,
                    'kind' => $item->kind->value,
                    'label' => $item->label,
                    'people' => $item->people,
                    'amount_per_person_cents' => $item->amountPerPersonInCents,
                    'amount_cents' => $item->amountInCents(),
                    'price_rule_id' => $item->priceRuleId,
                ];
            }

            $nights[] = [
                'night' => $night->night->toDateString(),
                'items' => $items,
                'subtotal_cents' => $night->subtotalInCents(),
                'discount_cents' => $night->discountInCents,
                'discount_percent' => $night->discountPercent,
                'total_cents' => $night->totalInCents(),
            ];
        }

        return [
            'nights' => $nights,
            'subtotal_cents' => $breakdown->subtotalInCents(),
            'discount_cents' => $breakdown->discountInCents(),
            'total_cents' => $breakdown->totalInCents(),
        ];
    }

    /**
     * Parametry stanowiska z chwili rezerwacji: nazwa, pojemność, wartości cech i cechy zawieszone
     * w dobach pobytu (zawieszenie nie jest odmową, ale wędkarz kupił stanowisko bez tej cechy).
     *
     * @param  array<int, FishingDay>  $nights
     * @return array<string, mixed>
     */
    private static function position(Position $position, array $nights): array
    {
        $attributes = [];

        $values = $position->attributeValues()->with(['attribute', 'option'])->get();

        foreach ($values as $value) {
            /** @var PositionAttributeValue $value */
            $attribute = $value->attribute;

            if ($attribute === null) {
                continue;
            }

            $attributes[] = [
                'attribute_id' => $attribute->getKey(),
                'name' => $attribute->name,
                'type' => $attribute->type->value,
                'unit' => $attribute->unit,
                'value' => $value->typedValue($attribute->type),
                'option' => $value->option?->name,
            ];
        }

        $suspended = [];

        if ($nights !== []) {
            $suspensions = (new PositionAvailability($position))
                ->attributeSuspensions($nights[0], $nights[count($nights) - 1]);

            foreach ($suspensions as $block) {
                $suspended[] = [
                    'attribute_id' => $block->position_attribute_id,
                    'attribute' => $block->attribute?->name,
                    'reason' => $block->reason,
                    'starts_on' => $block->starts_on->toDateString(),
                    'ends_on' => $block->ends_on?->toDateString(),
                ];
            }
        }

        return [
            'position_id' => $position->getKey(),
            'name' => $position->name,
            'max_anglers' => $position->max_anglers,
            'max_people' => $position->max_people,
            'attributes' => $attributes,
            'suspended_attributes' => $suspended,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function services(StayServicesQuote $quote): array
    {
        $lines = [];

        foreach ($quote->chosen() as $line) {
            $lines[] = [
                'service_id' => $line->service->getKey(),
                'name' => $line->service->name,
                'unit' => $line->unit->value,
                'quantity' => $line->quantity,
                'nights' => $line->nights,
                'unit_price_cents' => $line->unitPriceInCents,
                'amount_cents' => $line->amountInCents(),
                'is_required' => $line->isRequired,
            ];
        }

        return $lines;
    }

    /**
     * Id wersji dokumentów obowiązujących w chwili rezerwacji (ADR-026 pkt 2, ADR-017).
     *
     * @return array<string, int|null>
     */
    private static function documents(Position $position): array
    {
        $documents = new FisheryDocuments($position->fishery);
        $ids = [];

        foreach (DocumentType::cases() as $type) {
            $ids[$type->value] = $documents->current($type)?->getKey();
        }

        return $ids;
    }
}
