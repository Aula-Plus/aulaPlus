<?php

namespace Database\Factories;

use App\Enums\AlertCondition;
use App\Models\AlertRule;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'condition' => AlertCondition::ConsecutiveBelow,
            'threshold' => 5,
            'consecutive_count' => 3,
            'period_days' => null,
            'subject_id' => null,
            'active' => true,
        ];
    }

    public function averageBelow(float $threshold = 6, int $periodDays = 90): static
    {
        return $this->state(fn () => [
            'condition' => AlertCondition::AverageBelow,
            'threshold' => $threshold,
            'consecutive_count' => null,
            'period_days' => $periodDays,
        ]);
    }
}
