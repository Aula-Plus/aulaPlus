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

// "Alerta escalada con plazo vencido" (ClickUp 86e3jpzdp): handed-off alerts
// whose deadline passed without a way out.
Schedule::command('alerts:escalate')->daily();
