<?php

namespace Database\Factories;

use App\Enums\ClassSessionStatus;
use App\Models\ClassSession;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSession>
 */
class ClassSessionFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (ClassSession $classSession): void {
            $classSession->school_id ??= $classSession->group?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'unit_id' => null,
            'assessment_id' => null,
            'date' => fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
            'duration_minutes' => fake()->randomElement([40, 45, 60, 90]),
            'title' => fake()->sentence(3),
            'objective' => fake()->optional()->sentence(),
            'description' => fake()->optional()->paragraph(),
            'outcome' => null,
            'teacher_notes' => null,
            'status' => ClassSessionStatus::Planned,
        ];
    }
}
