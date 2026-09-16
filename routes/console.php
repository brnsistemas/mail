<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('brnmail:pump')->everyMinute()->withoutOverlapping(2);
Schedule::command('brnmail:scan')->everyFiveMinutes()->withoutOverlapping(15);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
