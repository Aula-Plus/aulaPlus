<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimetableSlot>
 */
class TimetableSlotFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (TimetableSlot $slot): void {
            $slot->school_id ??= $slot->group?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'teacher_id' => User::factory(),
            'group_id' => Group::factory(),
            'subject_id' => Subject::factory(),
            'weekday' => fake()->numberBetween(1, 5),
            'start_time' => fake()->randomElement(['08:00', '08:30', '10:15', '11:30']),
            'room' => fake()->optional()->bothify('Aula ##'),
        ];
    }
}
