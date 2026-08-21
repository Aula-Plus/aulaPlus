<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (Alert $alert): void {
            $alert->school_id ??= $alert->student?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'type' => AlertType::Behavior,
            'severity' => AlertSeverity::Medium,
            'description' => fake()->sentence(),
            'resolved' => false,
            'resolved_by_id' => null,
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'resolved' => true,
            'resolved_at' => now(),
        ]);
    }
}
