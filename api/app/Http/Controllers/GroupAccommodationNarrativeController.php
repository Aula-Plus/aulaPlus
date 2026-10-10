<?php

namespace App\Http\Controllers;

use App\Actions\Groups\BuildGroupAccommodationSummary;
use App\Enums\AccommodationNarrativeStatus;
use App\Jobs\GenerateGroupAccommodationNarrativeJob;
use App\Models\Group;
use App\Models\GroupAccommodationNarrative;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI text summary of a group's accommodations. Psychopedagogy generates a
 * draft, reads it and publishes it; teachers of the group, psychopedagogy and
 * direction read the last published one. Drafts and generation state are
 * visible to psychopedagogy only.
 *
 * Writes (generate, publish) lock the group row first, so they run one at a
 * time per group: no two pending generations, no two published texts.
 */
class GroupAccommodationNarrativeController extends Controller
{
    /**
     * How long a `pending` generation is considered still running. Past it the
     * job is assumed lost (no worker, crash before failed()): show() reports it
     * as stale and generate() replaces it. Well above the job's own timeout.
     */
    public const PENDING_WINDOW_MINUTES = 10;

    public const ERROR_STALE = 'Generation did not finish in time.';

    /**
     * GET /groups/{group}/accommodation-narrative
     */
    public function show(Group $group, BuildGroupAccommodationSummary $summarize): JsonResponse
    {
        $this->authorize('view', $group);

        $fingerprint = $summarize->fingerprint($summarize($group));
        $canGenerate = request()->user()->can('generateAccommodationNarrative', $group);

        $published = GroupAccommodationNarrative::query()
            ->where('group_id', $group->id)
            ->where('status', AccommodationNarrativeStatus::Published)
            ->latest('published_at')
            ->with('publishedBy:id,name')
            ->first();

        $latest = $canGenerate
            ? GroupAccommodationNarrative::query()
                ->where('group_id', $group->id)
                ->whereIn('status', [
                    AccommodationNarrativeStatus::Pending,
                    AccommodationNarrativeStatus::Draft,
                    AccommodationNarrativeStatus::Error,
                ])
                ->latest('id')
                ->first()
            : null;

        // A generation that never finished is reported as failed, so the UI
        // stops waiting and offers to generate again.
        $stale = $latest !== null && $this->isStale($latest);

        return response()->json([
            'can_generate' => $canGenerate,
            'published' => $published ? [
                'id' => $published->id,
                'content' => $published->content,
                'published_at' => $published->published_at?->toIso8601String(),
                'published_by' => $published->publishedBy?->name,
                'outdated' => $published->fingerprint !== $fingerprint,
            ] : null,
            'draft' => $latest ? [
                'id' => $latest->id,
                'status' => $stale ? AccommodationNarrativeStatus::Error->value : $latest->status->value,
                'content' => $latest->content,
                'error_message' => $stale ? self::ERROR_STALE : $latest->error_message,
                'stale' => $stale,
                // Retrying won't help: an accommodation's text names a student.
                'name_in_data' => $latest->error_message === GenerateGroupAccommodationNarrativeJob::ERROR_NAME_IN_DATA,
                // Which accommodation types to fix, with the name redacted.
                'flagged_types' => $latest->flagged_types ?? [],
                'outdated' => $latest->fingerprint !== $fingerprint,
            ] : null,
        ]);
    }

    /**
     * POST /groups/{group}/accommodation-narrative/generate
     */
    public function generate(Group $group, BuildGroupAccommodationSummary $summarize): JsonResponse
    {
        $this->authorize('generateAccommodationNarrative', $group);

        $summary = $summarize($group);

        abort_if($summary === [], Response::HTTP_UNPROCESSABLE_ENTITY, 'The group has no active accommodations.');

        $fingerprint = $summarize->fingerprint($summary);

        try {
            [$narrative, $created] = DB::transaction(function () use ($group, $fingerprint): array {
                $this->lockGroup($group);

                // A generation already running for this group (double click,
                // two tabs): hand that one back instead of paying for a second
                // AI call.
                $running = GroupAccommodationNarrative::query()
                    ->where('group_id', $group->id)
                    ->where('status', AccommodationNarrativeStatus::Pending)
                    ->latest('id')
                    ->first();

                if ($running !== null && ! $this->isStale($running)) {
                    return [$running, false];
                }

                // A stale one is given up: marked failed, so its job does
                // nothing if a worker still picks it up later.
                $running?->update([
                    'status' => AccommodationNarrativeStatus::Error,
                    'error_message' => self::ERROR_STALE,
                ]);

                // Older drafts are superseded by the new generation and can no
                // longer be published.
                GroupAccommodationNarrative::query()
                    ->where('group_id', $group->id)
                    ->where('status', AccommodationNarrativeStatus::Draft)
                    ->update(['status' => AccommodationNarrativeStatus::Archived]);

                $narrative = GroupAccommodationNarrative::create([
                    'group_id' => $group->id,
                    'status' => AccommodationNarrativeStatus::Pending,
                    'fingerprint' => $fingerprint,
                    'generated_by_id' => request()->user()->id,
                ]);

                return [$narrative, true];
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race the lock didn't serialise: the one-pending-per-group
            // index caught it, so the other request's generation is the one.
            $narrative = GroupAccommodationNarrative::query()
                ->where('group_id', $group->id)
                ->where('status', AccommodationNarrativeStatus::Pending)
                ->firstOrFail();
            $created = false;
        }

        // Dispatched after the commit, so the job always sees its row.
        if ($created) {
            GenerateGroupAccommodationNarrativeJob::dispatch($narrative);
        }

        return response()->json(['id' => $narrative->id, 'status' => $narrative->status->value], Response::HTTP_ACCEPTED);
    }

    /**
     * POST /groups/{group}/accommodation-narrative/{narrative}/publish
     */
    public function publish(Group $group, GroupAccommodationNarrative $narrative): JsonResponse
    {
        $this->authorize('generateAccommodationNarrative', $group);

        abort_unless($narrative->group_id === $group->id, Response::HTTP_NOT_FOUND);

        // Archive + publish as one unit under the group lock, so two concurrent
        // publishes (of the same or of different drafts) run one after the
        // other: the second one finds no draft, and the group never ends up
        // with two published texts or with none.
        $narrative = DB::transaction(function () use ($group, $narrative): GroupAccommodationNarrative {
            $this->lockGroup($group);
            $narrative = GroupAccommodationNarrative::query()->findOrFail($narrative->id);

            abort_unless(
                $narrative->status === AccommodationNarrativeStatus::Draft,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Only a draft can be published.',
            );

            GroupAccommodationNarrative::query()
                ->where('group_id', $group->id)
                ->where('status', AccommodationNarrativeStatus::Published)
                ->update(['status' => AccommodationNarrativeStatus::Archived]);

            $narrative->update([
                'status' => AccommodationNarrativeStatus::Published,
                'published_by_id' => request()->user()->id,
                'published_at' => now(),
            ]);

            return $narrative;
        });

        return response()->json(['id' => $narrative->id, 'status' => $narrative->status->value]);
    }

    /** Serialises the narrative writes of a group (no-op on SQLite, which locks the whole DB). */
    protected function lockGroup(Group $group): void
    {
        Group::query()->whereKey($group->id)->lockForUpdate()->first();
    }

    protected function isStale(GroupAccommodationNarrative $narrative): bool
    {
        return $narrative->status === AccommodationNarrativeStatus::Pending
            && $narrative->created_at->lt(now()->subMinutes(self::PENDING_WINDOW_MINUTES));
    }
}
