<?php

namespace Database\Factories;

use App\Enums\AnepPrimaryBody;
use App\Enums\AnepSecondaryBody;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Escuela '.fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'logo_url' => null,
            'anep_authorization_type' => null,
            'anep_primary_body' => AnepPrimaryBody::NotApplicable,
            'anep_secondary_body' => AnepSecondaryBody::NotApplicable,
            'levels_offered' => ['Inicial', 'Primaria', 'Secundaria'],
            'instruction_languages' => ['Español'],
        ];
    }
}
