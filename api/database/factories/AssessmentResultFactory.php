<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssessmentResult>
 */
class AssessmentResultFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (AssessmentResult $result): void {
            $result->school_id ??= $result->assessment?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'student_id' => Student::factory(),
            'score' => fake()->randomFloat(2, 0, 100),
            'feedback' => fake()->optional()->sentence(),
            'created_by_id' => User::factory(),
        ];
    }
}
