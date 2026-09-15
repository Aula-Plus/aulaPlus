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
            // A recognizable Spanish subject word plus a process-unique suffix.
            // The suffix keeps names unique across the many subjects a full test
            // run creates (the `(school_id, name)` unique index would otherwise
            // clash), without exhausting a small fixed pool.
            'name' => fake()->randomElement([
                'Matemática', 'Lengua', 'Inglés', 'Biología', 'Historia',
                'Geografía', 'Física', 'Química', 'Arte', 'Educación Física',
            ]).' '.fake()->unique()->numberBetween(1, 1_000_000),
            'short_code' => fake()->optional()->lexify('???'),
            'color' => fake()->optional()->hexColor(),
        ];
    }
}
