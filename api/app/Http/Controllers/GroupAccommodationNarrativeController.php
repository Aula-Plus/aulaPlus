<?php

namespace App\Http\Controllers;

use App\Actions\Groups\BuildGroupAccommodationSummary;
use App\Enums\AccommodationNarrativeStatus;
use App\Jobs\GenerateGroupAccommodationNarrativeJob;
use App\Models\Group;
use App\Models\GroupAccommodationNarrative;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI text summary of a group's accommodations. Psychopedagogy generates a
 * draft, reads it and publishes it; teachers of the group, psychopedagogy and
 * direction read the last published one. Drafts and generation state are
 * visible to psychopedagogy only.
 */
class GroupAccommodationNarrativeController extends Controller
{
    /** How long a `pending` generation is considered still running. */
    protected const PENDING_WINDOW_MINUTES = 10;

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
                'status' => $latest->status->value,
                'content' => $latest->content,
                'error_message' => $latest->error_message,
                // Retrying won't help: an accommodation's text names a student.
                'name_in_data' => $latest->error_message === GenerateGroupAccommodationNarrativeJob::ERROR_NAME_IN_DATA,
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

        // A generation already running for this group (double click, two
        // tabs): hand that one back instead of paying for a second AI call. A
        // pending row older than the window is treated as lost and ignored.
        $running = GroupAccommodationNarrative::query()
            ->where('group_id', $group->id)
            ->where('status', AccommodationNarrativeStatus::Pending)
            ->where('created_at', '>=', now()->subMinutes(self::PENDING_WINDOW_MINUTES))
            ->latest('id')
            ->first();

        if ($running !== null) {
            return response()->json(['id' => $running->id, 'status' => $running->status->value], Response::HTTP_ACCEPTED);
        }

        $narrative = GroupAccommodationNarrative::create([
            'group_id' => $group->id,
            'status' => AccommodationNarrativeStatus::Pending,
            'fingerprint' => $summarize->fingerprint($summary),
            'generated_by_id' => request()->user()->id,
        ]);

        GenerateGroupAccommodationNarrativeJob::dispatch($narrative);

        return response()->json(['id' => $narrative->id, 'status' => $narrative->status->value], Response::HTTP_ACCEPTED);
    }

    /**
     * POST /groups/{group}/accommodation-narrative/{narrative}/publish
     */
    public function publish(Group $group, GroupAccommodationNarrative $narrative): JsonResponse
    {
        $this->authorize('generateAccommodationNarrative', $group);

        abort_unless($narrative->group_id === $group->id, Response::HTTP_NOT_FOUND);

        // Archive + publish as one unit, re-reading the row under a lock so two
        // concurrent publishes can't both pass the "is a draft" check or leave
        // the group with the old text archived and no new one published.
        $narrative = DB::transaction(function () use ($group, $narrative): GroupAccommodationNarrative {
            $narrative = GroupAccommodationNarrative::query()->lockForUpdate()->findOrFail($narrative->id);

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
}
