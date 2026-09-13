<?php

namespace Database\Factories;

use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreeningTestDesign>
 */
class ScreeningTestDesignFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (ScreeningTestDesign $design): void {
            $design->school_id ??= $design->type?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'screening_test_type_id' => ScreeningTestType::factory(),
            'cutoff_low' => 40,
            'cutoff_high' => 70,
            'meaning_red' => 'Requiere apoyo intensivo.',
            'meaning_yellow' => 'En proceso, seguimiento cercano.',
            'meaning_green' => 'Desempeño esperado.',
            'created_by_id' => User::factory(),
            'approved' => null,
            'approved_by_id' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(['approved' => true]);
    }

    public function rejected(): static
    {
        return $this->state(['approved' => false]);
    }
}
