<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOnboardingSchool;
use App\Models\SchoolOnboarding;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Screen 29 «Puesta en marcha»: the Aula+ team sets the header dates and the
 * person assigned to the school. Only the options given change; "none"
 * clears one. Dirección only reads this (P29-DEC-115).
 *
 * Usage: php artisan app:onboarding-status 3 --import-completed=2026-02-28 \
 *          --regime-configured=2026-02-20 --opened=2026-03-02 \
 *          --offering-confirmed=2026-02-25 --contact-name="Tiago" \
 *          --contact-channel="tiago@… · 099 …"
 */
class OnboardingStatus extends Command
{
    use ResolvesOnboardingSchool;

    protected $signature = 'app:onboarding-status {school : School id}
        {--import-completed= : Import completed on (YYYY-MM-DD or none)}
        {--regime-configured= : Regime configured by Aula+ on (YYYY-MM-DD or none)}
        {--opened= : System opened to the three roles on (YYYY-MM-DD or none)}
        {--offering-confirmed= : Offering confirmed by the school on (YYYY-MM-DD or none)}
        {--contact-name= : Aula+ person assigned to the school (or none)}
        {--contact-channel= : How to reach them, e.g. mail or phone (or none)}';

    protected $description = 'Set the onboarding dates and contact of a school (screen 29)';

    protected const DATES = [
        'import-completed' => 'import_completed_on',
        'regime-configured' => 'regime_configured_on',
        'opened' => 'opened_on',
        'offering-confirmed' => 'offering_confirmed_on',
    ];

    public function handle(): int
    {
        $school = $this->school();
        if ($school === null) {
            return self::FAILURE;
        }

        $changes = [];
        foreach (self::DATES as $option => $column) {
            $value = $this->option($option);
            if ($value === null) {
                continue;
            }
            if ($value === 'none') {
                $changes[$column] = null;

                continue;
            }
            $date = $this->date((string) $value, "--{$option}");
            if ($date === null) {
                return self::FAILURE;
            }
            $changes[$column] = $date->toDateString();
        }
        foreach (['contact-name' => ['contact_name', 120], 'contact-channel' => ['contact_channel', 200]] as $option => [$column, $max]) {
            $value = $this->option($option);
            if ($value !== null) {
                $changes[$column] = $value === 'none' ? null : mb_substr(trim((string) $value), 0, $max);
            }
        }

        Tenancy::forSchool($school, function () use ($changes): void {
            $onboarding = SchoolOnboarding::query()->firstOrNew();
            $onboarding->fill($changes)->save();
        });

        $this->info("Onboarding status saved for \"{$school->name}\".");

        return self::SUCCESS;
    }
}
