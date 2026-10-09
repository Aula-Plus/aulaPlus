<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Alerts\OverdueAlertEscalator;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * "Alerta escalada con plazo vencido" (ClickUp 86e3jpzdp). Scheduled daily
 * in routes/console.php. Same tenancy pattern as GeneratePerformanceAlerts.
 */
class EscalateOverdueAlerts extends Command
{
    protected $signature = 'alerts:escalate';

    protected $description = 'Escalate handed-off alerts whose deadline passed without a way out';

    public function handle(OverdueAlertEscalator $escalator): int
    {
        $escalated = 0;

        School::query()->orderBy('id')->each(function (School $school) use ($escalator, &$escalated): void {
            $escalated += Tenancy::forSchool($school, fn () => $escalator->escalate());
        });

        $this->info("Escalated {$escalated} alert(s).");

        return self::SUCCESS;
    }
}
