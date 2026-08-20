<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'full_name' => fake()->firstName().' '.fake()->lastName(),
            'photo_url' => null,
            'birth_date' => fake()->dateTimeBetween('-18 years', '-4 years')->format('Y-m-d'),
            'enrollment_year' => now()->year,
            'has_therapeutic_companion' => false,
            'learning_profile' => null,
            'tracking_notes' => null,
            'individual_profile' => null,
            'related_documents' => null,
        ];
    }
}
