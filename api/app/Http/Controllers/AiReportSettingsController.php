<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAiReportSettingRequest;
use App\Models\School;
use App\Support\Reports\AiReportSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/PUT /api/v1/ai-report-setting — the school's switch for the AI reading of
 * student reports (ClickUp 86e3jujgx). Any staff member reads it (the UI
 * explains what happens to the data); only direction writes. `available` is
 * false when our global brake is on or the provider is not configured: the
 * school's own switch then has no effect.
 */
class AiReportSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user()->school)]);
    }

    public function update(UpdateAiReportSettingRequest $request): JsonResponse
    {
        $school = School::query()->findOrFail($request->user()->school_id);
        $school->ai_report_interpretation_enabled = $request->boolean('enabled');
        $school->save();

        return response()->json(['data' => $this->payload($school->refresh())]);
    }

    /**
     * @return array<string, bool>
     */
    protected function payload(School $school): array
    {
        return [
            'enabled' => $school->ai_report_interpretation_enabled,
            'available' => AiReportSwitch::globallyEnabled(),
        ];
    }
}
