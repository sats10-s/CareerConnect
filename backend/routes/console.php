<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly placement summary to each HOD (only while the Super Admin has
// "Email HODs a weekly placement summary" turned on). Needs the
// scheduler running: `php artisan schedule:work`, or a cron entry for
// `php artisan schedule:run` every minute.
Schedule::command('reports:weekly-hod-summary')->weeklyOn(1, '08:00');
