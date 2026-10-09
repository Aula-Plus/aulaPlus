<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Alerts\PerformanceAlertGenerator;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Sustained-low-performance alerts (ClickUp 86e3jpzcv), from the conditions
 * each school configured. Scheduled daily in routes/console.php.
 *
 * Same tenancy pattern as GenerateAlerts: a console context has no
 * authenticated user, so every school is iterated explicitly and scoped with
 * Tenancy::forSchool().
 */
class GeneratePerformanceAlerts extends Command
{
    protected $signature = 'alerts:performance';

    protected $description = 'Generate sustained-low-performance alerts from each school\'s configured conditions';

    public function handle(PerformanceAlertGenerator $generator): int
    {
        $generated = 0;

        School::query()->orderBy('id')->each(function (School $school) use ($generator, &$generated): void {
            $generated += Tenancy::forSchool($school, fn () => $generator->generate());
        });

        $this->info("Generated {$generated} alert(s).");

        return self::SUCCESS;
    }
}
