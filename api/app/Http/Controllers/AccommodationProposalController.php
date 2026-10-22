<?php

namespace App\Http\Controllers;

use App\Enums\AccommodationReviewStatus;
use App\Http\Requests\AccommodationProposals\ConfirmAccommodationProposalRequest;
use App\Http\Requests\AccommodationProposals\RejectAccommodationProposalRequest;
use App\Http\Requests\AccommodationProposals\ResubmitAccommodationProposalRequest;
use App\Http\Requests\AccommodationProposals\ReturnAccommodationProposalRequest;
use App\Http\Requests\AccommodationProposals\WithdrawAccommodationProposalRequest;
use App\Http\Resources\AccommodationResource;
use App\Http\Resources\AccommodationReviewEventResource;
use App\Models\Accommodation;
use App\Models\Student;
use App\Services\Accommodations\AccommodationProposalWorkflow;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The review steps of a direction accommodation proposal (ClickUp 86e3qkwkk).
 * Authorization lives in the Form Requests (AccommodationPolicy); the
 * workflow checks the proposal's state (422) and writes it. Route-model
 * binding runs through the SchoolScope, so another tenant's {accommodation}
 * 404s first.
 */
class AccommodationProposalController extends Controller
{
    public function __construct(protected AccommodationProposalWorkflow $workflow) {}

    /**
     * GET /students/{student}/accommodation-proposals — the student's direction
     * proposals not confirmed yet, open ones first, then the closed ones
     * (withdrawn/rejected) kept for the record. A confirmed proposal is an
     * ordinary accommodation and shows with the rest. Not cached (unlike the
     * tracking aggregate), so a step shows at once. {student} resolves through
     * the SchoolScope (another tenant's 404s).
     */
    public function index(Student $student): AnonymousResourceCollection
    {
        $this->authorize('viewProposals', Accommodation::class);

        $proposals = $student->accommodations()
            ->whereIn('review_status', [
                AccommodationReviewStatus::Pending->value,
                AccommodationReviewStatus::Returned->value,
                AccommodationReviewStatus::Withdrawn->value,
                AccommodationReviewStatus::Rejected->value,
            ])
            // Everything AccommodationResource reads, so no query per row.
            ->with(['subjects', 'proposedBy', 'reviewedBy', 'latestReviewEvent'])
            ->latest('id')
            ->get()
            ->sortBy(fn (Accommodation $proposal): int => $proposal->isOpenProposal() ? 0 : 1)
            ->values();

        return AccommodationResource::collection($proposals);
    }

    public function confirm(ConfirmAccommodationProposalRequest $request, Accommodation $accommodation): AccommodationResource
    {
        return $this->respond($this->workflow->confirm(
            $accommodation,
            $request->user(),
            $request->validated('starts_on'),
            $request->safe()->only(['origin']),
        ));
    }

    public function returnProposal(ReturnAccommodationProposalRequest $request, Accommodation $accommodation): AccommodationResource
    {
        return $this->respond($this->workflow->returnToDirection($accommodation, $request->user(), $request->validated('note')));
    }

    public function resubmit(ResubmitAccommodationProposalRequest $request, Accommodation $accommodation): AccommodationResource
    {
        return $this->respond($this->workflow->resubmit($accommodation, $request->user(), $request->validated('note')));
    }

    public function withdraw(WithdrawAccommodationProposalRequest $request, Accommodation $accommodation): AccommodationResource
    {
        return $this->respond($this->workflow->withdraw($accommodation, $request->user(), $request->validated('reason')));
    }

    public function reject(RejectAccommodationProposalRequest $request, Accommodation $accommodation): AccommodationResource
    {
        return $this->respond($this->workflow->reject($accommodation, $request->user(), $request->validated('reason')));
    }

    /**
     * The whole back-and-forth of the proposal, oldest first.
     */
    public function history(Accommodation $accommodation): AnonymousResourceCollection
    {
        $this->authorize('viewReviewHistory', $accommodation);

        return AccommodationReviewEventResource::collection(
            $accommodation->reviewEvents()->with('user.roles')->get()
        );
    }

    protected function respond(Accommodation $accommodation): AccommodationResource
    {
        return new AccommodationResource($accommodation->refresh()->load('subjects'));
    }
}
