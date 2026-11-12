<?php

namespace Database\Factories;

use App\Enums\CalendarEventType;
use App\Models\CalendarEvent;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    public function definition(): array
    {
        $startAt = fake()->dateTimeBetween('now', '+3 months');

        return [
            'school_id' => School::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'start_at' => $startAt,
            'end_at' => (clone $startAt)->modify('+1 hour'),
            'type' => CalendarEventType::Institutional->value,
        ];
    }

    /**
     * A meeting (screen 10, reserved): private to the calendars it is linked to.
     */
    public function meeting(): static
    {
        return $this->state(['type' => CalendarEventType::Meeting->value]);
    }
}
