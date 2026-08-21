<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Director-only pilot-adoption dashboard (docs/prompts/04-seguimiento-
 * institucional.md §5) — one of the three pilot success indicators (VIN
 * form). Wraps the plain array assembled by AdoptionDashboardController.
 *
 * @mixin array{
 *     teacher_login_rate: float,
 *     teacher_planning_rate: float,
 *     weekly_login_series: array,
 *     weekly_content_series: array,
 * }
 */
class AdoptionDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'teacher_login_rate_30d' => $this->resource['teacher_login_rate'],
            'teacher_planning_rate_30d' => $this->resource['teacher_planning_rate'],
            'weekly_login_series' => $this->resource['weekly_login_series'],
            'weekly_content_series' => $this->resource['weekly_content_series'],
        ];
    }
}
