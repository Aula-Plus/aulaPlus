<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\CommentTone;
use App\Http\Requests\GroupPerformanceTimelineRequest;
use App\Models\Accommodation;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Barrier;
use App\Models\CalendarEvent;
use App\Models\Comment;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only aggregators behind the "Perfil de grupo" screen
 * (docs/prompts/21-perfil-de-grupo.md). Both endpoints are strictly
 * aggregate: the group profile shows the class as a whole and NEVER a student
 * roster tied to sensitive data — "agregado por tipo, nunca nómina" (the red
 * line of pantalla 3). No response here carries a student identifier.
 */
class GroupProfileController extends Controller
{
    /**
     * GET /api/v1/groups/{group}/accommodations-summary — active accommodations
     * of the group's students, aggregated by `type` (§1).
     *
     * No extra clinical gating: it never names a student, so it exposes nothing
     * a teacher who can already see the group shouldn't see. Grouped by the
     * free-text `type` (not `category`: two accommodations may share a category
     * yet be different concrete adjustments); `student_count` counts DISTINCT
     * students, not rows, and only effective accommodations are counted.
     */
    public function accommodationsSummary(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        $studentIds = $group->students()->pluck('students.id');

        $summary = Accommodation::query()
            ->whereIn('student_id', $studentIds)
            ->get()
            ->filter->isEffective()
            ->groupBy('type')
            ->map(fn (Collection $group, string $type): array => [
                'type' => $type,
                // A given `type` is expected to carry one category; if the data
                // ever disagrees we take the first deterministically rather than
                // silently dropping the row.
                'category' => $group->first()->category?->value,
                'student_count' => $group->pluck('student_id')->unique()->count(),
            ])
            // Deterministic order (the domain doc gives none): alphabetical by
            // type, so the payload is stable across requests.
            ->sortBy('type')
            ->values()
            ->all();

        return response()->json($summary);
    }

    /**
     * GET /api/v1/groups/{group}/performance-timeline?from=&to= — the group's
     * performance line plus aggregated marks (§2). Same authorization as
     * GET /groups/{group}/tracking (enforced by the FormRequest).
     */
    public function performanceTimeline(GroupPerformanceTimelineRequest $request, Group $group): JsonResponse
    {
        $from = $request->date('from');
        $to = $request->date('to');
        $user = $request->user();

        $students = $group->students()->get();
        $studentIds = $students->pluck('id');

        return response()->json([
            'results' => $this->timelineResults($group, $from, $to),
            'marks' => $this->timelineMarks($group, $students, $studentIds, $user, $from, $to),
        ]);
    }

