<?php

use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Migracja schematu 4.x -> 5.x (zadanie 010)
|--------------------------------------------------------------------------
|
| Równoważności względem 4.x:
|   - `delete_records_older_than_days` -> `clean_after_days`
|   - `subject_returns_soft_deleted_models` -> `include_soft_deleted_subjects`
|   - `table_name`, `database_connection` -> USUNIĘTE (v5 nie czyta ich już
|     sam; tabela `activity_log` i domyślne połączenie są zaszyte na sztywno
|     w migracjach tego projektu — patrz docs/conventions/dziennik-zmian.md)
|   - env `ACTIVITY_LOGGER_ENABLED` -> `ACTIVITYLOG_ENABLED` (zmiana nazwy
|     zmiennej w samym pakiecie; w tym projekcie nigdy nieustawiona)
|   - nowe: `default_except_attributes`, `buffer`, `actions`
|
*/

return [

    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    'clean_after_days' => 365,

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    'include_soft_deleted_subjects' => false,

    'activity_model' => Activity::class,

    /*
     * Zakres logowania w tym projekcie to `logOnly($this->fillable)` w każdym
     * modelu (patrz docs/conventions/dziennik-zmian.md) — to zadanie przenosi
     * istniejące zachowanie, nie zawęża go globalnie. Wykluczenie pól
     * wrażliwych (np. `phone`) jest osobnym tematem, celowo poza zakresem.
     */
    'default_except_attributes' => [],

    /*
     * Bufor domyślnie wyłączony — projekt nie loguje na tyle dużego wolumenu
     * aktywności w jednym żądaniu, żeby zysk z buforowania przeważył nad
     * prostotą (aktywność bez buforowania ma ID od razu po zapisie).
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
