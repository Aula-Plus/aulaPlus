<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOnboardingSchool;
use App\Enums\OnboardingStatus;
use App\Models\OnboardingLogEntry;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Screen 29 «Bitácora»: add (or remove, with --remove=ID) one step.
 *
 * Usage: php artisan app:onboarding-log 3 2026-02-27 \
 *          "Validación conjunta: el colegio revisó una muestra de 20 alumnos" --status=done
 */
class OnboardingLog extends Command
{
    use ResolvesOnboardingSchool;

    protected $signature = 'app:onboarding-log {school : School id}
        {date? : YYYY-MM-DD}
        {step? : What was done (no student data)}
        {--status=done : pending, in_progress or done}
        {--remove= : Remove the step with this id instead}';

    protected $description = 'Add or remove a step in a school\'s onboarding log (screen 29)';

    public function handle(): int
    {
        $school = $this->school();
        if ($school === null) {
            return self::FAILURE;
        }

        if ($this->option('remove') !== null) {
            $deleted = Tenancy::forSchool($school, fn () => OnboardingLogEntry::query()->whereKey((int) $this->option('remove'))->delete());
            $deleted ? $this->info('Step removed.') : $this->error('No such step in this school.');

            return $deleted ? self::SUCCESS : self::FAILURE;
        }

        $status = OnboardingStatus::tryFrom((string) $this->option('status'));
        if ($this->argument('date') === null || $this->argument('step') === null || $status === null) {
            $this->error('Give date and step, and a status among: '.implode(', ', OnboardingStatus::values()).'.');

            return self::FAILURE;
        }
        $date = $this->date((string) $this->argument('date'), 'date');
        if ($date === null) {
            return self::FAILURE;
        }

        $entry = Tenancy::forSchool($school, fn () => OnboardingLogEntry::create([
            'happened_on' => $date->toDateString(),
            'step' => mb_substr(trim((string) $this->argument('step')), 0, 300),
            'status' => $status,
        ]));

        $this->info("Step #{$entry->id} added for \"{$school->name}\".");

        return self::SUCCESS;
    }
}
