<?php

namespace Database\Factories;

use App\Enums\CurricularItemType;
use App\Models\CurricularCatalog;
use App\Models\CurricularItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurricularItem>
 */
class CurricularItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'curricular_catalog_id' => CurricularCatalog::factory(),
            'parent_id' => null,
            'type' => fake()->randomElement(CurricularItemType::cases()),
            'code' => strtoupper(fake()->bothify('??-###')),
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
