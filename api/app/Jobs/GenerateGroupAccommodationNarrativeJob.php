<?php

namespace App\Jobs;

use App\Actions\Groups\BuildGroupAccommodationSummary;
use App\Enums\AccommodationNarrativeStatus;
use App\Models\GroupAccommodationNarrative;
use App\Models\Student;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the plain-text "Ajustes activos" summary of a group through
 * Anthropic's Messages API. Runs on the queue, like GenerateAIProposalJob.
 *
 * The model only receives the aggregated accommodations (type, category,
 * number of students) — never a name or id of a student (CLAUDE.md rule 11).
 * `type` is free text typed by staff, so the outgoing prompt is checked too:
 * if it contains a student's name nothing is sent. The answer is checked
 * again before being stored: if it names any student of the school it is
 * discarded. Logs carry only the narrative id, never the context
 * or the text (rules 2 and 11).
 */
class GenerateGroupAccommodationNarrativeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    protected const ANTHROPIC_VERSION = '2023-06-01';

    protected const MAX_HTTP_ATTEMPTS = 3;

    protected const RETRY_BACKOFF_MS = 1000;

    protected const HTTP_TIMEOUT_SECONDS = 60;

    protected const MAX_TOKENS = 1024;

    /** Stored when the accommodation data itself names a student (nothing is sent). */
    public const ERROR_NAME_IN_DATA = 'Accommodation data names a student; nothing was sent to the AI.';

    /** Name fragments too generic to prove a student is being named. */
    protected const NAME_STOPWORDS = ['del', 'las', 'los', 'van', 'von', 'dos', 'san', 'que'];

    /** Lower-cased name parts of the school's students, loaded once per run. */
    protected ?array $studentNameParts = null;

    public function __construct(public GroupAccommodationNarrative $narrative) {}

    public function handle(BuildGroupAccommodationSummary $summarize): void
    {
        Tenancy::forSchool($this->narrative->school_id, function () use ($summarize): void {
            $group = $this->narrative->group;
            $prompt = $this->userPrompt($group->name, $summarize($group));

            // Free-text accommodation types could carry a name: never let it
            // reach the third-party API (rule 11).
            if ($this->namesAStudent($prompt)) {
                $this->markError(self::ERROR_NAME_IN_DATA);

                return;
            }

            try {
                $response = Http::withHeaders([
                    'x-api-key' => (string) config('services.anthropic.key'),
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'content-type' => 'application/json',
                ])
                    ->timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->retry(self::MAX_HTTP_ATTEMPTS, self::RETRY_BACKOFF_MS, throw: false)
                    ->post(self::ENDPOINT, [
                        'model' => config('services.anthropic.model'),
                        'max_tokens' => self::MAX_TOKENS,
                        'system' => $this->systemPrompt(),
                        'messages' => [['role' => 'user', 'content' => $prompt]],
                    ]);
            } catch (ConnectionException) {
                $this->markError('Could not reach the AI provider after retries.');

                return;
            }

            if ($response->failed()) {
                $this->markError("AI provider returned HTTP {$response->status()}.");

                return;
            }

            $text = trim((string) $response->json('content.0.text'));

            if ($text === '') {
                $this->markError('AI response was empty.');

                return;
            }

            if ($this->namesAStudent($text)) {
                $this->markError('AI response named a student and was discarded.');

                return;
            }

            $this->narrative->update([
                'content' => $text,
                'status' => AccommodationNarrativeStatus::Draft,
                'error_message' => null,
            ]);
        });
    }

    /**
     * Uncaught failure (worker crash, DB error…): never leave the narrative
     * stuck in `pending`, which the UI would poll forever.
     */
    public function failed(?Throwable $exception): void
    {
        Tenancy::forSchool($this->narrative->school_id, fn () => $this->markError('Generation failed unexpectedly.'));
    }

    protected function markError(string $message): void
    {
        Log::warning('Group accommodation narrative generation failed', [
            'narrative_id' => $this->narrative->id,
            'reason' => $message,
        ]);

        $this->narrative->update([
            'status' => AccommodationNarrativeStatus::Error,
            'error_message' => $message,
        ]);
    }

    /**
     * True when the text contains the full name or any name part (3+ letters)
     * of a student of the school, as a whole word, ignoring case.
     */
    protected function namesAStudent(string $text): bool
    {
        foreach ($this->studentNameParts() as $part) {
            if (preg_match('/(?<!\pL)'.preg_quote($part, '/').'(?!\pL)/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function studentNameParts(): array
    {
        return $this->studentNameParts ??= Student::query()->pluck('full_name')
            ->flatMap(fn ($fullName) => preg_split('/\s+/u', mb_strtolower(trim((string) $fullName))) ?: [])
            ->filter(fn (string $part) => mb_strlen($part) >= 3 && ! in_array($part, self::NAME_STOPWORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
        Sos un asistente pedagógico para docentes de un colegio uruguayo. Recibís la lista de
        ajustes razonables activos de un grupo, cada uno con su categoría y la cantidad de
        alumnos que lo tienen. Escribí un resumen breve (un párrafo, máximo 120 palabras) con lo
        que el docente debería tener en cuenta al planificar una clase o una evaluación para
        este grupo, en español rioplatense.

        Reglas estrictas:
        - Nunca nombres ni identifiques a ningún alumno; hablá solo en cantidades ("3 alumnos").
        - No inventes ajustes ni datos que no se te dieron.
        - Respondé únicamente con el texto del resumen, sin títulos ni markdown.
        PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $summary
     */
    protected function userPrompt(string $groupName, array $summary): string
    {
        $lines = array_map(
            fn (array $entry): string => sprintf('%s, %s, %d alumnos', $entry['type'], $entry['category'] ?? 'sin categoría', $entry['student_count']),
            $summary,
        );

        return "Grupo: {$groupName}\n\nAJUSTES ACTIVOS (ajuste, categoría, cantidad de alumnos):\n".implode("\n", $lines);
    }
}
