<?php

namespace Database\Factories;

use App\Models\ScheduledFollowUp;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledFollowUp>
 */
class ScheduledFollowUpFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (ScheduledFollowUp $followUp): void {
            // Keep the tenant consistent with the target student when the
            // caller didn't set it explicitly (mirrors AlertFactory).
            $followUp->school_id ??= $followUp->student?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'created_by_id' => User::factory(),
            'description' => fake()->sentence(),
            'due_date' => fake()->dateTimeBetween('-1 week', '+1 month')->format('Y-m-d'),
            'resolved' => false,
            'resolved_by_id' => null,
            'resolved_at' => null,
            'resolution_note' => null,
        ];
    }

    /**
     * Due yesterday and still open — the canonical overdue case.
     */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'due_date' => now()->subDay()->format('Y-m-d'),
            'resolved' => false,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'resolved' => true,
            'resolved_at' => now(),
        ]);
    }
}
