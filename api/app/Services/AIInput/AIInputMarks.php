<?php

namespace App\Services\AIInput;

use App\Actions\AI\BuildAccommodationInput;
use App\Actions\AI\BuildProposalContext;
use App\Contracts\StatusChangeNotifier;
use App\Enums\AccommodationCategory;
use App\Enums\AIInputMarkResolution;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\AIInputMark;
use App\Models\Alert;
use App\Models\Group;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\AIInputMarkNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The review view of «Lo que recibe la IA» and its marks (ClickUp
 * 1dfd3mc2wx2; documento vivo screen 03, P03-AJU-03 / P03-ACT-53).
 *
 * - The view shows, for a group and optionally a subject, exactly the lines
 *   the AI would receive ({@see BuildAccommodationInput}), with the
 *   accommodations each one comes from. The derived text is never edited by
 *   hand.
 * - «La IA lo entendió mal» marks a line: while the mark is pending the line
 *   is withheld from the AI. It closes when the origin accommodation changes
 *   (the marked line no longer exists — {@see self::reconcile()}) or with
 *   «El ajuste está bien», which sends the line again without touching the
 *   accommodation and leaves no weekly alert.
 * - Both a new mark and «El ajuste está bien» are e-mailed to the Aula+ team
 *   (the «Ayuda y sugerencias» recipients, SUPPORT_NOTIFY_EMAILS).
 * - A mark still pending a week later raises one alert for whoever marked it
 *   ({@see self::raiseWeeklyAlerts()}), worked with the same ways out as any
 *   alert; it closes on its own when the mark closes.
 */
class AIInputMarks
{
    /** Days a mark may stay pending before its alert is raised. */
    public const ALERT_AFTER_DAYS = 7;

    public function __construct(
        protected BuildAccommodationInput $input,
        protected StatusChangeNotifier $notifier,
    ) {}

