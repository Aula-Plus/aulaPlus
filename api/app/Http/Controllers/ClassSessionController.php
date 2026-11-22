<?php

namespace App\Http\Controllers;

use App\Actions\ClassSessions\CloseClassSession;
use App\Enums\AIProposalStatus;
use App\Enums\AIProposalType;
use App\Enums\ClassSessionStatus;
use App\Enums\ObjectiveCoverage;
use App\Enums\Role;
use App\Http\Requests\ClassSessions\CloseClassSessionRequest;
use App\Models\AIProposal;
use App\Models\ClassSession;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\UnitObjective;
use App\Support\SchoolTime;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher's classes (screens 14 «Nueva clase» and 15 «Propuesta de
 * clase», ClickUp 86e3jujh0): «Tus clases», one confirmed class, and its
 * one-tap closing. Classes are created by confirming an AI proposal
 * (ApplyProposal); there is no other way to create one yet.
 */
class ClassSessionController extends Controller
{
    /** How many classes and drafts «Tus clases» lists. */
    public const LIST_LIMIT = 30;

    /**
     * GET /my-classes — «Tus clases» (P14-ASI-05): the drafts the teacher
     * asked for and not confirmed or discarded yet, and the classes of the
     * group-subjects they teach (the same ownership rule as the Calendar and
     * ClassSessionPolicy::update). Psicopedagogía and Dirección don't
     * generate classes: their list is empty.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole(Role::Teacher->value)) {
            return response()->json(['data' => ['drafts' => [], 'classes' => []]]);
        }

        $drafts = AIProposal::query()
            ->with('group:id,name')
            ->where('type', AIProposalType::ClassSession->value)
            ->where('requested_by_id', $user->id)
            ->whereIn('status', [AIProposalStatus::Pending->value, AIProposalStatus::Completed->value, AIProposalStatus::Error->value])
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        // A draft's subject is chosen directly or comes from its unit's plan
        // (as in AIProposalResource::classRequest), resolved for all drafts
        // in two queries instead of one per row.
        $unitSubjects = Unit::query()
            ->join('annual_plans', 'annual_plans.id', '=', 'units.annual_plan_id')
            ->whereIn('units.id', $drafts->map(fn (AIProposal $p) => $p->input_parameters['unit_id'] ?? null)->filter()->unique()->values())
            ->pluck('annual_plans.subject_id', 'units.id');
        $subjectIdOf = fn (AIProposal $p): ?int => isset($p->input_parameters['subject_id'])
            ? (int) $p->input_parameters['subject_id']
            : (isset($p->input_parameters['unit_id']) ? ($unitSubjects[(int) $p->input_parameters['unit_id']] ?? null) : null);
        $subjects = Subject::query()
            ->whereIn('id', $drafts->map($subjectIdOf)->filter()->unique()->values())
            ->get(['id', 'name'])
            ->keyBy('id');

        $drafts = $drafts->map(function (AIProposal $proposal) use ($subjectIdOf, $subjects): array {
            $subject = $subjects->get($subjectIdOf($proposal));

            return [
                'id' => $proposal->id,
                'title' => $proposal->raw_response['title'] ?? $proposal->input_parameters['focus'] ?? null,
                'group' => $proposal->group ? ['id' => $proposal->group->id, 'name' => $proposal->group->name] : null,
                'subject' => $subject ? ['id' => $subject->id, 'name' => $subject->name] : null,
                'date' => $proposal->input_parameters['date'] ?? null,
                'status' => $proposal->status->value,
            ];
        });

        $classes = ClassSession::query()
            ->with(['group:id,name', 'subject:id,name'])
            ->whereExists(function (QueryBuilder $query) use ($user): void {
                $query->selectRaw('1')
                    ->from('group_teacher')
                    ->whereColumn('group_teacher.group_id', 'class_sessions.group_id')
                    ->where('group_teacher.teacher_id', $user->id)
                    ->where(fn (QueryBuilder $inner) => $inner
                        ->whereNull('class_sessions.subject_id')
                        ->orWhereColumn('group_teacher.subject_id', 'class_sessions.subject_id'));
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (ClassSession $class): array => [
                'id' => $class->id,
                'title' => $class->title,
                'group' => $class->group ? ['id' => $class->group->id, 'name' => $class->group->name] : null,
                'subject' => $class->subject ? ['id' => $class->subject->id, 'name' => $class->subject->name] : null,
                'date' => $class->date->toDateString(),
                'status' => $class->status->value,
                'closing_result' => $class->closing_result?->value,
            ]);

        return response()->json(['data' => ['drafts' => $drafts->values(), 'classes' => $classes->values()]]);
    }

    /**
     * GET /class-sessions/{classSession} — a confirmed class: its sections,
     * the objectives it works with what its closing recorded, and the «¿por
     * qué?» of the proposal it came from. The owning teacher, Psicopedagogía
     * and Dirección read it (ClassSessionPolicy::view).
     */
    public function show(Request $request, ClassSession $classSession): JsonResponse
    {
        $this->authorize('view', $classSession);

        return response()->json(['data' => $this->detail($request, $classSession)]);
    }

