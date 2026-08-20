<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechnicalReport>
 */
class TechnicalReportFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (TechnicalReport $technicalReport): void {
            $technicalReport->school_id ??= $technicalReport->student?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'document_url' => 'reports/'.fake()->uuid().'.pdf',
            'summary' => fake()->optional()->paragraph(),
            'attachments' => null,
            'uploaded_by_id' => User::factory(),
        ];
    }
}
