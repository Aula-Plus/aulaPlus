<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOnboardingSchool;
use App\Enums\OnboardingStatus;
use App\Models\OnboardingSource;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Screen 29 «Qué se recibió»: add (or remove, with --remove=ID) one received
 * source. Describe what arrived, never its content: no student data.
 *
 * Usage: php artisan app:onboarding-source 3 "Sistema de gestión del colegio" \
 *          "Alumnos, grupos, docentes y materias" --format=CSV --status=done
 */
class OnboardingSourceCommand extends Command
{
    use ResolvesOnboardingSchool;

    protected $signature = 'app:onboarding-source {school : School id}
        {source? : Where it came from}
        {content? : What it holds (no student data)}
        {--format= : CSV, Excel, PDF…}
        {--status=done : pending, in_progress or done}
        {--remove= : Remove the source with this id instead}';

    protected $description = 'Add or remove a received source in a school\'s onboarding record (screen 29)';

    public function handle(): int
    {
        $school = $this->school();
        if ($school === null) {
            return self::FAILURE;
        }

        if ($this->option('remove') !== null) {
            $deleted = Tenancy::forSchool($school, fn () => OnboardingSource::query()->whereKey((int) $this->option('remove'))->delete());
            $deleted ? $this->info('Source removed.') : $this->error('No such source in this school.');

            return $deleted ? self::SUCCESS : self::FAILURE;
        }

        $status = OnboardingStatus::tryFrom((string) $this->option('status'));
        if ($this->argument('source') === null || $this->argument('content') === null || $status === null) {
            $this->error('Give source and content, and a status among: '.implode(', ', OnboardingStatus::values()).'.');

            return self::FAILURE;
        }

        $source = Tenancy::forSchool($school, fn () => OnboardingSource::create([
            'source' => mb_substr(trim((string) $this->argument('source')), 0, 120),
            'content' => mb_substr(trim((string) $this->argument('content')), 0, 300),
            'format' => $this->option('format') === null ? null : mb_substr(trim((string) $this->option('format')), 0, 40),
            'status' => $status,
            'position' => (int) OnboardingSource::query()->max('position') + 1,
        ]));

        $this->info("Source #{$source->id} added for \"{$school->name}\".");

        return self::SUCCESS;
    }
}