    /**
     * POST /class-sessions/{classSession}/close — «Todo / Una parte / Nada»;
     * with «Una parte», each objective «Se dio» or «Se empezó» (or unmarked).
     */
    public function close(CloseClassSessionRequest $request, ClassSession $classSession, CloseClassSession $close): JsonResponse
    {
        $close(
            $classSession,
            ObjectiveCoverage::from($request->validated('result')),
            array_map('intval', (array) $request->validated('given_objective_ids', [])),
            array_map('intval', (array) $request->validated('started_objective_ids', [])),
        );

        return response()->json(['data' => $this->detail($request, $classSession->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(Request $request, ClassSession $class): array
    {
        $class->loadMissing(['group:id,name', 'subject:id,name', 'unit:id,name,position,annual_plan_id', 'unitObjectives']);
        $canClose = $request->user()->can('close', $class);

        $proposal = AIProposal::query()
            ->where('applied_to_type', $class->getMorphClass())
            ->where('applied_to_id', $class->id)
            ->latest('id')
            ->first();

        return [
            'id' => $class->id,
            'title' => $class->title,
            'date' => $class->date->toDateString(),
            'start_time' => $class->start_time,
            'room' => $class->room,
            'duration_minutes' => $class->duration_minutes,
            'status' => $class->status->value,
            'group' => $class->group ? ['id' => $class->group->id, 'name' => $class->group->name] : null,
            'subject' => $class->subject ? ['id' => $class->subject->id, 'name' => $class->subject->name] : null,
            'unit' => $class->unit ? [
                'id' => $class->unit->id,
                'name' => $class->unit->name,
                'position' => $class->unit->position,
                'annual_plan_id' => $class->unit->annual_plan_id,
            ] : null,
            'objective' => $class->objective,
            'description' => $class->description,
            'sections' => $class->sections ?? [],
            'objectives' => $class->unitObjectives
                ->sortBy('position')
                ->map(fn (UnitObjective $objective): array => [
                    'id' => $objective->id,
                    'description' => $objective->description,
                    'coverage' => $objective->pivot?->coverage,
                ])
                ->values()
                ->all(),
            'closing_result' => $class->closing_result?->value,
            'closed_at' => $class->closed_at?->toIso8601String(),
            'can_close' => $canClose,
            // Enabled from the day of the class on (P15-PRO-05).
            'closable_now' => $canClose
                && $class->status !== ClassSessionStatus::Cancelled
                && $class->date->toDateString() <= SchoolTime::today()->toDateString(),
            'proposal' => $proposal === null ? null : [
                'id' => $proposal->id,
                'rationale' => $proposal->raw_response['rationale'] ?? null,
                'data_used' => $proposal->raw_response['data_used'] ?? [],
                'planner' => $proposal->raw_response['planner'] ?? null,
            ],
        ];
    }
}
