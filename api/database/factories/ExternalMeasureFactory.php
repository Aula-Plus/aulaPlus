<?php

namespace Database\Factories;

use App\Models\ExternalMeasure;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalMeasure>
 */
class ExternalMeasureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => 'Prueba de vocabulario',
            'scale_min' => 0,
            'scale_max' => 100,
            'taken_every_year' => true,
        ];
    }
}
