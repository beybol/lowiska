<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Wygaszanie niepotwierdzonych rezerwacji (zadanie 045). Lokalnie: `schedule:work` (usługa `scheduler`);
// na Cloud Run komendę wyzwala Cloud Scheduler — `docs/operations/obraz-produkcyjny.md` §13.
Schedule::command('reservations:expire')->everyFiveMinutes();
