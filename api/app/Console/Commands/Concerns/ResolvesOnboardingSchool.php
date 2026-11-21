<?php

namespace App\Console\Commands\Concerns;

use App\Models\School;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * Shared bits of the `app:onboarding-*` commands (screen 29): the school by id
 * and strict Y-m-d dates.
 */
trait ResolvesOnboardingSchool
{
    protected function school(): ?School
    {
        $school = School::query()->find((int) $this->argument('school'));
        if ($school === null) {
            $this->error('No school with id '.$this->argument('school').'.');
        }

        return $school;
    }

    /** A Y-m-d date, or null when invalid (an error is printed). */
    protected function date(string $value, string $label): ?CarbonImmutable
    {
        if (Validator::make(['d' => $value], ['d' => 'date_format:Y-m-d'])->fails()) {
            $this->error("{$label}: use YYYY-MM-DD (got \"{$value}\").");

            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value);
    }
}
