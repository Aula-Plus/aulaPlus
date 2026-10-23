<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseFollowUpStageRequest;
use App\Http\Requests\SplitFollowUpStageRequest;
use App\Http\Requests\StoreFollowUpStageNoteRequest;
use App\Http\Requests\StoreFollowUpStageRequest;
use App\Http\Requests\UpdateFollowUpStageRequest;
use App\Http\Resources\ScheduledFollowUpDetailResource;
use App\Models\FollowUpStage;
use App\Models\ScheduledFollowUp;
use App\Services\FollowUps\FollowUpWorkflow;

/**
 * Stages of a follow-up (ClickUp 86e3qkwna): add, edit, subdivide, close and
 * annotate. Each action is authorized by its FormRequest (ScheduledFollowUp
 * Policy / FollowUpStagePolicy) and answers with the whole updated record, so
 * the client never has to stitch partial state together.
 */
class FollowUpStageController extends Controller
{
    public function __construct(protected FollowUpWorkflow $workflow) {}

    public function store(StoreFollowUpStageRequest $request, ScheduledFollowUp $followUp): ScheduledFollowUpDetailResource
    {
        $this->workflow->addStage($followUp, $request->user(), $request->validated());

        return ScheduledFollowUpDetailResource::forViewer($followUp, $request->user());
    }

    public function update(UpdateFollowUpStageRequest $request, FollowUpStage $stage): ScheduledFollowUpDetailResource
    {
        $this->workflow->updateStage($stage, $request->user(), $request->validated());

        return ScheduledFollowUpDetailResource::forViewer($stage->followUp, $request->user());
    }

    public function split(SplitFollowUpStageRequest $request, FollowUpStage $stage): ScheduledFollowUpDetailResource
    {
        $this->workflow->splitStage($stage, $request->user(), array_values($request->validated('stages')));

        return ScheduledFollowUpDetailResource::forViewer($stage->followUp, $request->user());
    }

    public function close(CloseFollowUpStageRequest $request, FollowUpStage $stage): ScheduledFollowUpDetailResource
    {
        $this->workflow->closeStage($stage, $request->user(), $request->validated('conclusion'));

        return ScheduledFollowUpDetailResource::forViewer($stage->followUp, $request->user());
    }

    public function storeNote(StoreFollowUpStageNoteRequest $request, FollowUpStage $stage): ScheduledFollowUpDetailResource
    {
        $this->workflow->addNote($stage, $request->user(), $request->validated('body'));

        return ScheduledFollowUpDetailResource::forViewer($stage->followUp, $request->user());
    }
}
