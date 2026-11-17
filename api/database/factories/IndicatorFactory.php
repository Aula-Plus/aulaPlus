<?php

namespace Database\Factories;

use App\Models\Indicator;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Indicator>
 */
class IndicatorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => 'Herramienta de lectura',
            'calculation' => 'raw',
            'inputs' => [],
            'base_100' => false,
            'z_population' => null,
            'bands' => [
                ['name' => 'Bajo', 'color' => 'red', 'max' => 39, 'meaning' => 'Seguimiento cercano.'],
                ['name' => 'Medio', 'color' => 'yellow', 'max' => 59, 'meaning' => 'Se vuelve a mirar.'],
                ['name' => 'Esperado', 'color' => 'green', 'max' => null, 'meaning' => 'Sin acción.'],
            ],
            'display_places' => ['cross_chart'],
            'teacher_visible' => false,
        ];
    }
}
