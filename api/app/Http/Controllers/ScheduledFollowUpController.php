<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\GroupScheduledFollowUpsRequest;
use App\Http\Requests\ResolveScheduledFollowUpRequest;
use App\Http\Requests\StoreScheduledFollowUpRequest;
use App\Http\Resources\ScheduledFollowUpDetailResource;
use App\Http\Resources\ScheduledFollowUpResource;
use App\Http\Resources\StaffMemberResource;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\Student;
use App\Models\User;
use App\Policies\ScheduledFollowUpPolicy;
use App\Services\FollowUps\FollowUpWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Scheduled follow-ups on a student (docs/prompts/17-seguimiento-
 * programado.md §3). Every action is authorized in code — store/resolve via
 * their FormRequests, index via ScheduledFollowUpPolicy::viewForStudent.
 * Stages and people are managed by FollowUpStageController and
 * FollowUpMemberController (ClickUp 86e3qkwna).
 */
class ScheduledFollowUpController extends Controller
{
    public function index(Request $request, Student $student): AnonymousResourceCollection
    {
        $this->authorize('viewForStudent', [ScheduledFollowUp::class, $student]);

        $query = $student->scheduledFollowUps()->with(['assignedTo.roles', 'sharedWith.roles', 'members.user.roles'])->latest();

        // Review tasks of accommodation proposals are school-wide only
        // (ScheduledFollowUpPolicy::view).
        if (! $request->user()->hasAnyRole(Role::schoolWideValues())) {
            $query->whereNull('accommodation_id');
        }

        // The endpoint returns every follow-up unless the caller narrows it
        // with ?resolved=true|false — the default filter is a client concern,
        // not decided here (spec §3).
        if ($request->has('resolved')) {
            $query->where('resolved', $request->boolean('resolved'));
        }

        return ScheduledFollowUpResource::collection($query->get());
    }

    /**
     * GET /api/v1/groups/{group}/scheduled-follow-ups?overdue=true — the
     * follow-ups of a group's students (docs/prompts/21-perfil-de-grupo.md
     * §4). Endpoint access is GroupPolicy::view (enforced by the FormRequest:
     * a teacher who does not lead the group gets 403).
     *
     * Per-follow-up authorization is not repeated per row: ScheduledFollowUpPolicy
     * ::view grants access to a student iff the caller shares the school AND
     * (teaches the student OR is school-wide). Every student here belongs to
     * this group and the caller already passed GroupPolicy::view, so a teacher
     * leading the group teaches all of them and a school-wide role covers them
     * anyway — the per-row check was always true and only cost an N+1
     * (teachesStudent fires one query per follow-up). Optional ?overdue=true
     * narrows to overdue ones (is_overdue computed server-side, app timezone —
     * never trusting a client-computed flag).
     */
    public function indexForGroup(GroupScheduledFollowUpsRequest $request, Group $group): AnonymousResourceCollection
    {
        $studentIds = $group->students()->pluck('students.id');

        $followUps = ScheduledFollowUp::query()
            ->with(['assignedTo.roles', 'sharedWith.roles', 'members.user.roles'])
            ->whereIn('student_id', $studentIds)
            // Review tasks of accommodation proposals are school-wide only
            // (ScheduledFollowUpPolicy::view).
            ->when(! $request->user()->hasAnyRole(Role::schoolWideValues()), fn ($query) => $query->whereNull('accommodation_id'))
            ->latest()
            ->get();

        if ($request->boolean('overdue')) {
            $followUps = $followUps->filter->isOverdue();
        }

        return ScheduledFollowUpResource::collection($followUps->values());
    }

    /**
     * GET /api/v1/students/{student}/follow-up-candidates?role=teacher|psychopedagogue|director
     * — the people a follow-up on this student can be assigned to or shared
     * with: only those who already see the student (same rule as
     * ScheduledFollowUpPolicy). Filtering by role first and then picking a
     * person is how the form works. Exposes names and roles only.
     */
    public function candidates(Request $request, Student $student): AnonymousResourceCollection
    {
        $this->authorize('viewForStudent', [ScheduledFollowUp::class, $student]);

        $request->validate(['role' => ['nullable', 'string', 'in:'.implode(',', Role::values())]]);

        $users = User::query()
            ->where('school_id', $student->school_id)
            // A deactivated person can no longer log in to pick it up.
            ->whereNull('disabled_at')
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => app(ScheduledFollowUpPolicy::class)->canFollow($user, $student))
            ->when($request->filled('role'), fn ($users) => $users->filter->hasRole($request->string('role')->toString()));

        return StaffMemberResource::collection($users->values());
    }

    /**
     * GET /api/v1/scheduled-follow-ups/mine — the caller's pending list:
     * follow-ups they take part in (general responsible, stage responsible
     * or participant — stage responsibles are always members), including ones
     * they left until they choose to hide them, dropping any on a student
     * they can no longer see.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $followUps = ScheduledFollowUp::query()
            ->with(['assignedTo.roles', 'sharedWith.roles', 'members.user.roles', 'student'])
            ->where('resolved', false)
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id)->whereNull('hidden_at'))
            ->orderBy('due_date')
            ->get()
            ->filter(fn (ScheduledFollowUp $followUp) => $user->can('view', $followUp));

        return ScheduledFollowUpResource::collection($followUps->values());
    }

    public function store(StoreScheduledFollowUpRequest $request, Student $student, FollowUpWorkflow $workflow): JsonResponse
    {
        $followUp = $workflow->create($student, $request->user(), $request->validated());

        $followUp->load(['assignedTo.roles', 'sharedWith.roles', 'members.user.roles']);

        return (new ScheduledFollowUpResource($followUp))->response()->setStatusCode(201);
    }

    /**
     * GET /api/v1/scheduled-follow-ups/{followUp} — the full record (stages,
     * notes, people, history) for anyone who may see the student, with the
     * caller's `can` flags. Opened from "Mis seguimientos" and the student
     * file alike, so everyone involved sees the same up-to-date record.
     */
    public function show(Request $request, ScheduledFollowUp $followUp): ScheduledFollowUpDetailResource
    {
        $this->authorize('view', $followUp);

        return ScheduledFollowUpDetailResource::forViewer($followUp, $request->user());
    }

    public function resolve(ResolveScheduledFollowUpRequest $request, ScheduledFollowUp $followUp, FollowUpWorkflow $workflow): ScheduledFollowUpDetailResource
    {
        $workflow->resolve($followUp, $request->user(), $request->validated('resolution_note'));

        return ScheduledFollowUpDetailResource::forViewer($followUp, $request->user());
    }
}
