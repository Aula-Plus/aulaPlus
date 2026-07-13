<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'teacher_id' => null,
            'name' => fake()->numberBetween(1, 7).'° '.fake()->randomLetter(),
            'level' => fake()->randomElement(['Inicial', 'Primaria', 'Secundaria']),
            'year' => (string) now()->year,
        ];
    }
}
