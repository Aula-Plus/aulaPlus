<?php

namespace App\Http\Controllers;

use App\Http\Requests\AIInputReviewRequest;
use App\Http\Requests\ConfirmAIInputAdjustmentRequest;
use App\Http\Requests\StoreAIInputMarkRequest;
use App\Models\AIInputMark;
use App\Models\Group;
use App\Models\Subject;
use App\Policies\AIInputMarkPolicy;
use App\Services\AIInput\AIInputMarks;
use Illuminate\Http\JsonResponse;

/**
 * «Ver lo que recibiría la IA» in the group profile, tab «Ajustes activos»
 * (ClickUp 1dfd3mc2wx2; documento vivo screen 03, P03-AJU-03). Psychopedagogy
 * and direction read it; only psychopedagogy marks lines
 * ({@see AIInputMarkPolicy}).
 */
class AIInputReviewController extends Controller
{
    public function __construct(protected AIInputMarks $marks) {}

    /**
     * GET /api/v1/groups/{group}/ai-input?subject_id= — exactly the lines the
     * AI would receive for the group (and subject, when chosen), withheld
     * lines included and flagged, with their origin accommodations.
     */
    public function show(AIInputReviewRequest $request, Group $group): JsonResponse
    {
        $user = $request->user();
        $subject = $request->validated('subject_id') !== null
            ? Subject::query()->find($request->validated('subject_id'))
            : null;

        return response()->json(['data' => [
            'subject' => $subject ? ['id' => $subject->id, 'name' => $subject->name] : null,
            // The subjects taught in the group, for the optional selector.
            'subjects' => Subject::query()
                ->whereIn('id', $group->teachers()->pluck('group_teacher.subject_id')->filter()->unique())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Subject $option) => ['id' => $option->id, 'name' => $option->name])
                ->values(),
            'lines' => $this->marks->review($group, $subject, $user),
            'can' => ['mark' => $user->can('create', [AIInputMark::class, $group])],
        ]]);
    }

    /**
     * POST /api/v1/groups/{group}/ai-input/marks — «La IA lo entendió mal».
     */
    public function store(StoreAIInputMarkRequest $request, Group $group): JsonResponse
    {
        $subject = $request->validated('subject_id') !== null
            ? Subject::query()->find($request->validated('subject_id'))
            : null;

        $mark = $this->marks->mark(
            $group,
            (string) $request->validated('line_key'),
            $subject,
            $request->comment(),
            $request->user(),
        );

        return response()->json(['data' => $this->present($mark)], 201);
    }

    /**
     * POST /api/v1/ai-input-marks/{mark}/adjustment-ok — «El ajuste está bien».
     */
    public function confirmAdjustment(ConfirmAIInputAdjustmentRequest $request, AIInputMark $mark): JsonResponse
    {
        $mark = $this->marks->confirmAdjustment($mark, $request->comment(), $request->user());

        return response()->json(['data' => $this->present($mark)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(AIInputMark $mark): array
    {
        return [
            'id' => $mark->id,
            'group_id' => $mark->group_id,
            'line_key' => $mark->line_key,
            'resolution' => $mark->resolution?->value,
            'created_at' => $mark->created_at?->toIso8601String(),
            'resolved_at' => $mark->resolved_at?->toIso8601String(),
        ];
    }
}
