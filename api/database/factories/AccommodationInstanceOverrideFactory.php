<?php

namespace Database\Factories;

use App\Models\Accommodation;
use App\Models\AccommodationInstanceOverride;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccommodationInstanceOverride>
 */
class AccommodationInstanceOverrideFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (AccommodationInstanceOverride $override): void {
            // Keep the tenant consistent with the accommodation it overrides
            // when a school wasn't set explicitly (the tenant scope fills it
            // from the current school otherwise).
            $override->school_id ??= $override->accommodation?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'accommodation_id' => Accommodation::factory(),
            'assessment_id' => Assessment::factory(),
            'deactivated_by_id' => User::factory(),
            'reason' => fake()->sentence(),
        ];
    }
}
