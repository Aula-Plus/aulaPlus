<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sustained-low-performance alerts (ClickUp 86e3jpzcv): evaluates each
// school's configured conditions once a day.
Schedule::command('alerts:performance')->daily();
