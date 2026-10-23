<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Alerts\OverdueAlertMarker;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * «Llegó a su fecha sin salida» (ClickUp 86e3jpzdp, review of 14/10).
 * Scheduled daily in routes/console.php. Same tenancy pattern as
 * GeneratePerformanceAlerts.
 */
class MarkOverdueAlerts extends Command
{
    protected $signature = 'alerts:overdue';

    protected $description = 'Mark pending alerts whose deadline passed without a way out (no new alert is created)';

    public function handle(OverdueAlertMarker $marker): int
    {
        $marked = 0;

        School::query()->orderBy('id')->each(function (School $school) use ($marker, &$marked): void {
            $marked += Tenancy::forSchool($school, fn () => $marker->mark());
        });

        $this->info("Marked {$marked} alert(s) as overdue.");

        return self::SUCCESS;
    }
}
