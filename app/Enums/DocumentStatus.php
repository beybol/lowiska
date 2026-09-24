<?php

namespace App\Enums;

/**
 * Stan wersji dokumentu — LICZONY z daty wejścia w życie i „dziś" w strefie łowiska, nigdy
 * zapisywany (ADR-017). Liczy go `FisheryDocuments::statusOf()`.
 */
enum DocumentStatus: string
{
    /** Najpóźniejsza wersja rodzaju z datą ≤ dziś. */
    case Current = 'current';

    /** Data wejścia w życie w przyszłości — wersję wolno jeszcze edytować i usunąć. */
    case Scheduled = 'scheduled';

    /** Obowiązywała, zastąpiła ją późniejsza. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Current => __('In force'),
            self::Scheduled => __('Scheduled'),
            self::Archived => __('Archived'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Current => 'success',
            self::Scheduled => 'info',
            self::Archived => 'gray',
        };
    }
}
