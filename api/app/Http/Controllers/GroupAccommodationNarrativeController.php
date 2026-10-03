<?php

namespace App\Http\Controllers;

use App\Actions\Groups\BuildGroupAccommodationSummary;
use App\Enums\AccommodationNarrativeStatus;
use App\Jobs\GenerateGroupAccommodationNarrativeJob;
use App\Models\Group;
use App\Models\GroupAccommodationNarrative;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI text summary of a group's accommodations. Psychopedagogy generates a
 * draft, reads it and publishes it; teachers of the group, psychopedagogy and
 * direction read the last published one. Drafts and generation state are
 * visible to psychopedagogy only.
 */
class GroupAccommodationNarrativeController extends Controller
{
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

        return response()->json(['id' => $narrative->id, 'status' => $narrative->status->value]);
    }
}
