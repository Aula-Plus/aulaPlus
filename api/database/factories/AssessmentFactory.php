<?php

namespace Database\Factories;

use App\Enums\AssessmentType;
use App\Models\Assessment;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 */
class AssessmentFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (Assessment $assessment): void {
            $assessment->school_id ??= $assessment->group?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'teacher_id' => User::factory(),
            'type' => fake()->randomElement(AssessmentType::cases()),
            'purpose' => fake()->optional()->sentence(),
            'duration_minutes' => fake()->numberBetween(20, 90),
            'content' => null,
            'variant_number' => 1,
        ];
    }
}
