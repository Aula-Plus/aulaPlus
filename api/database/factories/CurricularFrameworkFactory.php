<?php

namespace Database\Factories;

use App\Enums\PlannerFamily;
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

    /** A framework that brings a given set of planner fields (e.g. 'anep'). */
    public function family(PlannerFamily $family): static
    {
        return $this->state(['planner_family' => $family]);
    }

    public function anep(): static
    {
        return $this->family(PlannerFamily::Anep);
    }
}
