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
