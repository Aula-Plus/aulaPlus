<?php

namespace App\Services\Materials;

use App\Enums\Role;
use App\Models\Group;
use App\Models\SchoolGrade;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which subject × grade folders a person works with in «Materiales» (screen
 * 19, ClickUp 1dfd3mc4bgx), and where they may upload.
 *
 * Built from the teaching assignments (group_teacher): a teacher's own
 * group × subject pairs, or every pair of the school for Psicopedagogía and
 * Dirección. A group without a grade falls in the «Sin grado» folder
 * (`grade_id` null).
 */
class MaterialScope
{
    /**
     * @return Collection<int, object{group_id: int, subject_id: int}>
     */
    public function pairs(User $user): Collection
    {
        return DB::table('group_teacher')
            ->join('groups', 'groups.id', '=', 'group_teacher.group_id')
            ->join('subjects', 'subjects.id', '=', 'group_teacher.subject_id')
            ->where('groups.school_id', $user->school_id)
            ->whereNull('subjects.deleted_at')
            ->when(
                ! $user->hasAnyRole(Role::schoolWideValues()),
                fn ($query) => $query->where('group_teacher.teacher_id', $user->id),
            )
            ->select('group_teacher.group_id', 'group_teacher.subject_id')
            ->distinct()
            ->get();
    }

    /** Whether $user may file a material under this group and subject. */
    public function canUploadTo(User $user, int $groupId, int $subjectId): bool
    {
        if (! $user->hasAnyRole([Role::Teacher->value, Role::Psychopedagogue->value])) {
            return false;
        }

        return $this->pairs($user)->contains(
            fn (object $pair): bool => (int) $pair->group_id === $groupId && (int) $pair->subject_id === $subjectId,
        );
    }

    /**
     * The prefilter (P19-DEC-02) and the upload form's choices.
     *
     * @return array{subjects: list<array<string, mixed>>, grades: list<array<string, mixed>>, groups: list<array<string, mixed>>}
     */
    public function options(User $user): array
    {
        $pairs = $this->pairs($user);
        $groups = Group::query()
            ->whereIn('id', $pairs->pluck('group_id')->unique())
            ->get(['id', 'name', 'school_grade_id'])
            ->keyBy('id');
        $subjects = Subject::query()
            ->whereIn('id', $pairs->pluck('subject_id')->unique())
            ->orderBy('name')
            ->get(['id', 'name']);
        $grades = SchoolGrade::query()
            ->whereIn('id', $groups->pluck('school_grade_id')->filter()->unique())
            ->orderBy('position')
            ->get(['id', 'label']);

        $gradeRows = $grades->map(fn (SchoolGrade $grade) => ['id' => $grade->id, 'label' => $grade->label])->values();
        if ($groups->contains(fn (Group $group) => $group->school_grade_id === null)) {
            $gradeRows->push(['id' => null, 'label' => 'Sin grado']);
        }

        $canUpload = $user->hasAnyRole([Role::Teacher->value, Role::Psychopedagogue->value]);

        return [
            'subjects' => $subjects->map(fn (Subject $subject) => ['id' => $subject->id, 'name' => $subject->name])->values()->all(),
            'grades' => $gradeRows->all(),
            // Where the caller may upload: each group with the subjects they
            // may file under it. Dirección uploads nothing.
            'groups' => $canUpload
                ? $groups->sortBy('name')->map(fn (Group $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'grade_id' => $group->school_grade_id,
                    'subject_ids' => $pairs->where('group_id', $group->id)->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
                ])->values()->all()
                : [],
        ];
    }
}
