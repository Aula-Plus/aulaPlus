<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddFollowUpMemberRequest;
use App\Http\Requests\RemoveFollowUpParticipantRequest;
use App\Http\Requests\WithdrawFromFollowUpRequest;
use App\Http\Resources\ScheduledFollowUpDetailResource;
use App\Models\ScheduledFollowUp;
use App\Models\User;
use App\Services\FollowUps\FollowUpWorkflow;
use Illuminate\Http\Request;

/**
 * People on a follow-up (ClickUp 86e3qkwna): participants, general
 * responsibles, withdrawing and hiding. Authorized by ScheduledFollowUpPolicy,
 * through the FormRequests or `authorize()` where there is no input. Every
 * action answers with the whole updated record as the caller now sees it.
 */
class FollowUpMemberController extends Controller
{
    public function __construct(protected FollowUpWorkflow $workflow) {}

    public function storeParticipant(AddFollowUpMemberRequest $request, ScheduledFollowUp $followUp): ScheduledFollowUpDetailResource
    {
        $this->workflow->addParticipant($followUp, $request->user(), User::query()->findOrFail($request->validated('user_id')));

        return ScheduledFollowUpDetailResource::forViewer($followUp, $request->user());
    }

    public function removeParticipant(RemoveFollowUpParticipantRequest $request, ScheduledFollowUp $followUp, User $user): ScheduledFollowUpDetailResource
    {
        $this->workflow->removeParticipant($followUp, $request->user(), $user, $request->validated('reason'));

        return ScheduledFollowUpDetailResource::forViewer($followUp, $request->user());
    }

    public function storeGeneralResponsible(AddFollowUpMemberRequest $request, ScheduledFollowUp $followUp): ScheduledFollowUpDetailResource
    {
        $this->workflow->addGeneralResponsible($followUp, $request->user(), User::query()->findOrFail($request->validated('user_id')));

        return ScheduledFollowUpDetailResource::forViewer($followUp, $request->user());
    }

    public function removeGeneralResponsible(Request $request, ScheduledFollowUp $followUp, User $user): ScheduledFollowUpDetailResource
    {
        $this->authorize('removeGeneralResponsible', [$followUp, $user]);

        $this->workflow->removeGeneralResponsible($followUp, $request->user(), $user);

        return ScheduledFollowUpDetailResource::forViewer($followUp, $request->user());
    }

    public function withdraw(WithdrawFromFollowUpRequest $request, ScheduledFollowUp $followUp): ScheduledFollowUpDetailResource
    {
        $successorId = $request->validated('new_general_responsible_id');

        $this->workflow->withdraw(
            $followUp,
            $request->user(),
            $request->validated('reason'),
            $successorId !== null ? User::query()->findOrFail($successorId) : null,
        );

        return ScheduledFollowUpDetailResource::forViewer($followUp, $request->user());
    }

    public function hide(Request $request, ScheduledFollowUp $followUp): ScheduledFollowUpDetailResource
    {
        $this->authorize('hide', $followUp);

        $this->workflow->hide($followUp, $request->user());

        return ScheduledFollowUpDetailResource::forViewer($followUp, $request->user());
    }
}
