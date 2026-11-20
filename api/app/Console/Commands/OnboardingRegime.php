<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOnboardingSchool;
use App\Enums\LevelRegime;
use App\Models\EducationLevel;
use App\Models\SchoolLevelRegime;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Screen 29: the Aula+ team loads a level's ANEP regime from the official
 * documentation (P29-DEC-01) — never declared by the school.
 *
 * Usage: php artisan app:onboarding-regime 3 primary enabled --note="Sigue el programa nacional"
 *        php artisan app:onboarding-regime 3 initial authorized --note="Programa propio"
 */
class OnboardingRegime extends Command
{
    use ResolvesOnboardingSchool;

    protected $signature = 'app:onboarding-regime {school : School id}
        {level : initial, primary, lower_secondary or upper_secondary}
        {regime : enabled (habilitado) or authorized (autorizado)}
        {--note= : Short note shown next to it}';

    protected $description = 'Set the ANEP regime of one level of a school (screen 29)';

    public function handle(): int
    {
        $school = $this->school();
        if ($school === null) {
            return self::FAILURE;
        }

        $level = EducationLevel::query()->where('key', $this->argument('level'))->first();
        if ($level === null) {
            $this->error('Unknown level. Use: '.EducationLevel::query()->orderBy('position')->pluck('key')->implode(', ').'.');

            return self::FAILURE;
        }

        $regime = LevelRegime::tryFrom((string) $this->argument('regime'));
        if ($regime === null) {
            $this->error('Unknown regime. Use: '.implode(', ', LevelRegime::values()).'.');

            return self::FAILURE;
        }

        if ($regime === LevelRegime::Authorized && LevelRegime::isFixedLevel($level->key)) {
            $this->error("{$level->label} is always «habilitado» for private schools (P23-INS-02).");

            return self::FAILURE;
        }

        $note = $this->option('note');
        Tenancy::forSchool($school, fn () => SchoolLevelRegime::query()->updateOrCreate(
            ['education_level_id' => $level->id],
            ['regime' => $regime, 'note' => $note === null ? null : mb_substr(trim((string) $note), 0, 300)],
        ));

        $this->info("{$level->label}: {$regime->value} for \"{$school->name}\".");

        return self::SUCCESS;
    }
}
