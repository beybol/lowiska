<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use Carbon\CarbonImmutable;

/**
 * Jedna pozycja rozbicia wyceny: doba × rola × reguła.
 *
 * ⚠️ Kwoty są w **groszach**, nie w `float`. Obniżka przedsprzedażowa zaokrągla się raz na
 * dobę i musi odróżnić 14,01 zł od 14,02 zł — arytmetyka zmiennoprzecinkowa nie daje na to
 * gwarancji. Formatowanie do postaci widzianej przez człowieka należy do widoku (019), nie tutaj.
 *
 * `label` jest tekstem operatora widzianym przez wędkarza („Stanowisko tylko dla Ciebie"),
 * a `priceRuleId` wskazuje regułę, z której pozycja wynika — bez tego operator nie wie,
 * którą linijkę cennika poprawić.
 */
final readonly class StayPriceItem
{
    public function __construct(
        public CarbonImmutable $night,
        public ParticipantRole $role,
        public PriceRuleKind $kind,
        public ?string $label,
        public int $people,
        public int $amountPerPersonInCents,
        public ?int $priceRuleId,
    ) {}

    public function amountInCents(): int
    {
        return $this->people * $this->amountPerPersonInCents;
    }
}
