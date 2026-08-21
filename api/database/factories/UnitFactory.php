<?php

namespace Database\Factories;

use App\Models\AnnualPlan;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-2 months', 'now');

        return [
            'annual_plan_id' => AnnualPlan::factory(),
            'name' => 'Unidad '.fake()->numberBetween(1, 10),
            'position' => fake()->numberBetween(1, 10),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => (clone $startDate)->modify('+3 weeks')->format('Y-m-d'),
            'materials' => null,
        ];
    }
}
