<?php

namespace App\Jobs;

use App\Actions\Groups\BuildGroupAccommodationSummary;
use App\Enums\AccommodationNarrativeStatus;
use App\Models\GroupAccommodationNarrative;
use App\Support\AiProvider;
use App\Support\StudentNameMatcher;
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
 * `type` is free text typed by staff, so each one is checked before sending:
 * if any names a student of the group (even a single name part) nothing is
 * sent, and the offending types are stored redacted so the UI can say which
 * one to fix. The answer is checked again before being stored and discarded
 * if it contains a student's full name — the model never saw a name, so a
 * single common word ("de forma clara") is not treated as one. Logs carry
 * only the narrative id, never the context or the text (rules 2 and 11).
 *
 * Timing: one attempt only ($tries = 1; the HTTP client already retries).
 * The HTTP budget (2 × 30 s + 1 s backoff) fits in $timeout, which in turn
 * stays below the queue's `retry_after` (90 s, config/queue.php), so a slow
 * run is never handed to a second worker while the first is still calling
 * the API. On timeout the job fails and failed() records the error.
 */
class GenerateGroupAccommodationNarrativeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    protected const ANTHROPIC_VERSION = '2023-06-01';

    protected const MAX_HTTP_ATTEMPTS = 2;

    protected const RETRY_BACKOFF_MS = 1000;

    protected const HTTP_TIMEOUT_SECONDS = 30;

    protected const MAX_TOKENS = 1024;

    /** Stored when the accommodation data itself names a student (nothing is sent). */
    public const ERROR_NAME_IN_DATA = 'Accommodation data names a student; nothing was sent to the AI.';

    public const ERROR_NO_ACCOMMODATIONS = 'The group has no active accommodations.';

    /** Never retried by the queue: a retry would pay for a second AI call. */
    public int $tries = 1;

    /** Seconds; above the HTTP budget, below the queue's `retry_after` (90). */
    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(public GroupAccommodationNarrative $narrative) {}

    public function handle(BuildGroupAccommodationSummary $summarize): void
    {
        Tenancy::forSchool($this->narrative->school_id, function () use ($summarize): void {
            // A pending row given up as stale (and replaced by a newer
            // generation) must not still reach the AI if a worker picks it up late.
            if ($this->narrative->refresh()->status !== AccommodationNarrativeStatus::Pending) {
                return;
            }

            // Queued before the provider was removed: record the error
            // without calling the API, so the UI stops waiting.
            if (! AiProvider::isConfigured()) {
                $this->markError(AiProvider::NOT_CONFIGURED);

                return;
            }

            $group = $this->narrative->group;
            $summary = $summarize($group);

            // Accommodations may have ended since the request was accepted: an
            // empty list would only invite the model to invent content.
            if ($summary === []) {
                $this->markError(self::ERROR_NO_ACCOMMODATIONS);

                return;
            }

            $names = StudentNameMatcher::forGroup($group);

            // Free-text accommodation types could carry a name: never let it
            // reach the third-party API (rule 11). Each type is checked on its
            // own so the UI can point at the one to fix (stored redacted).
            $flagged = collect($summary)
                ->pluck('type')
                ->filter(fn (string $type): bool => $names->containsAnyName($type))
                ->map(fn (string $type): string => $names->redact($type))
                ->values()
                ->all();

            if ($flagged !== []) {
                $this->markError(self::ERROR_NAME_IN_DATA, $flagged);

                return;
            }

            // The group name is not a student's: only a full name is a red
            // flag there ("San José" with a student José is fine). If it does
            // carry one, the group name is simply left out of the prompt.
            $prompt = $this->userPrompt($names->containsFullName($group->name) ? null : $group->name, $summary);

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

            if ($names->containsFullName($text)) {
                $this->markError('AI response named a student and was discarded.');

                return;
            }

            // Given up as stale while the AI was answering: keep it that way.
            if ($this->narrative->fresh()?->status !== AccommodationNarrativeStatus::Pending) {
                return;
            }

            $this->narrative->update([
                'content' => $text,
                'status' => AccommodationNarrativeStatus::Draft,
                'error_message' => null,
                // The data actually summarised (it may differ from what the
                // request saw), so `outdated` compares against the right thing.
                'fingerprint' => $summarize->fingerprint($summary),
            ]);
        });
    }

    /**
     * Uncaught failure (worker crash, DB error…): never leave the narrative
     * stuck in `pending`, which the UI would poll forever.
     */
    public function failed(?Throwable $exception): void
    {
        Tenancy::forSchool($this->narrative->school_id, function (): void {
            if ($this->narrative->refresh()->status === AccommodationNarrativeStatus::Pending) {
                $this->markError('Generation failed unexpectedly.');
            }
        });
    }

    /**
     * @param  list<string>|null  $flaggedTypes  redacted accommodation types that name a student
     */
    protected function markError(string $message, ?array $flaggedTypes = null): void
    {
        Log::warning('Group accommodation narrative generation failed', [
            'narrative_id' => $this->narrative->id,
            'reason' => $message,
        ]);

        $this->narrative->update([
            'status' => AccommodationNarrativeStatus::Error,
            'error_message' => $message,
            'flagged_types' => $flaggedTypes,
        ]);
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
    protected function userPrompt(?string $groupName, array $summary): string
    {
        $lines = array_map(
            fn (array $entry): string => sprintf('%s, %s, %d alumnos', $entry['type'], $entry['category'] ?? 'sin categoría', $entry['student_count']),
            $summary,
        );

        $header = $groupName !== null ? "Grupo: {$groupName}\n\n" : '';

        return $header."AJUSTES ACTIVOS (ajuste, categoría, cantidad de alumnos):\n".implode("\n", $lines);
    }
}
