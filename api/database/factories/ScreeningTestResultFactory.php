<?php

namespace Database\Factories;

use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestResult;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreeningTestResult>
 */
class ScreeningTestResultFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (ScreeningTestResult $result): void {
            $result->school_id ??= $result->application?->school_id
                ?? $result->student?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'screening_test_application_id' => ScreeningTestApplication::factory(),
            'student_id' => Student::factory(),
            'code' => fake()->unique()->numerify('##'),
            'score' => null,
            'color' => null,
            'loaded_by_id' => null,
            'loaded_at' => null,
        ];
    }
}
