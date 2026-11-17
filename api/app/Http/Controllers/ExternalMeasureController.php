<?php

namespace App\Http\Controllers;

use App\Actions\Indicators\MatchTemplateStudents;
use App\Http\Requests\MatchExternalMeasureStudentsRequest;
use App\Http\Requests\SaveExternalMeasureRequest;
use App\Http\Requests\StoreExternalMeasureValuesRequest;
use App\Models\ExternalMeasure;
use App\Models\ExternalMeasureValue;
use App\Models\Student;
use App\Services\Indicators\IndicatorData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Screen 30 «Datos»: a datum the school brings from outside, and its values
 * («Descargar la plantilla de Aula+», «Cargar desde Excel (CSV)», «Cargar a
 * mano»). Psicopedagogía and Dirección see and load data
 * (ExternalMeasurePolicy). Values are sensitive data about minors (CLAUDE.md
 * rule 11): they are never logged, and only this screen's roles read them.
 */
class ExternalMeasureController extends Controller
{
    public function store(SaveExternalMeasureRequest $request): JsonResponse
    {
        $measure = ExternalMeasure::create([
            ...$request->validated(),
            'taken_every_year' => (bool) $request->validated('taken_every_year', true),
            'created_by_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->present($measure)], Response::HTTP_CREATED);
    }

    public function update(SaveExternalMeasureRequest $request, ExternalMeasure $externalMeasure): JsonResponse
    {
        $externalMeasure->update($request->validated());

        return response()->json(['data' => $this->present($externalMeasure->fresh())]);
    }

    public function destroy(ExternalMeasure $externalMeasure): Response
    {
        $this->authorize('delete', $externalMeasure);

        $externalMeasure->delete();

        return response()->noContent();
    }

    /**
     * GET /external-measures/{id}/values?group_id= — this year's students
     * (of one group, or every group) with their value for the year: the
     * roster to load by hand and to fill the template.
     */
    public function values(Request $request, ExternalMeasure $externalMeasure): JsonResponse
    {
        $this->authorize('view', $externalMeasure);

        $validated = $request->validate([
            'group_id' => ['nullable', 'integer', Rule::exists('groups', 'id')->where('school_id', $request->user()->school_id)],
        ]);

        $data = app(IndicatorData::class);
        $current = ExternalMeasureValue::query()
            ->where('external_measure_id', $externalMeasure->id)
            ->where('school_year', $data->schoolYear())
            ->pluck('value', 'student_id');

        $rows = collect($data->roster())
            ->when(isset($validated['group_id']), fn ($roster) => $roster->where('group_id', (int) $validated['group_id']))
            ->sortBy([['group_name', 'asc'], ['name', 'asc']])
            ->map(fn (array $student): array => [
                'student_id' => $student['student_id'],
                'name' => $student['name'],
                'group_name' => $student['group_name'],
                'value' => isset($current[$student['student_id']]) ? (float) $current[$student['student_id']] : null,
            ])
            ->values()
            ->all();

        return response()->json(['data' => [
            'measure' => $this->present($externalMeasure),
            'school_year' => $data->schoolYear(),
            'rows' => $rows,
        ]]);
    }

    /**
     * POST /external-measures/{id}/match-students — who each row of an
     * uploaded CSV is: by its Aula+ code, else by name (and group). A name
     * shared by several students comes back `ambiguous` with each one's code
     * so the person picks (PO answer of 17/11); nothing is saved here.
     */
    public function matchStudents(MatchExternalMeasureStudentsRequest $request, ExternalMeasure $externalMeasure): JsonResponse
    {
        $match = new MatchTemplateStudents(app(IndicatorData::class));

        return response()->json(['data' => ['rows' => $match($request->rows())]]);
    }

    /**
     * PUT /external-measures/{id}/values — load values for a school year
     * (this one by default). Rows of another school's student or outside the
     * scale are skipped and counted back.
     */
    public function storeValues(StoreExternalMeasureValuesRequest $request, ExternalMeasure $externalMeasure): JsonResponse
    {
        $year = (int) ($request->validated('school_year') ?? app(IndicatorData::class)->schoolYear());
        $rows = collect($request->validated('values'));
        // SchoolScope: only this school's students.
        $known = Student::query()->whereIn('id', $rows->pluck('student_id'))->pluck('id')->flip();
        $saved = 0;
        $cleared = 0;
        $skipped = 0;

        // All or nothing: a file never stays half loaded.
        DB::transaction(function () use ($rows, $known, $externalMeasure, $year, $request, &$saved, &$cleared, &$skipped): void {
            foreach ($rows as $row) {
                $studentId = (int) $row['student_id'];
                $value = $row['value'];

                if (! $known->has($studentId)) {
                    $skipped++;

                    continue;
                }

                $key = ['external_measure_id' => $externalMeasure->id, 'student_id' => $studentId, 'school_year' => $year];

                if ($value === null || $value === '') {
                    $cleared += ExternalMeasureValue::query()->where($key)->delete();

                    continue;
                }

                $value = (float) $value;

                if ($value < $externalMeasure->scale_min || $value > $externalMeasure->scale_max) {
                    $skipped++;

                    continue;
                }

                ExternalMeasureValue::query()->updateOrCreate($key, ['value' => $value, 'loaded_by_id' => $request->user()->id]);
                $saved++;
            }
        });

        return response()->json(['data' => [
            'saved' => $saved,
            'cleared' => $cleared,
            'skipped' => $skipped,
            'school_year' => $year,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(ExternalMeasure $measure): array
    {
        return [
            'id' => $measure->id,
            'name' => $measure->name,
            'scale_min' => (float) $measure->scale_min,
            'scale_max' => (float) $measure->scale_max,
            'taken_every_year' => (bool) $measure->taken_every_year,
        ];
    }
}
