<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\ScreeningTestType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreeningTestType>
 */
class ScreeningTestTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->randomElement(['Comprensión lectora', 'Razonamiento matemático', 'Escritura'])
                .' '.fake()->randomElement(['1er ciclo', '2do ciclo', '3er ciclo']),
            // Every population by default: a screening without one cannot be
            // applied (ClickUp 86e3t4qjh), see withoutPopulation().
            'levels' => ['primary', 'lower_secondary', 'upper_secondary'],
            'active' => true,
            'created_by_id' => User::factory(),
        ];
    }

    public function withoutPopulation(): static
    {
        return $this->state(['levels' => []]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