    /**
     * One point per Assessment of the group (not per time window): its
     * `average_score` is the mean of its AssessmentResults and `results_count`
     * how many students have a score loaded. An assessment with no results
     * loaded yet produces no point.
     *
     * @return list<array<string, mixed>>
     */
    protected function timelineResults(Group $group, $from, $to): array
    {
        return Assessment::query()
            ->where('group_id', $group->id)
            ->when($from, fn ($query) => $query->whereDate('administered_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('administered_at', '<=', $to))
            ->withCount('results')
            ->withAvg('results', 'score')
            ->orderBy('administered_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Assessment $assessment): bool => $assessment->results_count > 0)
            ->map(fn (Assessment $assessment): array => [
                'assessment_id' => $assessment->id,
                'assessment_type' => $assessment->type->value,
                'administered_at' => $assessment->administered_at?->toDateString(),
                'average_score' => round((float) $assessment->results_avg_score, 2),
                'results_count' => $assessment->results_count,
            ])
            ->values()
            ->all();
    }

    /**
     * The `marks` array: always present, but which mark TYPES it carries varies
     * by authorization (§2), exactly like the student timeline (Session 10).
     * Every mark is an aggregate count by (type, date) — never a point per
     * student, never a student identifier.
     *
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, int>  $studentIds
     * @return list<array<string, mixed>>
     */
    protected function timelineMarks(Group $group, Collection $students, Collection $studentIds, $user, $from, $to): array
    {
        $marks = [];

        // Clinical marks (accommodation activate/deactivate, barrier) are
        // included as soon as the user passes view-clinical-profile for AT
        // LEAST ONE student of the group — the count is already aggregated, so
        // it exposes nothing that per-student filtering would protect.
        $canSeeClinical = $students->contains(
            fn (Student $student): bool => Gate::forUser($user)->allows('view-clinical-profile', $student)
        );

        if ($canSeeClinical) {
            $marks = array_merge(
                $marks,
                $this->accommodationActivatedMarks($studentIds, $from, $to),
                $this->accommodationDeactivatedMarks($studentIds, $from, $to),
                $this->barrierMarks($studentIds, $from, $to),
            );
        }

        // Concerning comments do NOT depend on the clinical gate — only on each
        // comment's own visibility (visible_to / author_only, Session 9). A
        // comment the user can't see contributes to no count.
        $marks = array_merge($marks, $this->concerningCommentMarks($studentIds, $user, $from, $to));

        // Calendar events are school-level (never per student), so they are
        // always present — GroupPolicy::view over the group already covers them.
        $marks = array_merge($marks, $this->calendarEventMarks($from, $to));

        return $marks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function accommodationActivatedMarks(Collection $studentIds, $from, $to): array
    {
        $accommodations = Accommodation::query()
            ->whereIn('student_id', $studentIds)
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->get();

        $marks = [];

        foreach ($accommodations->groupBy(fn (Accommodation $a): string => $a->created_at->toDateString()) as $date => $onDate) {
            foreach ($onDate->groupBy('type') as $type => $items) {
                $marks[] = [
                    'type' => 'accommodation_activated',
                    'date' => $date,
                    'accommodation_type' => $type,
                    'count' => $items->pluck('student_id')->unique()->count(),
                ];
            }
        }

        return $marks;
    }

    /**
     * Deactivation has no `deactivated_at` column — it is derived from the audit
     * trail (Session 3): an `updated` AuditLog on an Accommodation whose diff
     * flips `active` from true to false. Accommodations are resolved withTrashed
     * so a since-deleted one still yields its `type`.
     *
     * @return list<array<string, mixed>>
     */
    protected function accommodationDeactivatedMarks(Collection $studentIds, $from, $to): array
    {
        $accommodationsById = Accommodation::withTrashed()
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('id');

        if ($accommodationsById->isEmpty()) {
            return [];
        }

        $logs = AuditLog::query()
            ->where('auditable_type', Accommodation::class)
            ->whereIn('auditable_id', $accommodationsById->keys())
            ->where('action', AuditAction::Updated)
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->get()
            ->filter(function (AuditLog $log): bool {
                $active = $log->changes['active'] ?? null;

                return is_array($active)
                    && ($active['before'] ?? null) === true
                    && ($active['after'] ?? null) === false;
            });

        $marks = [];

        foreach ($logs->groupBy(fn (AuditLog $log): string => $log->created_at->toDateString()) as $date => $onDate) {
            $byType = $onDate->groupBy(fn (AuditLog $log) => $accommodationsById[$log->auditable_id]->type);

            foreach ($byType as $type => $items) {
                $marks[] = [
                    'type' => 'accommodation_deactivated',
                    'date' => $date,
                    'accommodation_type' => $type,
                    'count' => $items
                        ->map(fn (AuditLog $log) => $accommodationsById[$log->auditable_id]->student_id)
                        ->unique()
                        ->count(),
                ];
            }
        }

        return $marks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function barrierMarks(Collection $studentIds, $from, $to): array
    {
        $barriers = Barrier::query()
            ->whereIn('student_id', $studentIds)
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->get();

        $marks = [];

        foreach ($barriers->groupBy(fn (Barrier $b): string => $b->created_at->toDateString()) as $date => $onDate) {
            $marks[] = [
                'type' => 'barrier_registered',
                'date' => $date,
                'count' => $onDate->pluck('student_id')->unique()->count(),
            ];
        }

        return $marks;
    }

    /**
     * Concerning comments on the group's students, honoring each comment's
     * visibility for the requesting user (Session 9). Aggregated by date; the
     * count is of visible comments, mirroring the student timeline's one mark
     * per comment.
     *
     * @return list<array<string, mixed>>
     */
    protected function concerningCommentMarks(Collection $studentIds, $user, $from, $to): array
    {
        $comments = Comment::query()
            ->where('commentable_type', Student::class)
            ->whereIn('commentable_id', $studentIds)
            ->where('tone', CommentTone::Concerning)
            ->visibleToRole($user)
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->get();

        $marks = [];

        foreach ($comments->groupBy(fn (Comment $c): string => $c->created_at->toDateString()) as $date => $onDate) {
            $marks[] = [
                'type' => 'concerning_comment',
                'date' => $date,
                'count' => $onDate->count(),
            ];
        }

        return $marks;
    }

    /**
     * School-level calendar events in the window (not linked to student/group
     * in the model — do not invent a link). One mark each, no aggregation.
     *
     * @return list<array<string, mixed>>
     */
    protected function calendarEventMarks($from, $to): array
    {
        return CalendarEvent::query()
            ->when($from, fn ($query) => $query->whereDate('start_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('start_at', '<=', $to))
            ->orderBy('start_at')
            ->get()
            ->map(fn (CalendarEvent $event): array => [
                'type' => 'calendar_event',
                'date' => $event->start_at->toDateString(),
                'calendar_event_id' => $event->id,
                'title' => $event->title,
            ])
            ->all();
    }
}
