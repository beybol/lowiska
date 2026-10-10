<?php

namespace App\Enums;

/**
 * Rodzaj zmiany konfiguracji, która może kolidować z istniejącymi rezerwacjami (ADR-027, zadanie 044).
 *
 * Zbiór decyzji zależy od rodzaju (`decisions()`): blokada sprzedaży i wycofanie stanowiska wymagają
 * jawnej decyzji dla każdej dotkniętej rezerwacji; zawieszenie cechy tylko informuje (ADR-027 pkt 8).
 * Kolejne zmiany (godziny doby, skrócenie okresu sprzedaży, pojemność) dokładają tu wartość i własny
 * zbiór decyzji — mechanizm i ekran decyzji zostają te same.
 */
enum ConfigurationChangeKind: string
{
    case SaleBlock = 'sale_block';

    case PositionWithdrawal = 'position_withdrawal';

    case AttributeSuspension = 'attribute_suspension';

    /**
     * @return array<int, CollisionDecision>
     */
    public function decisions(): array
    {
        return match ($this) {
            // Bez „przenieś" i bez anulacji bez zwrotu (ADR-027 pkt 3, G8).
            self::SaleBlock, self::PositionWithdrawal => [CollisionDecision::Cancel, CollisionDecision::Keep],
            self::AttributeSuspension => [],
        };
    }

    public function requiresDecision(): bool
    {
        return $this->decisions() !== [];
    }

    /** Wyjątek zapisywany w warunkach rezerwacji przy decyzji „zostaw". */
    public function keepException(): TermsExceptionKind
    {
        return match ($this) {
            self::SaleBlock => TermsExceptionKind::SaleBlocked,
            default => TermsExceptionKind::SaleRule,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SaleBlock => __('Sale block'),
            self::PositionWithdrawal => __('Withdrawing the position from sale'),
            self::AttributeSuspension => __('Attribute suspension'),
        };
    }
}
