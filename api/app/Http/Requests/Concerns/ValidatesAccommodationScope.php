<?php

namespace App\Http\Requests\Concerns;

use App\Enums\AssessmentType;
use App\Models\Accommodation;
use App\Support\Tenancy;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation shared by the accommodation create/edit requests for where and
 * since when an accommodation applies (ClickUp 86e3dpbwy follow-up):
 *
 * - `starts_on`: the date it is in force from (Y-m-d). Required on create.
 * - `subject_ids`: the subjects it is restricted to — each must be a live
 *   subject of the current school (never another tenant's, never a deleted
 *   one). On edit, a subject already attached to the accommodation is still
 *   accepted after it was soft-deleted: the form resends the current scope,
 *   and keeping it must not 422 (dropping it is always allowed). An empty
 *   list means a general accommodation (every subject).
 * - `assessment_types`: the assessment types it is restricted to. Empty or
 *   null means every type.
 */
trait ValidatesAccommodationScope
{
    /**
     * @param  Accommodation|null  $editing  the accommodation being edited (null on create)
     * @return array<string, array<int, mixed>>
     */
    protected function accommodationScopeRules(?Accommodation $editing = null): array
    {
        $creating = $editing === null;
        // The relation includes trashed subjects (Accommodation::subjects).
        $attachedSubjectIds = $editing?->subjects()->pluck('subjects.id')->all() ?? [];

        return [
            'starts_on' => $creating
                ? ['required', 'date_format:Y-m-d']
                : ['sometimes', 'required', 'date_format:Y-m-d'],
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
            'assessment_types' => ['sometimes', 'nullable', 'array'],
            'assessment_types.*' => ['distinct', new Enum(AssessmentType::class)],
        ];
    }
}
