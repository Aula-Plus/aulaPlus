<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'user_id' => User::factory(),
            'event_type' => 'login',
            'metadata' => null,
            'created_at' => now(),
        ];
    }

    public function ofType(string $eventType): static
    {
        return $this->state(['event_type' => $eventType]);
    }
}
