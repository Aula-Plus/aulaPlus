<?php

namespace App\Http\Controllers;

use App\Http\Requests\EndTherapeuticCompanionRequest;
use App\Http\Requests\TherapeuticCompanionRequest;
use App\Models\Student;
use App\Models\TherapeuticCompanion;
use App\Support\SchoolTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Screen 10 › Acompañantes (ClickUp 1dfd3mc4bge): who accompanies each
 * student, with weekly hours, days and the companion's role. Psychopedagogy
 * assigns, edits and ends a companion (an end date, never a delete) and reads
 * the history; direction reads the current companions only — the ended ones
 * are left out server-side. A teacher gets 403.
 */
class TherapeuticCompanionController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', TherapeuticCompanion::class);
        $year = SchoolTime::today()->year;
        $withHistory = request()->user()->can('viewHistory', TherapeuticCompanion::class);

        $companions = TherapeuticCompanion::query()
            ->with(['student' => fn ($student) => $student->select('id', 'school_id', 'full_name')->with([
                'groups' => fn ($groups) => $groups->wherePivot('school_year', $year)->select('groups.id', 'groups.name'),
            ])])
            // A removed (soft-deleted) student's companions are not active.
            ->whereHas('student')
            ->when(! $withHistory, fn ($query) => $query->current())
            // Current first, then the history from the most recently ended.
            ->orderByRaw('ended_on is not null')
            ->orderByDesc('ended_on')
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
                ...$request->safe()->only(['student_id', 'name', 'weekly_hours', 'days', 'role']),
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

        $companion->update($request->safe()->only(['name', 'weekly_hours', 'days', 'role']));

        return response()->json(['data' => $this->present($companion->load('student.groups'))]);
    }

    /**
     * «Dejó de acompañar»: the record gets an end date and stays in the
     * student's history (authorized in the FormRequest).
     */
    public function end(EndTherapeuticCompanionRequest $request, TherapeuticCompanion $companion): JsonResponse
    {
        DB::transaction(function () use ($request, $companion): void {
            $companion->forceFill([
                'ended_on' => $request->validated('ended_on'),
                'ended_by_id' => $request->user()->id,
            ])->save();
            if ($companion->student !== null) {
                TherapeuticCompanion::syncStudentFlag($companion->student);
            }
        });

        return response()->json(['data' => $this->present($companion->load('student.groups'))]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(TherapeuticCompanion $companion): array
    {
        $group = $companion->student?->groups->first();

        return [
            'id' => $companion->id,
            'name' => $companion->name,
            'student' => $companion->student ? ['id' => $companion->student->id, 'name' => $companion->student->full_name] : null,
            'group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'weekly_hours' => $companion->weekly_hours,
            'days' => $companion->days,
            'role' => $companion->role,
            'ended_on' => $companion->ended_on?->toDateString(),
        ];
    }
}
