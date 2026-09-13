<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\CommentTone;
use App\Models\Accommodation;
use App\Models\AccommodationInstanceOverride;
use App\Models\AssessmentResult;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the student performance-timeline payload (docs/prompts/20-linea-
 * tiempo-alumno.md §1): the `results` line (from Session 6) plus a single
 * `marks` array aggregated from six sources built across Sessions 3, 6, 8, 9
 * and the pre-existing Barrier/CalendarEvent models.
 *
 * Two orthogonal authorization axes decide which marks a given user sees —
 * both re-evaluated here for the requesting user, never trusted from the
 * client (CLAUDE.md security rule 3, "Non-negotiable" in §Architecture):
 *
 *  - The clinical-profile gate (`view-clinical-profile`, i.e.
 *    StudentPolicy::viewClinicalProfile): the four clinical mark types
 *    (`accommodation_activated`, `accommodation_deactivated`,
 *    `accommodation_instance_override`, `barrier_registered`) are omitted
 *    entirely for a user who fails it — not returned as an empty list, simply
 *    absent, exactly like the rest of the clinical domain.
 *  - Comment visibility (`visible_to`/`author_only`, Session 9): a
 *    `concerning_comment` mark appears only if the comment is visible to the
 *    user. This is independent of the clinical gate — a teacher who can't see
 *    the clinical profile still sees concerning comments addressed to them.
 *
 * `calendar_event` marks are school-level (never student-linked in the domain
 * model) and are always included — no gate.
 *
 * `marks` is always present as one flat array; what varies by authorization is
 * *which mark types it contains*, never whether the key exists.
 */
class PerformanceTimelineBuilder
{
    /**
     * @return array{results: Collection<int, AssessmentResult>, marks: array<int, array<string, mixed>>}
     */
    public function build(Student $student, User $user, ?string $from, ?string $to): array
    {
        return [
            'results' => $this->results($student, $from, $to),
            'marks' => $this->marks($student, $user, $from, $to),
        ];
    }

