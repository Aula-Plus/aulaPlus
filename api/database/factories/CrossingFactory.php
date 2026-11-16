<?php

namespace Database\Factories;

use App\Models\Crossing;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Crossing>
 */
class CrossingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => 'Patrón instrumental a revisar',
            'conditions' => [],
            'reading' => 'No se asigna una conclusión cerrada.',
        ];
    }
}
