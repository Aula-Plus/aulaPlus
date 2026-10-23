<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Alerts\DifficultySignalAlertGenerator;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * «Señal de dificultad» alerts (ClickUp 86e3qkwpn), from the conditions each
 * school configured. Scheduled daily in routes/console.php, next to
 * `alerts:performance`, with the same tenancy pattern: a console context has
 * no authenticated user, so every school is iterated explicitly and scoped
 * with Tenancy::forSchool().
 */
class GenerateDifficultySignalAlerts extends Command
{
    protected $signature = 'alerts:difficulty-signals';

    protected $description = 'Generate «Señal de dificultad» alerts from each school\'s configured conditions';

    public function handle(DifficultySignalAlertGenerator $generator): int
    {
        $generated = 0;

        School::query()->orderBy('id')->each(function (School $school) use ($generator, &$generated): void {
            $generated += Tenancy::forSchool($school, fn () => $generator->generate());
        });

        $this->info("Generated {$generated} alert(s).");

        return self::SUCCESS;
    }
}
