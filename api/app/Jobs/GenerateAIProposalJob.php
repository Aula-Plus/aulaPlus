<?php

namespace App\Jobs;

use App\Actions\AI\BuildProposalContext;
use App\Enums\AIProposalStatus;
use App\Enums\AIProposalType;
use App\Models\AIProposal;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generates an AIProposal draft by calling Anthropic's Messages API
 * (docs/prompts/05-asistente-ia-docente.md §1, §5, §6). Always runs on the
 * queue — never synchronously in the request.
 *
 * Tenancy: a queued job has no authenticated user, so BelongsToSchool/
 * SchoolScope can't infer the tenant. The whole body is wrapped in
 * Tenancy::forSchool() using the proposal's own school — same pattern as
 * App\Console\Commands\GenerateAlerts. (AppServiceProvider::boot also resets
 * tenancy around every job as a safety net.)
 *
 * Errors never crash the job into an uncontrolled state: a network/timeout
 * failure is retried (HTTP-client level) up to MAX_HTTP_ATTEMPTS, and any
 * remaining failure — unreachable API, non-2xx status, unparseable/off-schema
 * JSON — is caught and recorded as `status = error`. Logs carry only the
 * proposal id, never the context sent (CLAUDE.md rules 2 and 11).
 */
class GenerateAIProposalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Anthropic Messages API endpoint. */
    protected const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    protected const ANTHROPIC_VERSION = '2023-06-01';

    /** Total HTTP attempts (1 initial + up to 2 retries) for network/timeout. */
    protected const MAX_HTTP_ATTEMPTS = 3;

    /** Simple fixed backoff between HTTP retries, in milliseconds. */
    protected const RETRY_BACKOFF_MS = 1000;

    /** Per-request HTTP timeout in seconds (spec §6). */
    protected const HTTP_TIMEOUT_SECONDS = 60;

    protected const MAX_TOKENS = 4096;

    public function __construct(public AIProposal $proposal) {}

    public function handle(BuildProposalContext $buildContext): void
    {
        Tenancy::forSchool($this->proposal->school, function () use ($buildContext): void {
            $context = $buildContext($this->proposal);
            $this->proposal->update(['context_sent' => $context]);

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
                        'system' => $this->systemPrompt($this->proposal->type),
                        'messages' => [[
                            'role' => 'user',
                            'content' => $this->userPrompt($context, $this->proposal->input_parameters ?? []),
                        ]],
                    ]);
            } catch (ConnectionException $e) {
                $this->markError('Could not reach the AI provider after retries.');

                return;
            }

            if ($response->failed()) {
                $this->markError("AI provider returned HTTP {$response->status()}.");

                return;
            }

            $parsed = $this->parse($response->json('content.0.text'), $this->proposal->type);

            if ($parsed === null) {
                $this->markError('AI response was not valid JSON matching the expected schema.');

                return;
            }

            $this->proposal->update([
                'raw_response' => $parsed,
                'status' => AIProposalStatus::Completed,
                'error_message' => null,
            ]);
        });
    }

    protected function markError(string $message): void
    {
        Log::warning('AIProposal generation failed', [
            'ai_proposal_id' => $this->proposal->id,
            'reason' => $message,
        ]);

        $this->proposal->update([
            'status' => AIProposalStatus::Error,
            'error_message' => $message,
        ]);
    }

    /**
     * Decode the model's text output and check it carries the structural keys
     * the applier will rely on. Returns null on any failure (unparseable JSON
     * or a shape that doesn't match the type's schema) — never throws.
     *
     * @return array<string, mixed>|null
     */
    protected function parse(?string $text, AIProposalType $type): ?array
    {
        if ($text === null) {
            return null;
        }

        // Tolerate a ```json fenced block around the JSON.
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;

        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded)) {
            return null;
        }

        return $this->matchesSchema($decoded, $type) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    protected function matchesSchema(array $decoded, AIProposalType $type): bool
    {
        return match ($type) {
            AIProposalType::AnnualPlan => isset($decoded['description']) && is_string($decoded['description']),
            AIProposalType::Unit => isset($decoded['name']) && isset($decoded['sessions']) && is_array($decoded['sessions']),
            AIProposalType::ClassSession => isset($decoded['title']),
            AIProposalType::Assessment => isset($decoded['purpose']),
        };
    }

    /**
     * Fixed system instruction: the DUA/UDL principles never vary per request
     * (spec §4), followed by the exact output schema for this type. The model
     * is told to answer with JSON only.
     */
    protected function systemPrompt(AIProposalType $type): string
    {
        return <<<PROMPT
        Sos un asistente pedagógico para docentes de un colegio uruguayo. Diseñás
        propuestas de planificación alineadas al Diseño Universal para el Aprendizaje (DUA/UDL):

        - Múltiples formas de representación de los contenidos.
        - Múltiples formas de acción y expresión por parte de los alumnos.
        - Múltiples formas de implicación y motivación.

        Tené en cuenta el resumen agregado del grupo (accommodations activas, acompañamiento
        terapéutico) para proponer estrategias inclusivas, sin asumir datos que no se te dieron.

        Respondé ÚNICAMENTE con un objeto JSON válido, sin texto adicional, sin markdown, sin
        explicaciones. El JSON debe seguir exactamente este esquema:

        {$this->schemaFor($type)}
        PROMPT;
    }

    protected function schemaFor(AIProposalType $type): string
    {
        return match ($type) {
            AIProposalType::AnnualPlan => '{"description": string}',
            AIProposalType::Unit => '{"name": string, "suggested_position": number, "suggested_start_date": "YYYY-MM-DD", "suggested_end_date": "YYYY-MM-DD", "suggested_curricular_items": [string (code)], "sessions": [{"title": string, "objective": string, "duration_minutes": number}]}',
            AIProposalType::ClassSession => '{"title": string, "objective": string, "description": string, "duration_minutes": number, "suggested_date": "YYYY-MM-DD" | null}',
            AIProposalType::Assessment => '{"purpose": string, "duration_minutes": number, "content": object, "suggested_curricular_items": [string (code)]}',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $parameters
     */
    protected function userPrompt(array $context, array $parameters): string
    {
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $parametersJson = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
        Generá la propuesta usando este contexto del grupo y del marco curricular:

        CONTEXTO:
        {$contextJson}

        PARÁMETROS PEDIDOS POR EL DOCENTE:
        {$parametersJson}
        PROMPT;
    }
}
