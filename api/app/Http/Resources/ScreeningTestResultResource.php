<?php

namespace App\Http\Resources;

use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The anonymised view of a result (docs/prompts/11-pruebas-de-sondeo.md §3):
 * by code only, NEVER carrying `student_id` or the student's name. The
 * code -> student mapping is exposed solely by the roster endpoint
 * (ScreeningTestApplicationController::roster). Do not add `student_id` here.
 *
 * `band`/`color` are what the result was read as when loaded, with design
 * version `screening_test_design_id`. When the caller set the `inForceDesign`
 * relation and it is a different version, `current_band`/`current_color` are
 * the reading with the design in force and `band_changed` says whether the
 * band differs ("con el diseño anterior era X", decided 15/10).
 *
 * @mixin ScreeningTestResult
 */
class ScreeningTestResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $this->currentReading();

        return [
            'id' => $this->id,
            'screening_test_application_id' => $this->screening_test_application_id,
            'code' => $this->code,
            'score' => $this->score,
            'screening_test_design_id' => $this->screening_test_design_id,
            'band' => $this->band,
            'color' => $this->color?->value,
            'current_design_id' => $current['design_id'],
            'current_band' => $current['band'],
            'current_color' => $current['color'],
            'band_changed' => $current['changed'],
            'loaded_by_id' => $this->loaded_by_id,
            'loaded_at' => $this->loaded_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{design_id: int|null, band: string|null, color: string|null, changed: bool}
     */
    protected function currentReading(): array
    {
        /** @var ScreeningTestDesign|null $inForce */
        $inForce = $this->resource->relationLoaded('inForceDesign')
            ? $this->resource->getRelation('inForceDesign')
            : null;

        if ($inForce === null || $this->score === null) {
            return ['design_id' => $inForce?->id, 'band' => $this->band, 'color' => $this->color?->value, 'changed' => false];
        }

        if ($inForce->id === $this->screening_test_design_id) {
            return ['design_id' => $inForce->id, 'band' => $this->band, 'color' => $this->color?->value, 'changed' => false];
        }

        $band = $inForce->bandFor((float) $this->score);

        // A result loaded before free bands may have no band name stored: then
        // only the colour tells whether the reading changed (a null name is
        // "unknown", not "different").
        $nameChanged = $this->band !== null && $band['name'] !== $this->band;

        return [
            'design_id' => $inForce->id,
            'band' => $band['name'],
            'color' => $band['color'],
            'changed' => $nameChanged || $band['color'] !== $this->color?->value,
        ];
    }
}
