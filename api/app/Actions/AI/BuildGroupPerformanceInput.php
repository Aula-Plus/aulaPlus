<?php

namespace App\Actions\AI;

use App\Enums\ClassSessionStatus;
use App\Models\Assessment;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\User;
use App\Services\StudentGradeAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * The group's performance and class data the AI may draw on for a class or
 * assessment proposal (PA-116, P14-DEC-100, decision of 17/11/2026 — it
 * replaces the PA-036 rule that only a unit's own assessments could be used).
 *
 * The model gets the group's assessments whether or not they are tied to a
 * unit (and from any unit), plus how the group's recent classes of the
 * subject were closed, and picks what is pertinent; its «¿por qué?» then says
 * which data it took, where it comes from and why.
 *
 * Aggregated only (CLAUDE.md rule 11): one average per assessment, never a
 * student, a name or a per-student grade. The same visibility as the group's
 * performance line on screen (StudentGradeAccess::forGroup): a teacher
 * restricted to their own subjects never gets another subject's average
 * through the assistant. An assessment with fewer than MIN_RESULTS visible
 * results is left out — with two results an average is nearly an individual
 * grade, and this leaves the school.
 */
class BuildGroupPerformanceInput
{
    /** Below this many results an average is too close to one student's grade. */
    public const MIN_RESULTS = 3;

    public const MAX_ASSESSMENTS = 12;

    public const MAX_CLASSES = 10;

    public function __construct(protected StudentGradeAccess $gradeAccess) {}

    /**
     * @return array{assessments: list<array<string, mixed>>, recent_classes: list<array<string, mixed>>}
     */
    public function __invoke(Group $group, ?int $subjectId, ?User $requester): array
    {
        return [
            'assessments' => $requester === null ? [] : $this->assessments($group, $requester),
            'recent_classes' => $subjectId === null ? [] : $this->recentClasses($group, $subjectId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function assessments(Group $group, User $requester): array
    {
        $rule = $this->gradeAccess->forGroup($requester, $group);
        $visible = fn (Builder $query) => $rule->apply($query)->whereNotNull('assessment_results.score');

        return Assessment::query()
            ->where('group_id', $group->id)
            ->with(['subject:id,name', 'unitObjectives.unit:id,name'])
            ->withCount(['results' => $visible])
            ->withAvg(['results' => $visible], 'score')
            ->orderByDesc('administered_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ASSESSMENTS * 3)
            ->get()
            ->filter(fn (Assessment $assessment): bool => $assessment->results_count >= self::MIN_RESULTS)
            ->take(self::MAX_ASSESSMENTS)
            ->map(fn (Assessment $assessment): array => [
                'subject' => $assessment->subject?->name,
                'type' => $assessment->type->value,
                'date' => $assessment->administered_at?->toDateString(),
                'title' => $assessment->purpose,
                // Where it comes from: the unit(s) whose objectives it worked,
                // or none — PA-116 sends it either way.
                'units' => $assessment->unitObjectives->pluck('unit.name')->filter()->unique()->values()->all(),
                'average_score' => round((float) $assessment->results_avg_score, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * How the group's recent classes of the subject went: what the teacher
     * recorded when closing them. Titles, dates and the closing only.
     *
     * @return list<array<string, mixed>>
     */
    protected function recentClasses(Group $group, int $subjectId): array
    {
        return ClassSession::query()
            ->where('group_id', $group->id)
            ->where('subject_id', $subjectId)
            ->where('status', '!=', ClassSessionStatus::Cancelled->value)
            ->whereNotNull('closing_result')
            ->with('unit:id,name')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::MAX_CLASSES)
            ->get()
            ->map(fn (ClassSession $class): array => [
                'title' => $class->title,
                'date' => $class->date?->toDateString(),
                'unit' => $class->unit?->name,
                'closing' => $class->closing_result?->value,
            ])
            ->values()
            ->all();
    }
}
