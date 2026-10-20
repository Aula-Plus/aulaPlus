<?php

namespace App\Http\Controllers;

use App\Enums\ProposalBasis;
use App\Enums\ProposalStatus;
use App\Http\Requests\ReviewAccommodationProposalRequest;
use App\Http\Requests\StoreAccommodationProposalRequest;
use App\Http\Resources\AccommodationProposalResource;
use App\Models\Accommodation;
use App\Models\AccommodationProposal;
use App\Models\ReportDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Accommodations proposed from a student report (ClickUp 86e3jujgx). Only
 * psychopedagogy reviews them (ReportDocumentPolicy::manage). Nothing is in
 * force until it approves one: approving (as is or modified) creates the
 * accommodation with the same fields a manual one has; rejecting only closes
 * the proposal. A proposal is reviewed once.
 */
class AccommodationProposalController extends Controller
{
    /** An adjustment the AI missed, added by hand. */
    public function store(StoreAccommodationProposalRequest $request, ReportDocument $report): JsonResponse
    {
        $this->authorize('manage', $report);

        $proposal = AccommodationProposal::create([
            ...$request->validated(),
            'report_document_id' => $report->id,
            'student_id' => $report->student_id,
            'basis' => ProposalBasis::Manual,
            'status' => ProposalStatus::Pending,
        ]);

        return (new AccommodationProposalResource($proposal))->response()->setStatusCode(201);
    }

    public function review(ReviewAccommodationProposalRequest $request, AccommodationProposal $proposal): JsonResponse|AccommodationProposalResource
    {
        $this->authorize('manage', $proposal->reportDocument);

        $validated = $request->validated();

        return DB::transaction(function () use ($validated, $proposal, $request): JsonResponse|AccommodationProposalResource {
            // Locked read: two simultaneous reviews must not create two accommodations.
            $locked = AccommodationProposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($locked->status !== ProposalStatus::Pending) {
                return response()->json(['message' => 'Esta propuesta ya fue revisada.'], 422);
            }

            if ($validated['decision'] === 'reject') {
                $locked->forceFill([
                    'status' => ProposalStatus::Rejected,
                    'reviewed_by_id' => $request->user()->id,
                    'reviewed_at' => now(),
                ])->save();

                return new AccommodationProposalResource($locked);
            }

            $fields = array_intersect_key($validated, array_flip(['type', 'category', 'description', 'focus_area']));
            $modified = collect($fields)->contains(
                fn ($value, string $key): bool => (string) ($key === 'category' ? $locked->category->value : $locked->{$key}) !== (string) $value,
            ) || isset($validated['starts_on']) || isset($validated['subject_ids']) || isset($validated['assessment_types']);

            $accommodation = Accommodation::create([
                'student_id' => $locked->student_id,
                'type' => $fields['type'] ?? $locked->type,
                'category' => $fields['category'] ?? $locked->category->value,
                'description' => $fields['description'] ?? $locked->description,
                'focus_area' => $fields['focus_area'] ?? ($locked->focus_area ?? ''),
                'starts_on' => $validated['starts_on'] ?? null,
                'assessment_types' => ($validated['assessment_types'] ?? []) === [] ? null : $validated['assessment_types'],
                'active' => true,
                'requires_external_approval' => false,
            ]);
            $accommodation->subjects()->sync($validated['subject_ids'] ?? []);

            $locked->forceFill([
                'status' => $modified ? ProposalStatus::Modified : ProposalStatus::Approved,
                'accommodation_id' => $accommodation->id,
                'reviewed_by_id' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            return new AccommodationProposalResource($locked);
        });
    }
}
