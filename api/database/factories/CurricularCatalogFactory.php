<?php

namespace Database\Factories;

use App\Models\CurricularCatalog;
use App\Models\CurricularFramework;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurricularCatalog>
 */
class CurricularCatalogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'curricular_framework_id' => CurricularFramework::factory(),
            'name' => fake()->year().' edition',
            'valid_from' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'valid_until' => null,
        ];
    }
}
