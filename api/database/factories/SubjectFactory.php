<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->unique()->randomElement([
                'Matemática', 'Lengua', 'Inglés', 'Biología', 'Historia',
                'Geografía', 'Física', 'Química', 'Arte', 'Educación Física',
            ]),
            'short_code' => fake()->optional()->lexify('???'),
            'color' => fake()->optional()->hexColor(),
        ];
    }
}
