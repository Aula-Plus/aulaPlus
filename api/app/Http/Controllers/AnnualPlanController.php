<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AnnualPlans\StoreAnnualPlanRequest;
use App\Http\Requests\AnnualPlans\UpdateAnnualPlanRequest;
use App\Models\AnnualPlan;
use App\Models\CurricularFramework;
use App\Services\AnnualPlans\AnnualPlanProgress;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Screen 12 of the living document — Programa anual (ClickUp 1dfd3mc4bg8).
 *
 * The teacher builds the annual plan of each group-subject they teach;
 * Psicopedagogía and Dirección read any plan of the school (AnnualPlanPolicy).
 * Individualized plans (`student_id` set, the legacy PEI shape) are not part
 * of this screen and are never listed here.
 */
class AnnualPlanController extends Controller
{
    public function __construct(private readonly AnnualPlanProgress $progress) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AnnualPlan::class);
        $user = $request->user();

        $plans = AnnualPlan::query()
            ->whereNull('student_id')
            ->when(
                ! $user->hasAnyRole(Role::schoolWideValues()),
                fn ($query) => $query->where('teacher_id', $user->id),
            )
            ->with(['group:id,name', 'subject:id,name', 'teacher:id,name', 'curricularFramework:id,name'])
            ->withCount('units')
            ->orderByDesc('year')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $plans->map(fn (AnnualPlan $plan): array => $this->summary($plan))->values(),
        ]);
    }

    /**
     * What a teacher can create a plan for: the group-subjects they are
     * assigned (group_teacher), plus the school's active curricular frameworks.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AnnualPlan::class);
        $schoolId = Tenancy::schoolId();

        $assignments = DB::table('group_teacher')
            ->join('groups', 'groups.id', '=', 'group_teacher.group_id')
            ->join('subjects', 'subjects.id', '=', 'group_teacher.subject_id')
            ->where('group_teacher.teacher_id', $request->user()->id)
            ->where('groups.school_id', $schoolId)
            ->where('subjects.school_id', $schoolId)
            ->orderBy('groups.name')
            ->orderBy('subjects.name')
            ->get(['groups.id as group_id', 'groups.name as group_name', 'subjects.id as subject_id', 'subjects.name as subject_name']);

        $frameworks = CurricularFramework::query()
            ->whereHas('schools', fn ($q) => $q->where('schools.id', $schoolId)->where('school_curricular_framework.active', true))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => [
                'assignments' => $assignments,
                'frameworks' => $frameworks,
            ],
        ]);
    }

    public function store(StoreAnnualPlanRequest $request): JsonResponse
    {
        $plan = AnnualPlan::create([
            'group_id' => $request->integer('group_id'),
            'subject_id' => $request->integer('subject_id'),
            'curricular_framework_id' => $request->integer('curricular_framework_id'),
            'teacher_id' => $request->user()->id,
            'year' => $request->integer('year'),
            'description' => (string) $request->validated('description'),
            'language' => 'es',
        ]);

        return response()->json(['data' => $this->detail($plan, $request)], 201);
    }

    public function show(Request $request, AnnualPlan $annualPlan): JsonResponse
    {
        $this->authorize('view', $annualPlan);

        return response()->json(['data' => $this->detail($annualPlan, $request)]);
    }

    public function update(UpdateAnnualPlanRequest $request, AnnualPlan $annualPlan): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('description', $data)) {
            $data['description'] = (string) $data['description'];
        }
        $annualPlan->update($data);

        return response()->json(['data' => $this->detail($annualPlan->refresh(), $request)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(AnnualPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'year' => $plan->year,
            'group' => ['id' => $plan->group?->id, 'name' => $plan->group?->name],
            'subject' => ['id' => $plan->subject?->id, 'name' => $plan->subject?->name],
            'teacher' => ['id' => $plan->teacher?->id, 'name' => $plan->teacher?->name],
            'framework' => ['id' => $plan->curricularFramework?->id, 'name' => $plan->curricularFramework?->name],
            'units_count' => $plan->units_count ?? $plan->units()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(AnnualPlan $plan, Request $request): array
    {
        $plan->loadMissing(['group:id,name', 'subject:id,name', 'teacher:id,name', 'curricularFramework:id,name']);
        $progress = $this->progress->for($plan);

        return [
            ...$this->summary($plan),
            'units_count' => count($progress['units']),
            'description' => $plan->description,
            'can_edit' => $request->user()->can('update', $plan),
            ...$progress,
        ];
    }
}
