<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            ->get();

        return AlertResource::collection($alerts);
    }

    public function forStudent(Request $request, Student $student): AnonymousResourceCollection
    {
        $this->authorize('view', $student);

        return AlertResource::collection(
            $student->alerts()->visibleTo($request->user())->with(AlertResource::RELATIONS)->latest()->get()
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
            ->get();

        return AlertResource::collection($alerts);
    }

    public function resolve(Alert $alert): AlertResource
    {
        $this->authorize('resolve', $alert);

        $alert->update([
            'resolved' => true,
            'resolved_by_id' => request()->user()->id,
            'resolved_at' => now(),
        ]);

        return new AlertResource($alert->load(AlertResource::RELATIONS));
    }
}
