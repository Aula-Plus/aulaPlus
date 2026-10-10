<?php

namespace App\Services;

use App\Enums\GradesVisibility;
use App\Support\SchoolTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The grades one viewer may see in one context (a student, or a group's
 * aggregate), resolved once by {@see StudentGradeAccess} and then applied to as
 * many queries as the request needs — so the school setting, the teacher's
 * subjects and the last cutoff are looked up once, not per query.
 */
final class GradeAccessRule
{
    /**
     * @param  list<int>  $ownSubjectIds  subjects always visible live (the ones the teacher teaches)
     * @param  CarbonImmutable|null  $cutoff  periodic mode: the last cutoff (a local calendar day)
     */
    public function __construct(
        public readonly GradesVisibility $mode,
        public readonly bool $restricted,
        public readonly array $ownSubjectIds = [],
        public readonly ?CarbonImmutable $cutoff = null,
    ) {}

    public static function unrestricted(): self
    {
        return new self(GradesVisibility::AllLive, false);
    }

    /**
     * Constrain a query over `assessment_results` that can reference
     * `assessments` (joined, or as the outer table of a withCount/withAvg
     * subquery) to what this rule allows.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder
    {
        if (! $this->restricted) {
            return $query;
        }

        return $query->where(function (Builder $q) {
            $q->whereIn('assessments.subject_id', $this->ownSubjectIds);

            // Other subjects: only results loaded before the cutoff day started
            // (school time). Later loads wait for the next cutoff; a later
            // correction of an already-visible result is not frozen (results
            // are upserted in place, there is no snapshot).
            if ($this->cutoff !== null) {
                $q->orWhere('assessment_results.created_at', '<', SchoolTime::startOfDay($this->cutoff));
            }
        });
    }

    /**
     * What the UI needs to explain the restriction (never any grade data).
     *
     * @return array{mode: string, restricted: bool, own_subject_ids: list<int>, cutoff_at: string|null}
     */
    public function describe(): array
    {
        return [
            'mode' => $this->mode->value,
            'restricted' => $this->restricted,
            'own_subject_ids' => $this->ownSubjectIds,
            'cutoff_at' => $this->cutoff?->toDateString(),
        ];
    }
}
