<?php

namespace Database\Factories;

use App\Models\AnnualPlan;
use App\Models\CurricularFramework;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnnualPlan>
 */
class AnnualPlanFactory extends Factory
{
    /**
     * AnnualPlan's school_id is not part of the factory definition on
     * purpose: it must always match its group's school, so it is derived
     * from the (possibly factory-created) group instead of being set
     * independently.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (AnnualPlan $annualPlan): void {
            $annualPlan->school_id ??= $annualPlan->group?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'curricular_framework_id' => CurricularFramework::factory(),
            'teacher_id' => User::factory(),
            'student_id' => null,
            'description' => fake()->paragraph(),
            'year' => now()->year,
            'subject' => fake()->randomElement(['Matemática', 'Lengua', 'Ciencias', 'Inglés']),
            'language' => 'Español',
        ];
    }
}
