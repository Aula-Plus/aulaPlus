<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreeningTestApplication>
 */
class ScreeningTestApplicationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (ScreeningTestApplication $application): void {
            $application->school_id ??= $application->group?->school_id
                ?? $application->type?->school_id;

            // Same snapshot CreateScreeningTestApplication takes, unless a test
            // sets it explicitly.
            if (! array_key_exists('education_level_id', $application->getAttributes())) {
                $level = $application->group?->educationLevel;
                $application->education_level_id = $level?->id;
                $application->screening_population = $level?->screening_population;
                $application->population_levels = $application->type?->levels ?? [];
            }
        });
    }

    public function definition(): array
    {
        return [
            'screening_test_type_id' => ScreeningTestType::factory(),
            'group_id' => Group::factory(),
            'applied_by_id' => User::factory(),
            'application_date' => now()->toDateString(),
        ];
    }
}
