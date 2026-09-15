<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGroupTeacherAssignmentRequest;
use App\Http\Resources\GroupTeacherAssignmentResource;
use App\Models\Group;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Director-managed teacher-subject assignments for a group (rows of the
 * group_teacher pivot). One row = "this teacher teaches this subject to this
 * group". Read is allowed to anyone who can view the group; writes require the
 * director (enforced by StoreGroupTeacherAssignmentRequest via GroupPolicy).
 */
class GroupTeacherAssignmentController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        $this->authorize('view', $group);

        // Each pivot row is one assignment. Map the (teacher, subject_id) rows
        // to a display shape, resolving subject names in one query.
        $rows = $group->teachers()->get();
        $subjectNames = Subject::query()
            ->whereIn('id', $rows->pluck('pivot.subject_id')->unique())
            ->pluck('name', 'id');

        $rows->each(function ($teacher) use ($subjectNames): void {
            $teacher->subjectName = $subjectNames[$teacher->pivot->subject_id] ?? null;
        });

        return GroupTeacherAssignmentResource::collection($rows);
    }

    public function store(StoreGroupTeacherAssignmentRequest $request, Group $group): JsonResponse
    {
        $data = $request->validated();

        // Idempotent attach: syncWithoutDetaching keyed by the pair avoids a
        // duplicate-key error on the (group, teacher, subject) unique index.
        $group->teachers()->syncWithoutDetaching([
            $data['teacher_id'] => ['subject_id' => $data['subject_id']],
        ]);

        return response()->json(status: 201);
    }

    public function destroy(StoreGroupTeacherAssignmentRequest $request, Group $group): Response
    {
        $data = $request->validated();

        // Precise pivot delete: BelongsToMany::detach($id) would remove every
        // pivot row for the teacher, ignoring wherePivot — delete only the one
        // (group, teacher, subject) pair.
        DB::table('group_teacher')
            ->where('group_id', $group->id)
            ->where('teacher_id', $data['teacher_id'])
            ->where('subject_id', $data['subject_id'])
            ->delete();

        return response()->noContent();
    }
}
