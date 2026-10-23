<?php

namespace App\Http\Controllers;

use App\Actions\AI\ApplyProposal;
use App\Enums\AIProposalStatus;
use App\Enums\JourneyStep;
use App\Http\Requests\GenerateAIProposalRequest;
use App\Http\Resources\AIProposalResource;
use App\Jobs\GenerateAIProposalJob;
use App\Models\AIProposal;
use App\Models\Group;
use App\Support\AiProvider;
use App\Support\UsageJourney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI teaching-assistant endpoints (docs/prompts/05-asistente-ia-docente.md §3).
 * The assistant only ever proposes: `generate` queues a draft, the teacher
 * polls with `show`, then explicitly `apply`s or `discard`s it.
 */
class AIProposalController extends Controller
{
    /**
     * POST /groups/{group}/assistant/generate — authorization + per-type
     * validation live in the Form Request. Creates a pending proposal, queues
     * generation, and returns 202 Accepted — or 503 when this environment has
     * no AI provider configured (nothing is created or queued).
     */
    public function generate(GenerateAIProposalRequest $request, Group $group): JsonResponse
    {
        abort_unless(AiProvider::isConfigured(), Response::HTTP_SERVICE_UNAVAILABLE, AiProvider::UNAVAILABLE);

        $referenceId = $request->input('parameters.reference_proposal_id');

        $proposal = AIProposal::create([
            'group_id' => $group->id,
            'requested_by_id' => $request->user()->id,
            'journey_id' => UsageJourney::forNewProposal(
                $request->validated('journey_id'),
                $referenceId === null ? null : AIProposal::find($referenceId),
                $request->user()->id,
            ),
            'type' => $request->input('type'),
            'input_parameters' => $request->input('parameters'),
            'status' => AIProposalStatus::Pending,
        ]);

        // The journey's attempt (or, inside an existing journey, a retry /
        // change request) — ClickUp 86e3qkxtc.
        UsageJourney::recordGenerationRequest($proposal);

        GenerateAIProposalJob::dispatch($proposal);

        return (new AIProposalResource($proposal))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(AIProposal $aiProposal): AIProposalResource
    {
        $this->authorize('view', $aiProposal);

        return new AIProposalResource($aiProposal);
    }

    public function apply(Request $request, AIProposal $aiProposal, ApplyProposal $applyProposal): AIProposalResource
    {
        $this->authorize('apply', $aiProposal);

        abort_unless(
            $aiProposal->status === AIProposalStatus::Completed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Only a completed proposal can be applied.',
        );

        // The policy guarantees the applier is the requester; authorship of the
        // created entity points at whoever applies it (spec §5).
        $applyProposal($aiProposal, $request->user());

        return new AIProposalResource($aiProposal->refresh());
    }

    public function discard(AIProposal $aiProposal): AIProposalResource
    {
        $this->authorize('discard', $aiProposal);

        abort_if(
            $aiProposal->status === AIProposalStatus::Applied,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'An applied proposal cannot be discarded.',
        );

        $aiProposal->update(['status' => AIProposalStatus::Discarded]);

        if ($aiProposal->journey_id !== null) {
            UsageJourney::record(
                $aiProposal->requested_by_id,
                $aiProposal->journey_id,
                JourneyStep::DraftDiscarded,
                $aiProposal->type->value,
                $aiProposal->group_id,
                metadata: ['ai_proposal_id' => $aiProposal->id],
            );
        }

        return new AIProposalResource($aiProposal);
    }
}
