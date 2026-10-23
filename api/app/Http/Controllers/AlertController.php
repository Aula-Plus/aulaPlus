<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Read/resolve endpoints for Alerts (docs/prompts/04-seguimiento-
 * institucional.md §4). Alerts hold behavior/performance concerns about a
 * minor: every list is filtered through Alert::scopeVisibleTo() and every
 * single-alert action goes through AlertPolicy.
 */
class AlertController extends Controller
{
    /**
     * GET /api/v1/alerts — the open alerts that reach the current user
     * (ClickUp 86e3jpzcv: "le llega a Mariana, que da Matemática en su
     * grupo"). Oldest condition first, so nothing waits behind newer ones.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Alert::class);

        $alerts = Alert::query()
            ->visibleTo($request->user())
            ->where('resolved', false)
            ->with(['student:id,full_name', ...AlertResource::RELATIONS])
            ->orderBy('created_at')
            ->get()
            ->each(fn (Alert $alert) => $alert->markVisibleTo($request->user()));

        return AlertResource::collection($alerts);
    }

    public function forStudent(Request $request, Student $student): AnonymousResourceCollection
    {
        $this->authorize('view', $student);

        return AlertResource::collection(
            $student->alerts()->visibleTo($request->user())->with(AlertResource::RELATIONS)->latest()->get()
                ->each(fn (Alert $alert) => $alert->markVisibleTo($request->user()))
        );
    }

    public function forGroup(Request $request, Group $group): AnonymousResourceCollection
    {
        $this->authorize('viewForGroup', [Alert::class, $group]);

        $studentIds = $group->students()->pluck('students.id');

        $alerts = Alert::query()
            ->whereIn('student_id', $studentIds)
            ->visibleTo($request->user())
            ->with(AlertResource::RELATIONS)
            ->latest()
            ->get()
            ->each(fn (Alert $alert) => $alert->markVisibleTo($request->user()));

        return AlertResource::collection($alerts);
    }

    public function resolve(Alert $alert): AlertResource
    {
        $this->authorize('resolve', $alert);

        $resolution = [
            'resolved' => true,
            'resolved_by_id' => request()->user()->id,
            'resolved_at' => now(),
        ];

        $alert = DB::transaction(function () use ($alert, $resolution): Alert {
            // Lock the row and re-check on what it holds now, as choosing a
            // way out does: it may have just been handed off from another tab
            // (so this user no longer has it) or closed.
            $alert = Alert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            if (! $alert->canBeResolvedBy(request()->user())) {
                throw new AuthorizationException;
            }

            $alert->update($resolution);

            // A legacy escalated alert (no longer created) has no way out of
            // its own: it closes when the alert it escalates moves —
            // including when that alert is resolved. Linked follow-ups are
            // separate records and stay as they are.
            $alert->escalations()->where('resolved', false)->update($resolution);

            return $alert;
        });

        return new AlertResource($alert->load(AlertResource::RELATIONS));
    }
}
