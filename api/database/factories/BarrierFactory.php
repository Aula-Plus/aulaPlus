<?php

namespace Database\Factories;

use App\Models\Barrier;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barrier>
 */
class BarrierFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (Barrier $barrier): void {
            $barrier->school_id ??= $barrier->student?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'description' => fake()->paragraph(),
            'coping_strategy' => fake()->optional()->sentence(),
            'active' => true,
            'created_by_id' => User::factory(),
            'deleted_by_id' => null,
        ];
    }
}
