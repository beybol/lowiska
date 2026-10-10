<?php

namespace App\Actions;

use App\Models\Reservation;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Models\Activity;

/**
 * Czyszczenie dziennika zmian (`activitylog:clean`) z pominięciem kanałów trwałych.
 *
 * ⚠️ Kanał `reservations` NIE podlega czyszczeniu (ADR-026 pkt 4): warunki rezerwacji nie mają
 * tabeli wersji, więc ich historię niesie wyłącznie dziennik. Wyczyszczenie go po
 * `clean_after_days` odebrałoby zamrożeniu warunków ochronę historii.
 *
 * Jawne `activitylog:clean reservations` też nic nie usuwa — kanał trwały jest pomijany zawsze.
 */
class CleanActivityLogKeepingRetained extends CleanActivityLogAction
{
    /** @var array<int, string> */
    public const RETAINED_LOGS = [Reservation::LOG_NAME];

    protected function deleteOldActivities(string $cutOffDate, ?string $logName): int
    {
        // `config('activitylog.activity_model')` to w tym projekcie model pakietu (`config/activitylog.php`).
        return Activity::query()
            ->where('created_at', '<', $cutOffDate)
            ->when($logName !== null, fn ($query) => $query->where('log_name', $logName))
            ->where(fn ($query) => $query->whereNull('log_name')->orWhereNotIn('log_name', self::RETAINED_LOGS))
            ->delete();
    }
}
