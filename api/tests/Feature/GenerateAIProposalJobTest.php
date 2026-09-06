<?php

use App\Enums\AIProposalStatus;
use App\Enums\AIProposalType;
use App\Jobs\GenerateAIProposalJob;
use App\Models\AIProposal;
use App\Models\Group;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function pendingProposal(AIProposalType $type = AIProposalType::Unit, array $parameters = ['focus' => 'x']): AIProposal
{
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    return AIProposal::factory()->create([
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
        'type' => $type,
        'input_parameters' => $parameters,
        'status' => AIProposalStatus::Pending,
    ]);
}

function anthropicText(string $text): array
{
    return ['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn'];
}

$validUnit = [
    'name' => 'Unidad 1',
    'suggested_position' => 1,
    'suggested_start_date' => '2026-03-01',
    'suggested_end_date' => '2026-03-21',
    'suggested_curricular_items' => ['MAT-001'],
    'sessions' => [['title' => 'Intro', 'objective' => 'Ver', 'duration_minutes' => 45]],
];

it('marks the proposal completed and stores the parsed response on a valid JSON reply', function () use ($validUnit) {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicText(json_encode($validUnit)), 200)]);
    $proposal = pendingProposal();

    GenerateAIProposalJob::dispatchSync($proposal);

    $proposal->refresh();
    expect($proposal->status)->toBe(AIProposalStatus::Completed)
        ->and($proposal->raw_response['name'])->toBe('Unidad 1')
        ->and($proposal->raw_response['sessions'])->toHaveCount(1)
        ->and($proposal->context_sent)->not->toBeNull()
        ->and($proposal->error_message)->toBeNull();
});

it('handles a ```json fenced reply', function () use ($validUnit) {
    $fenced = "```json\n".json_encode($validUnit)."\n```";
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicText($fenced), 200)]);
    $proposal = pendingProposal();

    GenerateAIProposalJob::dispatchSync($proposal);

    expect($proposal->refresh()->status)->toBe(AIProposalStatus::Completed);
});

it('marks the proposal errored without throwing when the reply is not valid JSON', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicText('this is not json'), 200)]);
    $proposal = pendingProposal();

    GenerateAIProposalJob::dispatchSync($proposal);

    $proposal->refresh();
    expect($proposal->status)->toBe(AIProposalStatus::Error)
        ->and($proposal->error_message)->not->toBeNull()
        ->and($proposal->raw_response)->toBeNull();
});

it('marks the proposal errored when valid JSON does not match the type schema', function () {
    // Missing the required `sessions` array for a unit.
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicText(json_encode(['name' => 'x'])), 200)]);
    $proposal = pendingProposal();

    GenerateAIProposalJob::dispatchSync($proposal);

    expect($proposal->refresh()->status)->toBe(AIProposalStatus::Error);
});

it('retries on a network timeout and then marks the proposal errored', function () {
    $attempts = 0;
    Http::fake(['api.anthropic.com/*' => function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('timed out');
    }]);
    $proposal = pendingProposal();

    GenerateAIProposalJob::dispatchSync($proposal);

    $proposal->refresh();
    expect($proposal->status)->toBe(AIProposalStatus::Error)
        ->and($proposal->error_message)->not->toBeNull()
        // 1 initial attempt + 2 retries.
        ->and($attempts)->toBe(3);
});

it('marks the proposal errored on a non-2xx API status', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'bad'], 400)]);
    $proposal = pendingProposal();

    GenerateAIProposalJob::dispatchSync($proposal);

    expect($proposal->refresh()->status)->toBe(AIProposalStatus::Error);
});
