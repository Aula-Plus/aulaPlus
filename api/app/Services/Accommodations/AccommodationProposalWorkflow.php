<?php

namespace App\Services\Accommodations;

use App\Enums\AccommodationReviewAction;
use App\Enums\AccommodationReviewStatus;
use App\Enums\Role;
use App\Http\Controllers\StudentTrackingController;
use App\Models\Accommodation;
use App\Models\AccommodationReviewEvent;
use App\Models\ScheduledFollowUp;
use App\Models\User;
use App\Notifications\AccommodationProposalReturnedNotification;
use App\Notifications\FollowUpAssignedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The circuit by which direction proposes an accommodation and psychopedagogy
 * confirms it (ClickUp 86e3qkwkk). Authorization is decided before this runs
 * (AccommodationPolicy, via the Form Requests); this class only checks the
 * business state (422 when the proposal is not in a state that allows the
 * step) and writes it, always in one transaction.
 *
 * - submit:   a new proposal is pending; psychopedagogy gets a review task
 *             (a ScheduledFollowUp linked to it) with the deadline direction
 *             picked, sent to the whole role or to the people direction
 *             picked — nobody is singled out as responsible on their behalf.
 * - edited:   direction or psychopedagogy edits an open proposal — the same
 *             proposal, no new one; editing never publishes it.
 * - return:   psychopedagogy sends it back with a comment (direction's turn);
 *             the proposer is notified. The review task stays open.
 * - resubmit: direction sends it back to review.
 * - confirm:  psychopedagogy confirms it; it becomes an ordinary accommodation
 *             (in force from today unless a start date is given), with the
 *             «Origen» psychopedagogy wrote, and the review task closes. Who
 *             confirmed it is recorded.
 * - withdraw: direction withdraws it with a reason; the review task closes.
 * - reject:   psychopedagogy rejects it with a reason; the review task closes.
 *
 * Nothing is ever deleted: content, history, who and when stay on the proposal.
 * Notes are stored but never logged (CLAUDE.md rule 11).
 */
class AccommodationProposalWorkflow
{
    /**
     * Whether an accommodation created by this user must go through review:
     * direction proposes, psychopedagogy creates directly. Someone holding
     * both roles creates directly.
     */
    public static function createsProposals(User $user): bool
    {
        return $user->hasRole(Role::Director->value) && ! $user->hasRole(Role::Psychopedagogue->value);
    }

    /**
     * Called right after a proposal was created (same transaction).
     *
     * @param  string  $dueDate  the review deadline direction picked (Y-m-d)
     * @param  list<int>|null  $reviewerIds  the psychopedagogues direction
     *                                       picked; null = the whole role
     *                                       (every active psychopedagogue)
     */
    public function submitted(Accommodation $accommodation, User $actor, string $dueDate, ?array $reviewerIds = null): void
    {
        $this->record($accommodation, $actor, AccommodationReviewAction::Submitted);

        $reviewers = User::query()
            ->where('school_id', $accommodation->school_id)
            ->whereNull('disabled_at')
            ->role(Role::Psychopedagogue->value)
            ->when($reviewerIds !== null, fn ($query) => $query->whereKey($reviewerIds))
            ->orderBy('name')
            ->get();

        $followUp = ScheduledFollowUp::create([
            'student_id' => $accommodation->student_id,
            'accommodation_id' => $accommodation->id,
            // The title is rendered by the client from the link (UI text lives
            // in the frontend); no proposal content is copied here.
            'description' => '',
            'due_date' => $dueDate,
            'created_by_id' => $actor->id,
            // Nobody is made responsible on the reviewers' behalf (21/10
            // answer): every reviewer gets it in their pending list.
            'assigned_to_id' => null,
        ]);
        $followUp->sharedWith()->sync($reviewers->modelKeys());

        foreach ($reviewers as $reviewer) {
            $reviewer->notify(new FollowUpAssignedNotification($followUp));
        }
    }

    public function edited(Accommodation $accommodation, User $actor): void
    {
        $this->record($accommodation, $actor, AccommodationReviewAction::Edited);
    }

    public function returnToDirection(Accommodation $accommodation, User $actor, string $note): Accommodation
    {
        return DB::transaction(function () use ($accommodation, $actor, $note): Accommodation {
            $this->ensureStatus($accommodation, [AccommodationReviewStatus::Pending]);

            $accommodation->update(['review_status' => AccommodationReviewStatus::Returned]);
            $this->record($accommodation, $actor, AccommodationReviewAction::Returned, $note);

            $proposer = $accommodation->proposedBy;
            if ($proposer !== null && $proposer->disabled_at === null) {
                $proposer->notify(new AccommodationProposalReturnedNotification($accommodation));
            }

            return $accommodation;
        });
    }

    public function resubmit(Accommodation $accommodation, User $actor, ?string $note): Accommodation
    {
        return DB::transaction(function () use ($accommodation, $actor, $note): Accommodation {
            $this->ensureStatus($accommodation, [AccommodationReviewStatus::Returned]);

            $accommodation->update(['review_status' => AccommodationReviewStatus::Pending]);
            $this->record($accommodation, $actor, AccommodationReviewAction::Resubmitted, $note);

            return $accommodation;
        });
    }

    /**
     * @param  array{origin?: string|null}  $origin  «Origen», when given
     */
    public function confirm(Accommodation $accommodation, User $actor, ?string $startsOn, array $origin = []): Accommodation
    {
        return DB::transaction(function () use ($accommodation, $actor, $startsOn, $origin): Accommodation {
            $this->ensureStatus($accommodation, [AccommodationReviewStatus::Pending, AccommodationReviewStatus::Returned]);

            $accommodation->update([
                'review_status' => AccommodationReviewStatus::Confirmed,
                'reviewed_by_id' => $actor->id,
                'reviewed_at' => now(),
                'active' => true,
                // Absent: Accommodation::booted moves it to today, the day it
                // becomes effective.
                ...($startsOn !== null ? ['starts_on' => $startsOn] : []),
                ...$origin,
            ]);
            $this->record($accommodation, $actor, AccommodationReviewAction::Confirmed);
            $this->closeReviewTask($accommodation, $actor);
            // It is in force now: the cached «Ajustes vigentes» must show it.
            Cache::forget(StudentTrackingController::cacheKey($accommodation->student_id));

            return $accommodation;
        });
    }

    public function withdraw(Accommodation $accommodation, User $actor, string $reason): Accommodation
    {
        return DB::transaction(function () use ($accommodation, $actor, $reason): Accommodation {
            $this->ensureStatus($accommodation, [AccommodationReviewStatus::Pending, AccommodationReviewStatus::Returned]);

            $accommodation->update([
                'review_status' => AccommodationReviewStatus::Withdrawn,
                'reviewed_by_id' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $this->record($accommodation, $actor, AccommodationReviewAction::Withdrawn, $reason);
            $this->closeReviewTask($accommodation, $actor);

            return $accommodation;
        });
    }

    public function reject(Accommodation $accommodation, User $actor, string $reason): Accommodation
    {
        return DB::transaction(function () use ($accommodation, $actor, $reason): Accommodation {
            $this->ensureStatus($accommodation, [AccommodationReviewStatus::Pending, AccommodationReviewStatus::Returned]);

            $accommodation->update([
                'review_status' => AccommodationReviewStatus::Rejected,
                'reviewed_by_id' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $this->record($accommodation, $actor, AccommodationReviewAction::Rejected, $reason);
            $this->closeReviewTask($accommodation, $actor);

            return $accommodation;
        });
    }

    /**
     * Business-state precondition, not authorization: 422 when the proposal is
     * not in one of the given states.
     *
     * @param  list<AccommodationReviewStatus>  $allowed
     */
    public function ensureStatus(Accommodation $accommodation, array $allowed): void
    {
        if (! in_array($accommodation->review_status, $allowed, true)) {
            throw ValidationException::withMessages([
                'accommodation' => 'This accommodation proposal does not allow this step in its current state.',
            ]);
        }
    }

    protected function record(Accommodation $accommodation, User $actor, AccommodationReviewAction $action, ?string $note = null): void
    {
        AccommodationReviewEvent::create([
            'accommodation_id' => $accommodation->id,
            'user_id' => $actor->id,
            'action' => $action,
            'note' => $note,
        ]);
    }

    protected function closeReviewTask(Accommodation $accommodation, User $actor): void
    {
        ScheduledFollowUp::query()
            ->where('accommodation_id', $accommodation->id)
            ->where('resolved', false)
            ->get()
            ->each(fn (ScheduledFollowUp $followUp) => $followUp->update([
                'resolved' => true,
                'resolved_by_id' => $actor->id,
                'resolved_at' => now(),
            ]));
    }
}
