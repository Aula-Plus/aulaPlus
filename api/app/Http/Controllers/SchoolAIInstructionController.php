<?php

namespace App\Http\Controllers;

use App\Enums\AIRequestType;
use App\Enums\JudgmentStylePreviewStatus;
use App\Http\Requests\StoreJudgmentStylePreviewRequest;
use App\Http\Requests\StoreSchoolAIInstructionRequest;
use App\Http\Requests\UpdateSchoolAIInstructionRequest;
use App\Jobs\GenerateJudgmentStylePreviewJob;
use App\Models\Group;
use App\Models\JudgmentStylePreview;
use App\Models\School;
use App\Models\SchoolAIInstruction;
use App\Models\Subject;
use App\Services\AIInstructions\SchoolAIInstructions;
use App\Support\AiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Screen 24 «Evaluación y juicios», tabs «Juicios» and «Evaluaciones»
 * (ClickUp 1dfd3mc4bh6; P24-JUI-01/02, P24-EVA-02, P24-DEC-20/110/111).
 *
 * - GET    /ai-instructions                 every request type, configurable or not, with its rules
 * - POST   /ai-instructions                 a new rule (institution, level or range)
 * - PUT    /ai-instructions/{instruction}   its fields (the screen saves on its own)
 * - DELETE /ai-instructions/{instruction}
 * - GET    /judgment-style-previews/options groups and subjects for «Ver ejemplo»
 * - POST   /judgment-style-previews         queue «Ver ejemplo»
 * - GET    /judgment-style-previews/{id}    poll it
 *
 * Direction only (SchoolPolicy::manageAIInstructions). Every rule carries
 * `prompt_text`: «Así le llega a la IA», exactly what the model receives.
 */
class SchoolAIInstructionController extends Controller
{
    public function __construct(protected SchoolAIInstructions $instructions) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSchool($request);

        $rules = SchoolAIInstruction::query()->orderBy('id')->get()->groupBy(fn ($rule) => $rule->request_type->value);

        return response()->json([
            'data' => array_map(fn (AIRequestType $type): array => [
                'request_type' => $type->value,
                'configurable' => $type->isConfigurable(),
                'default_prompt_text' => $this->instructions->promptText($type, null),
                'rules' => ($rules[$type->value] ?? collect())
                    ->map(fn (SchoolAIInstruction $rule) => $this->ruleData($rule))
                    ->values()
                    ->all(),
            ], AIRequestType::cases()),
        ]);
    }

    public function store(StoreSchoolAIInstructionRequest $request): JsonResponse
    {
        $type = AIRequestType::from($request->validated('request_type'));
        $levelId = $request->validated('education_level_id');

        $rule = SchoolAIInstruction::query()->create([
            'request_type' => $type,
            'education_level_id' => $levelId,
            'school_grade_ids' => $levelId === null ? null : $request->gradeIds(),
            'settings' => SchoolAIInstructions::sanitize($type, $request->validated('settings') ?? []),
            'updated_by_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->ruleData($rule)], Response::HTTP_CREATED);
    }

    public function update(UpdateSchoolAIInstructionRequest $request, SchoolAIInstruction $instruction): JsonResponse
    {
        $instruction->update([
            'settings' => SchoolAIInstructions::sanitize($instruction->request_type, $request->validated('settings')),
            'updated_by_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->ruleData($instruction)]);
    }

    public function destroy(Request $request, SchoolAIInstruction $instruction): JsonResponse
    {
        $this->authorize('manageAIInstructions', $instruction->school);
        $instruction->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Groups of the school with the subjects taught in each, for «Grupo y
     * materia» of «Ver ejemplo». Names only.
     */
    public function previewOptions(Request $request): JsonResponse
    {
        $this->authorizeSchool($request);

        $groups = Group::query()
            ->with('teachers')
            ->orderByDesc('school_year')
            ->orderBy('name')
            ->get();

        $subjects = Subject::query()
            ->whereKey($groups->flatMap(fn (Group $group) => $group->teachers->pluck('pivot.subject_id'))->filter()->unique())
            ->pluck('name', 'id');

        return response()->json([
            'data' => $groups
                ->map(fn (Group $group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'school_year' => $group->school_year,
                    'subjects' => $group->teachers->pluck('pivot.subject_id')->filter()->unique()
                        ->filter(fn ($id) => isset($subjects[$id]))
                        ->map(fn ($id) => ['id' => (int) $id, 'name' => $subjects[$id]])
                        ->sortBy('name')
                        ->values()
                        ->all(),
                ])
                ->filter(fn (array $group) => $group['subjects'] !== [])
                ->values()
                ->all(),
        ]);
    }

    public function storePreview(StoreJudgmentStylePreviewRequest $request): JsonResponse
    {
        abort_unless(AiProvider::isConfigured(), Response::HTTP_SERVICE_UNAVAILABLE, AiProvider::UNAVAILABLE);

        $preview = JudgmentStylePreview::query()->create([
            'requested_by_id' => $request->user()->id,
            'group_id' => $request->integer('group_id'),
            'subject_id' => $request->integer('subject_id'),
            'status' => JudgmentStylePreviewStatus::Pending,
        ]);

        GenerateJudgmentStylePreviewJob::dispatch($preview);

        return response()->json(['data' => $this->previewData($preview->refresh())], Response::HTTP_ACCEPTED);
    }

    public function showPreview(Request $request, JudgmentStylePreview $preview): JsonResponse
    {
        $this->authorize('manageAIInstructions', $preview->school);

        return response()->json(['data' => $this->previewData($preview)]);
    }

    protected function authorizeSchool(Request $request): void
    {
        $this->authorize('manageAIInstructions', School::query()->findOrFail($request->user()->school_id));
    }

    /**
     * @return array<string, mixed>
     */
    protected function ruleData(SchoolAIInstruction $rule): array
    {
        return [
            'id' => $rule->id,
            'request_type' => $rule->request_type->value,
            'education_level_id' => $rule->education_level_id,
            'school_grade_ids' => $rule->gradeIds(),
            'settings' => SchoolAIInstructions::sanitize($rule->request_type, $rule->settings ?? []),
            'prompt_text' => $this->instructions->promptText($rule->request_type, $rule->settings),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function previewData(JudgmentStylePreview $preview): array
    {
        return [
            'id' => $preview->id,
            'status' => $preview->status->value,
            'draft' => $preview->draft,
            'error' => $preview->status === JudgmentStylePreviewStatus::Error,
        ];
    }
}
