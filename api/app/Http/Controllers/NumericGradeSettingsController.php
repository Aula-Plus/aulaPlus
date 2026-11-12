<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNumericGradeSettingsRequest;
use App\Models\School;
use App\Models\SchoolScaleEquivalence;
use App\Services\ResultScales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET/PUT /api/v1/numeric-grade-settings — what the school decides about its
 * Primaria scale (screen 24, P24-EVA-03 / P24-ESC-02; ClickUp 1dfd3mc4bgg):
 *
 * - `show_numeric_grade`: «Mostrar la calificación numérica junto al nivel de
 *   avance», one switch for every place a Primaria grade is shown.
 * - `equivalences`: the school's own, optional level → 1..10 number for its
 *   Primaria scale, replaced as a whole (empty = no equivalence: the teacher
 *   writes the number with the level).
 *
 * Direction only, for reading and writing (part of screen 24).
 */
class NumericGradeSettingsController extends Controller
{
    public function show(Request $request, ResultScales $scales): JsonResponse
    {
        $school = School::query()->findOrFail($request->user()->school_id);
        $this->authorize('viewOfficialScales', $school);

        return response()->json(['data' => $this->payload($school, $scales)]);
    }

    public function update(UpdateNumericGradeSettingsRequest $request, ResultScales $scales): JsonResponse
    {
        $school = $request->school();

        DB::transaction(function () use ($request, $school): void {
            $school->show_primary_numeric_grade = $request->boolean('show_numeric_grade');
            $school->save();

            SchoolScaleEquivalence::query()->where('school_id', $school->id)->delete();

            foreach ($request->validated('equivalences') ?? [] as $row) {
                SchoolScaleEquivalence::query()->create([
                    'school_id' => $school->id,
                    'official_scale_level_id' => (int) $row['level_id'],
                    'numeric_grade' => (int) $row['numeric_grade'],
                ]);
            }
        });

        return response()->json(['data' => $this->payload($school->refresh(), $scales)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(School $school, ResultScales $scales): array
    {
        $primary = $scales->primaryScaleForSchool($school);
        $equivalences = $scales->equivalences($school);

        return [
            'show_numeric_grade' => (bool) $school->show_primary_numeric_grade,
            'primary_scale_id' => $primary?->id,
            'equivalences' => $primary
                ? $primary->levels
                    ->filter(fn ($level) => isset($equivalences[$level->id]))
                    ->map(fn ($level) => ['level_id' => $level->id, 'numeric_grade' => $equivalences[$level->id]])
                    ->values()
                    ->all()
                : [],
        ];
    }
}
