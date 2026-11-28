<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\School;
use App\Models\User;
use App\Services\DemoSchool\DemoSchoolBuilder;
use App\Services\DemoSchool\SchoolDataEraser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Wipes and rebuilds the test school a school's leadership walks through
 * before a pilot (ClickUp 1dfd3mc5g1p): its structure, fictitious students,
 * one account per role and the fictitious accommodation case.
 *
 * It only ever deletes the school whose slug the chosen profile names
 * (DemoSchoolBuilder::PROFILES), and refuses when that slug belongs to a school
 * it did not build. The emails are chosen by whoever runs it; the passwords
 * are random and printed once (security rule #1).
 *
 * It is meant for staging (Eitán, 26/11). `--allow-production` exists only
 * because a staging server may run with APP_ENV=production; it is not for
 * the production database of a real school.
 *
 * Usage:
 *   php artisan app:demo-school --director=… --psychopedagogue=… --teacher=…
 */
class DemoSchool extends Command
{
    protected $signature = 'app:demo-school
        {--profile=integral : Which test school to rebuild}
        {--director= : Email of the Dirección account}
        {--psychopedagogue= : Email of the Psicopedagogía account}
        {--teacher= : Email of the teacher account}
        {--force : Do not ask before wiping the existing test school}
        {--allow-production : Run even when APP_ENV is production (only for a staging server that runs as production)}';

    protected $description = 'Wipe and rebuild a test school with fictitious students and one account per role';

    public function handle(DemoSchoolBuilder $builder, SchoolDataEraser $eraser): int
    {
        if (app()->environment('production') && ! $this->option('allow-production')) {
            $this->error('This environment is production. Pass --allow-production to build the test school here.');

            return self::FAILURE;
        }

        try {
            $profile = DemoSchoolBuilder::profile((string) $this->option('profile'));
        } catch (InvalidArgumentException) {
            $this->error('Unknown profile. Available: '.implode(', ', array_keys(DemoSchoolBuilder::PROFILES)).'.');

            return self::FAILURE;
        }

        $emails = $this->emails();
        if ($emails === null) {
            return self::FAILURE;
        }

        $existing = School::query()->where('slug', $profile['slug'])->first();

        if ($existing !== null) {
            if ($existing->name !== $profile['name']) {
                $this->error("A school with slug \"{$profile['slug']}\" exists but is not the test school. Nothing was deleted.");

                return self::FAILURE;
            }

            if (! $this->option('force') && ! $this->confirm("Delete \"{$existing->name}\" and everything in it, then rebuild it?")) {
                $this->info('Nothing changed.');

                return self::SUCCESS;
            }
        }

        $taken = User::query()
            ->whereIn('email', array_values($emails))
            ->when($existing !== null, fn ($query) => $query->where('school_id', '!=', $existing->id))
            ->pluck('email');
        if ($taken->isNotEmpty()) {
            $this->error('These emails already belong to users of another school: '.$taken->implode(', ').'.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($existing, $eraser, $builder, $emails) {
            if ($existing !== null) {
                $eraser->erase($existing);
            }

            return $builder->build((string) $this->option('profile'), $emails);
        });

        $this->info("Built \"{$result['school']->name}\" (school #{$result['school']->id}).");
        $this->warn('These passwords are shown only once. Hand them over now; they cannot be recovered.');
        $this->table(
            ['Role', 'Email', 'Password'],
            array_map(fn (array $account) => [$account['role']->value, $account['email'], $account['password']], $result['accounts']),
        );
        $this->line('Next: sign in as the teacher and create the two classes with «Nueva clase» (see the PR of ClickUp 1dfd3mc5g1p).');

        return self::SUCCESS;
    }

    /** @return array<string, string>|null role value => email */
    protected function emails(): ?array
    {
        $emails = [];

        foreach ([Role::Director, Role::Psychopedagogue, Role::Teacher] as $role) {
            $emails[$role->value] = strtolower(trim((string) $this->option($role->value)));
        }

        $validator = validator($emails, array_fill_keys(array_keys($emails), ['required', 'email']));
        if ($validator->fails()) {
            $this->error('Pass a distinct, valid email for each of --director, --psychopedagogue and --teacher.');

            return null;
        }

        if (count(array_unique($emails)) !== count($emails)) {
            $this->error('The three emails must be different.');

            return null;
        }

        return $emails;
    }
}
