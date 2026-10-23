<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateScreeningTestResultRequest;
use App\Http\Resources\ScreeningTestResultResource;
use App\Models\ScreeningTestResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loading a score onto a result (docs/prompts/11-pruebas-de-sondeo.md §3).
 * Psychopedagogy only (authorized in the Form Request). The band is read with
 * the design in force and persisted together with that design version, so a
 * later change of cutoffs never rewrites it: the result is then shown with
 * the current design and "con el diseño anterior era X" (decided 15/10).
 */
class ScreeningTestResultController extends Controller
{
    public function update(UpdateScreeningTestResultRequest $request, ScreeningTestResult $result): ScreeningTestResultResource
    {
        $design = $result->application->type->currentApprovedDesign();

        // A score cannot be loaded without a design in force (spec §3).
        abort_if(
            $design === null,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'This screening has no design in force.',
        );

        $score = (float) $request->validated('score');
        $band = $design->bandFor($score);

        $result->update([
            'score' => $score,
            'screening_test_design_id' => $design->id,
            'color' => $band['color'],
            'band' => $band['name'],
            'loaded_by_id' => $request->user()->id,
            'loaded_at' => now(),
        ]);

        $result->setRelation('inForceDesign', $design);

        return new ScreeningTestResultResource($result);
    }
}
