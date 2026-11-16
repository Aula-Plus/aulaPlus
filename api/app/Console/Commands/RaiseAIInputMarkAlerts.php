<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\AIInput\AIInputMarks;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Weekly alert of a «La IA lo entendió mal» mark still pending (ClickUp
 * 1dfd3mc2wx2, P03-AJU-03: "a la semana sin resolver, alerta"). Scheduled
 * daily — each mark is alerted once, a week after it was made — with the same
 * tenancy pattern as the other alert commands.
 */
class RaiseAIInputMarkAlerts extends Command
{
    protected $signature = 'alerts:ai-input-marks';

    protected $description = 'Raise the alert of «Lo que recibe la IA» marks still pending a week later';

    public function handle(AIInputMarks $marks): int
    {
        $raised = 0;

        School::query()->orderBy('id')->each(function (School $school) use ($marks, &$raised): void {
            $raised += Tenancy::forSchool($school, fn () => $marks->raiseWeeklyAlerts());
        });

        $this->info("Raised {$raised} alert(s).");

        return self::SUCCESS;
    }
}
