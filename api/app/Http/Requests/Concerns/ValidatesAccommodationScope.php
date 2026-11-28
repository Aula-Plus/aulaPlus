<?php

namespace App\Http\Requests\Concerns;

use App\Enums\AssessmentType;
use App\Models\Accommodation;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation shared by the accommodation create/edit requests for where and
 * since when an accommodation applies (ClickUp 86e3dpbwy follow-up):
 *
 * - `starts_on`: the date it is in force from («Vigente desde», Y-m-d).
 *   Optional on create: when absent the model fills it with today (school
 *   time). Editable. There is deliberately no end-date field: the day an
 *   accommodation is switched off is recorded by the server
 *   (Accommodation::booted, `deactivated_on`), never sent by a client.
 * - `subject_ids`: the subjects it is restricted to — each must be a live
 *   subject of the current school (never another tenant's, never a deleted
 *   one). On edit, a subject already attached to the accommodation is still
 *   accepted after it was soft-deleted: the form resends the current scope,
 *   and keeping it must not 422 (dropping it is always allowed). An empty
 *   list means a general accommodation (every subject).
 * - `assessment_types`: the assessment types it is restricted to, as a list.
 *   Empty or null means every type.
 */
trait ValidatesAccommodationScope
{
    /**
     * @param  Accommodation|null  $editing  the accommodation being edited (null on create)
     * @return array<string, array<int, mixed>>
     */
    protected function accommodationScopeRules(?Accommodation $editing = null): array
    {
        // The relation includes trashed subjects (Accommodation::subjects).
        $attachedSubjectIds = $editing?->subjects()->pluck('subjects.id')->all() ?? [];

        return [
            'starts_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'subject_ids' => ['sometimes', 'array'],
            'subject_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('subjects', 'id')
                    ->where('school_id', Tenancy::schoolId())
                    ->where(fn (Builder $query) => $query->where(fn (Builder $live) => $live
                        ->whereNull('deleted_at')
                        ->orWhereIn('id', $attachedSubjectIds))),
            ],
            // `list`, not just `array`: a keyed object would be stored as a
            // JSON object, which the JSON-array queries (appliesToAssessmentType
            // → jsonb_array_length on PostgreSQL) and the SPA can't handle.
            'assessment_types' => ['sometimes', 'nullable', 'array', 'list'],
            'assessment_types.*' => ['distinct', new Enum(AssessmentType::class)],
        ];
    }

    /**
     * A teacher's proposal (ClickUp 1dfd3mc55zu, answer 2B of 2026-11-26) is
     * restricted to the subjects that teacher gives the student
     * (User::subjectIdsTaughtTo), or none = «Todas las materias».
     * Psychopedagogy may change them when it confirms. On edit, a subject
     * already attached stays accepted so resending the form never 422s.
     *
     * @param  list<int>  $attachedSubjectIds
     */
    protected function taughtSubjectRule(User $teacher, Student $student, array $attachedSubjectIds = []): Closure
    {
        $allowed = [...$teacher->subjectIdsTaughtTo($student), ...$attachedSubjectIds];

        return function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
            if (! in_array((int) $value, $allowed, true)) {
                $fail('The selected subject is not one you give this student.');
            }
        };
    }
}
