<?php

namespace Database\Factories;

use App\Models\Accommodation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Accommodation>
 */
class AccommodationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (Accommodation $accommodation): void {
            $accommodation->school_id ??= $accommodation->student?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'type' => fake()->randomElement(['extra_time', 'adapted_material', 'visual_support']),
            'active' => true,
            'description' => fake()->paragraph(),
            'focus_area' => fake()->randomElement(['literacy', 'mathematics', 'attention']),
            'llm_rule' => null,
            'requires_external_approval' => false,
            'approved' => null,
            'created_by_id' => User::factory(),
            'deleted_by_id' => null,
        ];
    }
}
