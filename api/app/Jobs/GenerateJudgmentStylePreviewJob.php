<?php

namespace App\Jobs;

use App\Enums\AIRequestType;
use App\Enums\JudgmentStylePreviewStatus;
use App\Models\AnnualPlan;
use App\Models\JudgmentStylePreview;
use App\Services\AIInstructions\SchoolAIInstructions;
use App\Support\AiProvider;
use App\Support\AnthropicMessages;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * «Ver ejemplo» of screen 24 (P24-JUI-02, P24-DEC-110 / PA-126; ClickUp
 * 1dfd3mc4bh6): a judgment written with the rule that applies to the group
 * Direction picked, for an invented student.
 *
 * What reaches the model: Aula+'s fixed base, the school's layer, the subject
 * name, the group's level and the unit names of its annual plans for that
 * subject, and an invented teacher's note about the invented student (code
 * ALUMNO_1). No real student's data, no scores (a real judgment never sends
 * them either, P24-DEC-112 / PA-128). Logs carry only the preview id.
 */
class GenerateJudgmentStylePreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The invented student's name, put back in place of the code. */
    public const EXAMPLE_NAME = 'Sofía';

    protected const MAX_UNITS = 8;

    public function __construct(public JudgmentStylePreview $preview) {}

    public function handle(SchoolAIInstructions $instructions): void
    {
        Tenancy::forSchool($this->preview->school, function () use ($instructions): void {
            if (! AiProvider::isConfigured()) {
                $this->markError(AiProvider::NOT_CONFIGURED);

                return;
            }

            $group = $this->preview->group;
            $rule = $instructions->resolveFor(AIRequestType::Judgment, $group);
            $system = $instructions->promptText(AIRequestType::Judgment, $rule?->settings)
                ."\n\n".'Respondé ÚNICAMENTE con un objeto JSON válido, sin texto adicional: {"draft": string}';

            $text = AnthropicMessages::text($system, $this->userPrompt());
            $draft = $this->parse($text);

            if ($draft === null) {
                $this->markError($text === null ? 'The AI provider did not answer.' : 'AI response was not valid JSON.');

                return;
            }

            $this->preview->update([
                'status' => JudgmentStylePreviewStatus::Completed,
                'draft' => str_replace(SchoolAIInstructions::STUDENT_CODE, self::EXAMPLE_NAME, $draft),
                'error_message' => null,
            ]);
        });
    }

    protected function userPrompt(): string
    {
        $code = SchoolAIInstructions::STUDENT_CODE;
        $group = $this->preview->group;
        $subject = $this->preview->subject?->name ?? '';

        $units = AnnualPlan::query()
            ->where('group_id', $group->id)
            ->where('subject_id', $this->preview->subject_id)
            ->whereNull('student_id')
            ->with('units')
            ->get()
            ->flatMap(fn (AnnualPlan $plan) => $plan->units->pluck('name'))
            ->unique()
            ->take(self::MAX_UNITS)
            ->values()
            ->all();

        $topic = $units[0] ?? $subject;

        $context = [
            'subject' => $subject,
            'group_level' => $group->educationLevel?->label,
            'units' => $units,
            'student' => $code,
            // Invented, like the student: what a teacher would write before
            // asking for the draft (P24-DEC-112). It shows how the rule treats
            // an accommodation without saying why the student has it.
            'teacher_note' => "{$code} participa más en clase y avanzó en {$topic}. Todavía le cuesta "
                .'organizar sus respuestas por escrito. Con el ajuste de más tiempo en los escritos terminó '
                .'todas las actividades.',
        ];

        return "Escribí el borrador del juicio de período de este alumno (es un ejemplo para que el colegio revise su estilo):\n\n"
            .json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    protected function parse(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)) ?? $text;
        $decoded = json_decode(trim($text), true);
        $draft = is_array($decoded) ? ($decoded['draft'] ?? null) : null;

        return is_string($draft) && trim($draft) !== '' ? trim($draft) : null;
    }

    protected function markError(string $message): void
    {
        Log::warning('Judgment style preview failed', [
            'judgment_style_preview_id' => $this->preview->id,
            'reason' => $message,
        ]);

        $this->preview->update([
            'status' => JudgmentStylePreviewStatus::Error,
            'error_message' => $message,
        ]);
    }
}