    /**
     * The lines the AI would receive for the group — the same scope as a
     * request from the viewer ({@see BuildProposalContext::accommodationsInScope()}):
     * in force today, cut to the subject when one is chosen and to what the
     * viewer sees (a no-op for psychopedagogy and direction). Lines withheld
     * by a pending mark are listed too, flagged as not sent, so they can be
     * reviewed.
     *
     * @return list<array<string, mixed>>
     */
    public function review(Group $group, ?Subject $subject, User $viewer): array
    {
        $this->reconcile($group);

        $pending = AIInputMark::query()
            ->where('group_id', $group->id)
            ->pending()
            ->with('markedBy:id,name', 'subject:id,name')
            ->get()
            ->keyBy('line_key');

        return $this->input->lines($group, $this->scope($subject?->id, $viewer))
            ->map(function (array $entry) use ($pending): array {
                $mark = $pending->get($entry['key']);

                return [
                    'key' => $entry['key'],
                    ...$entry['line'],
                    'sent' => $mark === null,
                    'mark' => $mark === null ? null : [
                        'id' => $mark->id,
                        'comment' => $mark->comment,
                        'subject_name' => $mark->subject?->name,
                        'marked_by' => $mark->markedBy
                            ? ['id' => $mark->markedBy->id, 'name' => $mark->markedBy->name]
                            : null,
                        'created_at' => $mark->created_at?->toIso8601String(),
                    ],
                    // «Ver ajuste de origen»: read by psychopedagogy and
                    // direction, who see the per-student detail (P03-AJU-02).
                    'origins' => $entry['origins']
                        ->map(fn (Accommodation $accommodation): array => [
                            'accommodation_id' => $accommodation->id,
                            'student_id' => $accommodation->student_id,
                            'student_name' => $accommodation->student instanceof Student
                                ? $accommodation->student->full_name
                                : null,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * «La IA lo entendió mal» on the line `$lineKey` of the group.
     *
     * @throws ValidationException when the line is not in the input (any
     *                             more) or already has a pending mark
     */
    public function mark(Group $group, string $lineKey, ?Subject $subject, ?string $comment, User $actor): AIInputMark
    {
        $mark = DB::transaction(function () use ($group, $lineKey, $subject, $comment, $actor): AIInputMark {
            // Serialize marks on the same group: two tabs must not both mark
            // the same line.
            Group::query()->whereKey($group->id)->lockForUpdate()->first();

            $entry = $this->input->lines($group, $this->fullScope())
                ->firstWhere('key', $lineKey);

            if ($entry === null) {
                throw ValidationException::withMessages([
                    'line_key' => 'Esa línea ya no está en lo que recibe la IA.',
                ]);
            }

            $alreadyPending = AIInputMark::query()
                ->where('group_id', $group->id)
                ->where('line_key', $lineKey)
                ->pending()
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'line_key' => 'Esa línea ya está marcada.',
                ]);
            }

            return AIInputMark::create([
                'group_id' => $group->id,
                'subject_id' => $subject?->id,
                'line_key' => $lineKey,
                'line' => $entry['line'],
                'category' => AccommodationCategory::tryFrom((string) $entry['line']['category']),
                'comment' => $comment,
                'marked_by_id' => $actor->id,
            ]);
        });

        $this->notifyTeam($mark, AIInputMarkNotification::KIND_MARKED);

        return $mark;
    }

    /**
     * «El ajuste está bien»: the line goes back to the AI as it was, the mark
     * closes (and with it its alert, if it was raised) and the accommodation
     * is not touched. The team is told anyway.
     *
     * @throws ValidationException when the mark is no longer pending
     */
    public function confirmAdjustment(AIInputMark $mark, ?string $comment, User $actor): AIInputMark
    {
        $mark = DB::transaction(function () use ($mark, $comment, $actor): AIInputMark {
            $mark = AIInputMark::query()->whereKey($mark->id)->lockForUpdate()->firstOrFail();

            if (! $mark->isPending()) {
                throw ValidationException::withMessages([
                    'mark' => 'Esa marca ya está cerrada.',
                ]);
            }

            $this->close($mark, AIInputMarkResolution::AdjustmentOk, $actor, $comment);

            return $mark;
        });

        $this->notifyTeam($mark, AIInputMarkNotification::KIND_ADJUSTMENT_OK);

        return $mark;
    }

    /**
     * Closes the group's pending marks whose line the AI would no longer
     * receive: the origin accommodation changed — its action, category,
     * subjects or assessment types — or stopped being in force (ended,
     * withdrawn, deleted). Returns how many closed.
     */
    public function reconcile(Group $group): int
    {
        $pending = AIInputMark::query()->where('group_id', $group->id)->pending()->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $current = $this->input->lines($group, $this->fullScope())->pluck('key')->flip();
        $closed = 0;

        foreach ($pending as $mark) {
            if ($current->has($mark->line_key)) {
                continue;
            }

            DB::transaction(function () use ($mark, &$closed): void {
                $locked = AIInputMark::query()->whereKey($mark->id)->pending()->lockForUpdate()->first();

                if ($locked !== null) {
                    $this->close($locked, AIInputMarkResolution::AdjustmentChanged, null, null);
                    $closed++;
                }
            });
        }

        return $closed;
    }

    /**
     * One alert per mark still pending {@see self::ALERT_AFTER_DAYS} days
     * after it was made, for whoever marked it (P03-AJU-03: "a la semana sin
     * resolver, alerta"). It reaches that person only — no role snapshot —
     * and carries no student. The caller must have set the tenant.
     */
    public function raiseWeeklyAlerts(?CarbonInterface $now = null): int
    {
        $cutoff = CarbonImmutable::parse($now ?? now())->subDays(self::ALERT_AFTER_DAYS);

        $due = fn () => AIInputMark::query()
            ->pending()
            ->where('created_at', '<=', $cutoff)
            ->whereNotNull('marked_by_id')
            ->whereDoesntHave('alert');

        // A mark whose accommodation already changed is closed, not alerted.
        foreach (Group::query()->whereIn('id', $due()->select('group_id'))->get() as $group) {
            $this->reconcile($group);
        }

        $raised = 0;

        foreach ($due()->with('markedBy')->orderBy('id')->get() as $mark) {
            $marker = $mark->markedBy;

            if ($marker === null
                || $marker->disabled_at !== null
                || $marker->school_id !== $mark->school_id
                || ! $marker->hasRole(Role::Psychopedagogue->value)) {
                continue;
            }

            $alert = DB::transaction(function () use ($mark, $marker): ?Alert {
                $locked = AIInputMark::query()->whereKey($mark->id)->pending()->lockForUpdate()->first();

                if ($locked === null || $locked->alert()->exists()) {
                    return null;
                }

                $alert = Alert::create([
                    'student_id' => null,
                    'subject_id' => $locked->subject_id,
                    'ai_input_mark_id' => $locked->id,
                    'type' => AlertType::AIInputMark,
                    'severity' => AlertSeverity::Medium,
                    // Never the line or the comment: they live on the mark.
                    'description' => sprintf(
                        'Marcaste una línea de «Lo que recibe la IA» como mal interpretada el %s y sigue pendiente: mientras tanto no se envía. Se resuelve cambiando el ajuste o con «El ajuste está bien».',
                        $locked->created_at?->format('d/m'),
                    ),
                    'condition_met_on' => today(),
                    // Nobody by role: only the person who marked it.
                    'recipients' => [],
                    'resolved' => false,
                ]);
                $alert->people()->attach($marker->id);

                return $alert;
            });

            if ($alert === null) {
                continue;
            }

            $raised++;
            // Ids only — never the line or the comment (CLAUDE.md rule 11).
            $this->notifier->notify('alert.generated', [
                'alert_id' => $alert->id,
                'ai_input_mark_id' => $mark->id,
                'type' => $alert->type->value,
            ]);
        }

        return $raised;
    }

    /**
     * The scope of a request from `$viewer`, as BuildProposalContext builds
     * it for a request without assessment type.
     *
     * @return Closure(HasMany<Accommodation, Student>): void
     */
    protected function scope(?int $subjectId, User $viewer): Closure
    {
        return function (HasMany $query) use ($subjectId, $viewer): void {
            $query->effective()->started();

            if ($subjectId !== null) {
                $query->appliesToSubject($subjectId);
            }

            $query->visibleTo($viewer);
        };
    }

    /**
     * Every line of the group in force today, whatever the subject: what a
     * request from psychopedagogy or direction receives (P03-ACT-81).
     *
     * @return Closure(HasMany<Accommodation, Student>): void
     */
    protected function fullScope(): Closure
    {
        return function (HasMany $query): void {
            $query->effective()->started();
        };
    }

    protected function close(AIInputMark $mark, AIInputMarkResolution $resolution, ?User $actor, ?string $comment): void
    {
        $mark->update([
            'resolution' => $resolution,
            'resolution_comment' => $comment,
            'resolved_by_id' => $actor?->id,
            'resolved_at' => now(),
        ]);

        // Its alert closes with it: there is nothing left to work on.
        Alert::query()
            ->where('ai_input_mark_id', $mark->id)
            ->where('resolved', false)
            ->get()
            ->each(fn (Alert $alert) => $alert->update([
                'resolved' => true,
                'resolved_by_id' => $actor?->id,
                'resolved_at' => now(),
            ]));
    }

    protected function notifyTeam(AIInputMark $mark, string $kind): void
    {
        $recipients = array_values(array_filter((array) config('services.support.notify_to')));

        if ($recipients !== []) {
            Notification::route('mail', $recipients)->notify(new AIInputMarkNotification($mark, $kind));
        }
    }
}
