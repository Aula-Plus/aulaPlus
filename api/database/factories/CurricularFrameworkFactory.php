<?php

namespace Database\Factories;

use App\Models\CurricularFramework;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurricularFramework>
 */
class CurricularFrameworkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
        ];
    }
}
