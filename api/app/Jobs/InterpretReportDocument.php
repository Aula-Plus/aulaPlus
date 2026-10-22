<?php

namespace App\Jobs;

use App\Enums\AccommodationCategory;
use App\Enums\ProposalBasis;
use App\Enums\ProposalStatus;
use App\Enums\ReportDocumentStatus;
use App\Models\AccommodationProposal;
use App\Models\ReportDocument;
use App\Models\Subject;
use App\Support\Reports\AiReportSwitch;
use App\Support\Reports\ReportAnonymizer;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks the AI to propose accommodations from a report's anonymized text
 * (ClickUp 86e3jujgx). Runs only after psychopedagogy reviewed the anonymized
 * text and confirmed the send (`send_confirmed_at`), and only while all three
 * switches are on (AiReportSwitch) — checked again here, because the job may
 * run long after the request.
 *
 * What goes to the provider: the numbered anonymized pages and a fixed
 * instruction. No student id, name or any other record data (CLAUDE.md rule
 * 11). The proposals are stored as pending: nothing is in force until a person
 * approves each one. Logs carry the report id only, never text.
 *
 * Timing: one HTTP attempt and one queue attempt. The HTTP budget (connect +
 * response timeout) fits in $timeout, which stays below the queue's
 * `retry_after` (90 s, config/queue.php): a slow reading is never handed to a
 * second worker while the first is still waiting on the provider (that would
 * send the text twice and duplicate the proposals). A failed reading is
 * retried by psychopedagogy with «Volver a enviar», which reuses the reviewed
 * text.
 */
class InterpretReportDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    protected const ANTHROPIC_VERSION = '2023-06-01';

    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const HTTP_TIMEOUT_SECONDS = 70;

    protected const MAX_TOKENS = 4096;

    protected const MAX_PROPOSALS = 30;

    /** Stored when the text still names the student or the school (nothing is sent). */
    public const ERROR_IDENTITY_IN_TEXT = 'The text still identifies the student; nothing was sent to the AI.';

    /** The report was deleted before the job ran (e.g. removed right after uploading): nothing to do. */
    public bool $deleteWhenMissingModels = true;

    /** Never retried by the queue: a retry would send the text again. */
    public int $tries = 1;

    /** Seconds; above the HTTP budget, below the queue's `retry_after` (90). */
    public int $timeout = 80;

    public bool $failOnTimeout = true;

    public function __construct(public ReportDocument $report) {}

    public function handle(): void
    {
        Tenancy::forSchool($this->report->school, function (): void {
            $report = $this->report->fresh();

            if ($report === null || $report->status !== ReportDocumentStatus::Interpreting || $report->send_confirmed_at === null) {
                return;
            }

            if (! AiReportSwitch::allowedFor($report, $report->school)) {
                $this->markError($report, 'The AI reading is switched off.');

                return;
            }

            $pages = $report->anonymized_pages ?? [];

            // Last check before the text leaves the system (rule 11): the send
            // was confirmed on a name-free text, but the student's record may
            // have changed since (a renamed student, a corrected surname).
            if ($pages === [] || ReportAnonymizer::forStudent($report->student, $report->school)->leaksIdentity($pages)) {
                $this->markError($report, self::ERROR_IDENTITY_IN_TEXT);

                return;
            }

            // Subject names are school catalogue data, not student data.
            $subjects = Subject::query()->orderBy('name')->pluck('name', 'id')->all();

            try {
                $response = Http::withHeaders([
                    'x-api-key' => (string) config('services.anthropic.key'),
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'content-type' => 'application/json',
                ])
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->post(self::ENDPOINT, [
                        'model' => config('services.anthropic.model'),
                        'max_tokens' => self::MAX_TOKENS,
                        'system' => $this->systemPrompt(),
                        'messages' => [['role' => 'user', 'content' => $this->userPrompt($pages, $subjects)]],
                    ]);
            } catch (ConnectionException) {
                $this->markError($report, 'Could not reach the AI provider.');

                return;
            }

            if ($response->failed()) {
                $this->markError($report, "AI provider returned HTTP {$response->status()}.");

                return;
            }

            $proposals = $this->parse($response->json('content.0.text'), $pages, array_keys($subjects));

            if ($proposals === null) {
                $this->markError($report, 'AI response was not valid JSON matching the expected schema.');

                return;
            }

            // All or nothing: a half-stored reading would be duplicated by «Volver a enviar».
            DB::transaction(function () use ($proposals, $report): void {
                foreach ($proposals as $proposal) {
                    AccommodationProposal::create([
                        ...$proposal,
                        'report_document_id' => $report->id,
                        'student_id' => $report->student_id,
                        'status' => ProposalStatus::Pending,
                    ]);
                }

                $report->forceFill(['status' => ReportDocumentStatus::Proposed, 'error_message' => null])->save();
            });
        });
    }

    public function failed(?Throwable $e): void
    {
        Tenancy::forSchool($this->report->school_id, function () use ($e): void {
            $report = $this->report->fresh();

            if ($report !== null && $report->status === ReportDocumentStatus::Interpreting) {
                $this->markError($report, 'The AI reading did not finish.', $e);
            }
        });
    }

    protected function markError(ReportDocument $report, string $message, ?Throwable $e = null): void
    {
        Log::warning('report.interpretation_failed', [
            'report_document_id' => $report->id,
            'reason' => $message,
            'error' => $e === null ? null : $e::class,
        ]);

        $report->forceFill([
            'status' => ReportDocumentStatus::Error,
            'error_message' => $message,
        ])->save();
    }

    protected function systemPrompt(): string
    {
        $categories = implode(', ', AccommodationCategory::values());

        return <<<PROMPT
        Sos un asistente de psicopedagogía de un colegio. Recibís el texto anonimizado de un informe sobre un alumno, dividido en páginas numeradas, y proponés ajustes razonables para el aula. Psicopedagogía revisa cada propuesta: vos solo proponés.

        Reglas:
        - Proponé solo ajustes que el informe indique (basis "explicit") o que se deduzcan claramente de lo que describe (basis "inferred"). No inventes.
        - Cada propuesta cita la página y un fragmento textual breve del informe (copiado tal cual, hasta 300 caracteres) en el que se apoya.
        - category es una de: {$categories} (access = cómo llega la tarea al alumno; content = qué se enseña o se pide se adapta; criteria = qué se evalúa cambia).
        - type: nombre corto del ajuste. description: qué hacer en clase, en términos pedagógicos concretos. focus_area: área en la que se aplica (ej. lectura, escritura, matemática, atención).
        - No incluyas nombres de personas ni datos que identifiquen al alumno. Si el texto trae alguno por error, no lo repitas.
        - subject_ids: ids de las materias de la lista que el informe indique para ese ajuste (subjects_basis "explicit"), o que se deduzcan claramente (subjects_basis "inferred"). Si el informe no aclara materias, devolvé subject_ids [] y subjects_basis null. Usá solo ids de la lista.
        - Todo en español rioplatense neutro.

        Respondé SOLO con JSON, sin texto alrededor, con esta forma exacta:
        {"proposals":[{"type":"","category":"","description":"","focus_area":"","basis":"explicit|inferred","page":1,"fragment":"","subject_ids":[],"subjects_basis":"explicit|inferred|null"}]}
        Si el informe no permite proponer nada, devolvé {"proposals":[]}.
        PROMPT;
    }

    /**
     * @param  list<string>  $pages
     * @param  array<int, string>  $subjects  the school's subjects, id => name
     */
    protected function userPrompt(array $pages, array $subjects): string
    {
        $out = "Materias del colegio (id: nombre):\n";

        foreach ($subjects as $id => $name) {
            $out .= "{$id}: {$name}\n";
        }

        $out .= "\nInforme (texto anonimizado):\n";

        foreach ($pages as $i => $text) {
            $number = $i + 1;
            $out .= "\n=== Página {$number} ===\n{$text}\n";
        }

        return $out;
    }

    /**
     * Decodes and validates the reply; null when it is not the expected shape.
     * A page outside the report becomes null; a fragment that is not literally
     * on its page is dropped (the source is never invented).
     *
     * @param  list<string>  $pages
     * @param  list<int>  $validSubjectIds  ids the school really has; anything else is dropped
     * @return list<array<string, mixed>>|null
     */
    protected function parse(?string $text, array $pages, array $validSubjectIds = []): ?array
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)) ?? $text;
        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded) || ! isset($decoded['proposals']) || ! is_array($decoded['proposals'])) {
            return null;
        }

        $out = [];

        foreach (array_slice($decoded['proposals'], 0, self::MAX_PROPOSALS) as $item) {
            if (! is_array($item)
                || ! is_string($item['type'] ?? null) || trim($item['type']) === ''
                || ! is_string($item['description'] ?? null) || trim($item['description']) === ''
                || ! in_array($item['category'] ?? null, AccommodationCategory::values(), true)
                || ! in_array($item['basis'] ?? null, ProposalBasis::aiValues(), true)) {
                continue;
            }

            $page = is_int($item['page'] ?? null) && isset($pages[$item['page'] - 1]) ? $item['page'] : null;
            $fragment = is_string($item['fragment'] ?? null) ? trim($item['fragment']) : '';

            if ($page === null || $fragment === '' || ! $this->contains($pages[$page - 1], $fragment)) {
                $fragment = null;
            }

            $subjectIds = array_values(array_unique(array_filter(
                is_array($item['subject_ids'] ?? null) ? $item['subject_ids'] : [],
                fn ($id): bool => is_int($id) && in_array($id, $validSubjectIds, true),
            )));
            $subjectsBasis = $subjectIds !== [] && in_array($item['subjects_basis'] ?? null, ProposalBasis::aiValues(), true)
                ? $item['subjects_basis']
                : null;

            $out[] = [
                'type' => Str::limit(trim($item['type']), 250, ''),
                'category' => $item['category'],
                'description' => trim($item['description']),
                'focus_area' => is_string($item['focus_area'] ?? null) && trim($item['focus_area']) !== ''
                    ? Str::limit(trim($item['focus_area']), 250, '')
                    : null,
                'basis' => $item['basis'],
                'source_page' => $page,
                'source_fragment' => $fragment === null ? null : Str::limit($fragment, 600, ''),
                'subject_ids' => $subjectsBasis === null ? [] : $subjectIds,
                'subjects_basis' => $subjectsBasis,
            ];
        }

        return $out;
    }

    /** Whether the fragment is on the page, ignoring whitespace differences. */
    protected function contains(string $page, string $fragment): bool
    {
        $normalize = fn (string $s): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', $s));

        return str_contains($normalize($page), $normalize($fragment));
    }
}
