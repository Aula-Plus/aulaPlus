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

// «Señal de dificultad» alerts (ClickUp 86e3qkwpn): evaluated daily alongside
// the performance alerts, from each school's configured conditions.
Schedule::command('alerts:difficulty-signals')->daily();

// «Llegó a su fecha sin salida» (ClickUp 86e3jpzdp, review of 14/10): marks
// the same alert when its deadline passed while still pending — no second
// alert is created.
Schedule::command('alerts:overdue')->daily();