    /**
     * The performance line itself: reuses the Session 6 rule
     * (GET /students/{student}/results) — ordered by the parent assessment's
     * `administered_at`, filtered to the window.
     *
     * @return Collection<int, AssessmentResult>
     */
    protected function results(Student $student, ?string $from, ?string $to): Collection
    {
        return AssessmentResult::query()
            ->where('assessment_results.student_id', $student->id)
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->when($from, fn (Builder $q) => $q->whereDate('assessments.administered_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('assessments.administered_at', '<=', $to))
            ->orderBy('assessments.administered_at')
            ->orderBy('assessment_results.id')
            ->select('assessment_results.*')
            ->with('assessment')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function marks(Student $student, User $user, ?string $from, ?string $to): array
    {
        /** @var Collection<int, array{date: Carbon, mark: array<string, mixed>}> $entries */
        $entries = collect();

        if (Gate::forUser($user)->allows('view-clinical-profile', $student)) {
            $this->appendClinicalMarks($entries, $student, $from, $to);
        }

        $this->appendConcerningComments($entries, $student, $user, $from, $to);
        $this->appendCalendarEvents($entries, $from, $to);

        // Single flat array, chronological. All mark dates are UTC ISO-8601, so
        // sorting by the underlying Carbon keeps sources interleaved by time.
        return $entries
            ->sortBy(fn (array $entry) => $entry['date'])
            ->values()
            ->map(fn (array $entry) => [
                ...$entry['mark'],
                'date' => $entry['date']->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The four mark types gated behind `view-clinical-profile`.
     *
     * @param  Collection<int, array{date: Carbon, mark: array<string, mixed>}>  $entries
     */
    protected function appendClinicalMarks(Collection $entries, Student $student, ?string $from, ?string $to): void
    {
        // withTrashed(): a deactivation/override event inside the window must
        // still surface even if the accommodation was soft-deleted afterwards
        // — same treatment as the audit-backed StudentHistoryController.
        $accommodations = $student->accommodations()->withTrashed()->get();
        $accommodationIds = $accommodations->pluck('id');

        // accommodation_activated — the accommodation's own created_at.
        foreach ($accommodations as $accommodation) {
            if ($this->inRange($accommodation->created_at, $from, $to)) {
                $entries->push([
                    'date' => $accommodation->created_at,
                    'mark' => [
                        'type' => 'accommodation_activated',
                        'accommodation_id' => $accommodation->id,
                    ],
                ]);
            }
        }

        // accommodation_deactivated — no `deactivated_at` column exists; it is
        // derived from the audit trail (Session 3): an `updated` log on the
        // Accommodation whose diff flips `active` from true to false. The mark
        // date is that log's created_at.
        $deactivationLogs = AuditLog::query()
            ->where('auditable_type', Accommodation::class)
            ->whereIn('auditable_id', $accommodationIds)
            ->where('action', AuditAction::Updated)
            ->get();

        foreach ($deactivationLogs as $log) {
            $active = $log->changes['active'] ?? null;

            // before truthy, after falsy — booleans round-trip through the JSON
            // cast, but stay tolerant of 1/0 storage.
            if ($active !== null && ($active['before'] ?? null) && ! ($active['after'] ?? null)
                && $this->inRange($log->created_at, $from, $to)) {
                $entries->push([
                    'date' => $log->created_at,
                    'mark' => [
                        'type' => 'accommodation_deactivated',
                        'accommodation_id' => $log->auditable_id,
                    ],
                ]);
            }
        }

        // accommodation_instance_override — Session 8. `reason` is clinical
        // content, only ever reached inside this gated branch.
        $overrides = AccommodationInstanceOverride::query()
            ->whereIn('accommodation_id', $accommodationIds)
            ->get();

        foreach ($overrides as $override) {
            if ($this->inRange($override->created_at, $from, $to)) {
                $entries->push([
                    'date' => $override->created_at,
                    'mark' => [
                        'type' => 'accommodation_instance_override',
                        'accommodation_id' => $override->accommodation_id,
                        'assessment_id' => $override->assessment_id,
                        'reason' => $override->reason,
                    ],
                ]);
            }
        }

        // barrier_registered — the barrier's created_at.
        foreach ($student->barriers()->withTrashed()->get() as $barrier) {
            if ($this->inRange($barrier->created_at, $from, $to)) {
                $entries->push([
                    'date' => $barrier->created_at,
                    'mark' => [
                        'type' => 'barrier_registered',
                        'barrier_id' => $barrier->id,
                    ],
                ]);
            }
        }
    }

    /**
     * concerning_comment — the student's `concerning`-toned comments, filtered
     * by the same visibility rule as every other read of Comment (Session 9),
     * which is orthogonal to the clinical gate.
     *
     * @param  Collection<int, array{date: Carbon, mark: array<string, mixed>}>  $entries
     */
    protected function appendConcerningComments(Collection $entries, Student $student, User $user, ?string $from, ?string $to): void
    {
        $comments = $student->comments()
            ->where('tone', CommentTone::Concerning->value)
            ->get();

        foreach ($comments as $comment) {
            if ($comment->isVisibleTo($user) && $this->inRange($comment->created_at, $from, $to)) {
                $entries->push([
                    'date' => $comment->created_at,
                    'mark' => [
                        'type' => 'concerning_comment',
                        'comment_id' => $comment->id,
                    ],
                ]);
            }
        }
    }

    /**
     * calendar_event — every school-level event in the window. The model has
     * no student/group link (it is school-wide by design), so no such link is
     * invented; the event's `start_at` is used as the mark date.
     *
     * @param  Collection<int, array{date: Carbon, mark: array<string, mixed>}>  $entries
     */
    protected function appendCalendarEvents(Collection $entries, ?string $from, ?string $to): void
    {
        $events = CalendarEvent::query()
            ->when($from, fn (Builder $q) => $q->whereDate('start_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('start_at', '<=', $to))
            ->get();

        foreach ($events as $event) {
            $entries->push([
                'date' => $event->start_at,
                'mark' => [
                    'type' => 'calendar_event',
                    'calendar_event_id' => $event->id,
                    'title' => $event->title,
                ],
            ]);
        }
    }

    /**
     * Inclusive date-window test used for the sources filtered in PHP
     * (small, relation-loaded collections). `to` covers the whole day, so the
     * behaviour matches the query-level `whereDate` used for the larger
     * sources.
     */
    protected function inRange(?Carbon $date, ?string $from, ?string $to): bool
    {
        if ($date === null) {
            return false;
        }

        if ($from !== null && $date->lt(Carbon::parse($from)->startOfDay())) {
            return false;
        }

        if ($to !== null && $date->gt(Carbon::parse($to)->endOfDay())) {
            return false;
        }

        return true;
    }
}
