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

        // One row per (group, teacher, subject) — Option A (decision B1-sub): a
        // teacher can teach several subjects in the same group. syncWithoutDetaching
        // keys pivots by teacher_id only, so it would UPDATE the teacher's existing
        // row instead of adding a second subject. insertOrIgnore keyed by the full
        // triple is idempotent (the unique index absorbs a repeat attach) AND
        // preserves the teacher's other subject rows and their created_at.
        DB::table('group_teacher')->insertOrIgnore([
            'group_id' => $group->id,
            'teacher_id' => $data['teacher_id'],
            'subject_id' => $data['subject_id'],
            'created_at' => now(),
            'updated_at' => now(),
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
