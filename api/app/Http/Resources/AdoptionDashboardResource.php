<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Director-only pilot-adoption dashboard (docs/prompts/04-seguimiento-
 * institucional.md §5) — one of the three pilot success indicators (VIN
 * form). Wraps the plain array assembled by AdoptionDashboardController.
 *
 * The login indicators were removed (ClickUp 86e3qkxtc): the panel now shows
 * who started creating (`teacher_started_rate_30d`, null while there is no
 * record of those steps) next to who confirmed a creation, plus since when
 * each journey step has data.
 *
 * @mixin array{
 *     teacher_started_rate: float|null,
 *     teacher_planning_rate: float,
 *     data_since: array<string, string|null>,
 *     weekly_content_series: array,
 *     weekly_content_by_type: array,
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
            'teacher_started_rate_30d' => $this->resource['teacher_started_rate'],
            'teacher_planning_rate_30d' => $this->resource['teacher_planning_rate'],
            'data_since' => $this->resource['data_since'],
            'weekly_content_series' => $this->resource['weekly_content_series'],
            'weekly_content_by_type' => $this->resource['weekly_content_by_type'],
        ];
    }
}
