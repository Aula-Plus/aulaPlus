<?php

namespace App\Http\Resources;

use App\Models\AlertRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AlertRule
 */
class AlertRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'condition' => $this->condition->value,
            'threshold' => (float) $this->threshold,
            'consecutive_count' => $this->consecutive_count,
            'period_days' => $this->period_days,
            'subject_id' => $this->subject_id,
            'active' => $this->active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
