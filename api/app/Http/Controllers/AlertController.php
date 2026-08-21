<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read/resolve endpoints for Alerts (docs/prompts/04-seguimiento-
 * institucional.md §4). Alerts hold behavior/performance concerns about a
 * minor, so every action here is gated the same way as the student's full
 * clinical profile — see AlertPolicy.
 */
class AlertController extends Controller
{
    public function forStudent(Student $student): AnonymousResourceCollection
    {
        $this->authorize('view-clinical-profile', $student);

        return AlertResource::collection(
            $student->alerts()->latest()->get()
        );
    }

    public function forGroup(Group $group): AnonymousResourceCollection
    {
        $this->authorize('viewForGroup', [Alert::class, $group]);

        $studentIds = $group->students()->pluck('students.id');

        $alerts = Alert::query()
            ->whereIn('student_id', $studentIds)
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

        return new AlertResource($alert);
    }
}
