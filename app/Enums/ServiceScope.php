<?php

namespace App\Enums;

/**
 * Zasięg usługi dodatkowej — pole `additional_services.scope` (zadanie 020).
 *
 * ⚠️ **Zasięg jest JAWNY i nie wynika z pustej listy przypięć.** Usługa „wybrane stanowiska" bez
 * żadnego przypięcia jest dostępna NIGDZIE, a odpięcie ostatniego stanowiska nie zmienia jej po
 * cichu w ogólnołowiskową (`dostepnosc.md` §5).
 *
 * ⚠️ **Usługa na całym łowisku nie ma przypięć, więc nie bywa obowiązkowa** — `is_required` żyje
 * na przypięciu. Zmiana zasięgu na „całe łowisko" usuwa przypięcia usługi (`AdditionalService`).
 */
enum ServiceScope: string
{
    /** Każde stanowisko łowiska, także dodane po zapisie usługi. */
    case WholeFishery = 'whole_fishery';

    /** Stanowiska z przypięciem w `additional_service_position`. */
    case SelectedPositions = 'selected_positions';

    public function label(): string
    {
        return match ($this) {
            self::WholeFishery => __('The whole fishery'),
            self::SelectedPositions => __('Selected positions'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
