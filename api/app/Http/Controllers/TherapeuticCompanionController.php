<?php

namespace App\Http\Controllers;

use App\Http\Requests\TherapeuticCompanionRequest;
use App\Models\Student;
use App\Models\TherapeuticCompanion;
use App\Support\SchoolTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Screen 10 › Acompañantes (ClickUp 1dfd3mc4bge): who accompanies each
 * student, with weekly hours and days. Psychopedagogy assigns and edits;
 * direction reads the list without the reason (P10-DEC-09) — the field is
 * left out server-side, not hidden by the client. A teacher gets 403.
 */
class TherapeuticCompanionController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', TherapeuticCompanion::class);
        $year = SchoolTime::today()->year;

        $companions = TherapeuticCompanion::query()
            ->with(['student' => fn ($student) => $student->select('id', 'school_id', 'full_name')->with([
                'groups' => fn ($groups) => $groups->wherePivot('school_year', $year)->select('groups.id', 'groups.name'),
            ])])
            // A removed (soft-deleted) student's companions are not active.
            ->whereHas('student')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $companions->map(fn (TherapeuticCompanion $companion) => $this->present($companion))->values(),
        ]);
    }

    public function store(TherapeuticCompanionRequest $request): JsonResponse
    {
        $student = Student::query()->findOrFail($request->validated('student_id'));
        $this->authorize('create', [TherapeuticCompanion::class, $student]);

        $companion = DB::transaction(function () use ($request, $student): TherapeuticCompanion {
            $companion = TherapeuticCompanion::create([
                ...$request->safe()->only(['student_id', 'name', 'weekly_hours', 'days', 'reason']),
                'created_by_id' => $request->user()->id,
            ]);
            TherapeuticCompanion::syncStudentFlag($student);

            return $companion;
        });

        return response()->json(['data' => $this->present($companion->load('student.groups'))], 201);
    }

    public function update(TherapeuticCompanionRequest $request, TherapeuticCompanion $companion): JsonResponse
    {
        $this->authorize('update', $companion);

        $companion->update($request->safe()->only(['name', 'weekly_hours', 'days', 'reason']));

        return response()->json(['data' => $this->present($companion->load('student.groups'))]);
    }

    public function destroy(TherapeuticCompanion $companion): Response
    {
        $this->authorize('delete', $companion);

        DB::transaction(function () use ($companion): void {
            $student = $companion->student;
            $companion->delete();
            if ($student !== null) {
                TherapeuticCompanion::syncStudentFlag($student);
            }
        });

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(TherapeuticCompanion $companion): array
    {
        $group = $companion->student?->groups->first();

        $row = [
            'id' => $companion->id,
            'name' => $companion->name,
            'student' => $companion->student ? ['id' => $companion->student->id, 'name' => $companion->student->full_name] : null,
            'group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'weekly_hours' => $companion->weekly_hours,
            'days' => $companion->days,
        ];

        if (request()->user()->can('viewReason', TherapeuticCompanion::class)) {
            $row['reason'] = $companion->reason;
        }

        return $row;
    }
}
