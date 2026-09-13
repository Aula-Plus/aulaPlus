<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateScreeningTestResultRequest;
use App\Http\Resources\ScreeningTestResultResource;
use App\Models\ScreeningTestResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loading a score onto a result (docs/prompts/11-pruebas-de-sondeo.md §3).
 * Psychopedagogy only (authorized in the Form Request). The `color` is
 * computed against the type's CURRENT approved design and persisted here — it
 * is never re-derived on read, so approving a later design does not change
 * this already-loaded color.
 */
class ScreeningTestResultController extends Controller
{
    public function update(UpdateScreeningTestResultRequest $request, ScreeningTestResult $result): ScreeningTestResultResource
    {
        $design = $result->application->type->currentApprovedDesign();

        // The design may have been rejected after the application was created
        // (spec §3): a score cannot be loaded without an approved design.
        abort_if(
            $design === null,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'This screening test type has no approved design in force.',
        );

        $score = (float) $request->validated('score');

        $result->update([
            'score' => $score,
            'color' => $design->colorFor($score),
            'loaded_by_id' => $request->user()->id,
            'loaded_at' => now(),
        ]);

        return new ScreeningTestResultResource($result);
    }
}
