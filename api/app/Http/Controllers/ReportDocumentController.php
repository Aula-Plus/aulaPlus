<?php

namespace App\Http\Controllers;

use App\Enums\ReportDocumentStatus;
use App\Enums\ReportVisibility;
use App\Http\Requests\UpdateAnonymizedReportTextRequest;
use App\Http\Requests\UpdateReportDocumentRequest;
use App\Http\Requests\UploadReportDocumentRequest;
use App\Http\Resources\ReportDocumentDetailResource;
use App\Http\Resources\ReportDocumentResource;
use App\Jobs\ExtractReportDocumentText;
use App\Jobs\InterpretReportDocument;
use App\Models\ReportDocument;
use App\Models\Student;
use App\Support\Reports\AiReportSwitch;
use App\Support\Reports\ReportAnonymizer;
use App\Support\Reports\ReportStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Student reports uploaded by psychopedagogy (ClickUp 86e3jujgx). Clinical
 * data about a minor (CLAUDE.md rule 11): the file sits on the private
 * `reports` disk under a random name and is opened only through a short-lived
 * temporary URL (rule 7); the extracted text is stored encrypted and never
 * logged.
 *
 * The AI path is deliberate: upload → queued extraction + automatic
 * anonymization → psychopedagogy reviews/edits the anonymized text →
 * explicit confirmation → only then does the (anonymized) text go to the
 * provider, and only while the global brake, the school's switch and this
 * report's own choice are all on.
 */
class ReportDocumentController extends Controller
{
    /** Lifetime of the temporary download URL. */
    protected const URL_TTL_MINUTES = 5;

    /** States in which the anonymized text can still be edited and sent. */
    protected const REVIEWABLE = [
        ReportDocumentStatus::AwaitingReview,
        ReportDocumentStatus::Ready,
        ReportDocumentStatus::Error,
    ];

    public function index(Request $request, Student $student)
    {
        abort_unless($request->user()->can('viewAnyFor', [ReportDocument::class, $student]), 403);

        $reports = ReportDocument::query()->where('student_id', $student->id)->orderByDesc('id')->get()
            ->filter(fn (ReportDocument $report): bool => $request->user()->can('view', $report))
            ->values();

        return ReportDocumentResource::collection($reports);
    }

    public function store(UploadReportDocumentRequest $request, Student $student): JsonResponse
    {
        if (! ReportStorage::isDurable()) {
            // Misconfiguration, not a user error: no details beyond the driver.
            Log::error('report.storage_not_durable', ['disk_driver' => 'local']);

            return response()->json([
                'message' => 'Por ahora no se pueden subir informes: falta configurar el almacenamiento de archivos. Avisale al equipo de Aula+.',
            ], 503);
        }

        $file = $request->file('file');
        // Format by content, not by the client's name: a Word file is a zip.
        $format = str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF') ? 'pdf' : 'docx';

        $path = $file->storeAs(
            "schools/{$student->school_id}/reports",
            Str::uuid().'.'.$format,
            ['disk' => ReportStorage::DISK, 'visibility' => 'private'],
        );

        $report = ReportDocument::create([
            'student_id' => $student->id,
            'uploaded_by_id' => $request->user()->id,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'format' => $format,
            'size' => $file->getSize(),
            'visibility' => $request->validated('visibility', ReportVisibility::Clinical->value),
            'status' => ReportDocumentStatus::Processing,
            'use_ai' => $request->boolean('use_ai', true),
        ]);

        ExtractReportDocumentText::dispatch($report);

        return (new ReportDocumentResource($report))->response()->setStatusCode(201);
    }

    public function show(Request $request, ReportDocument $report): ReportDocumentDetailResource
    {
        abort_unless($request->user()->can('viewContent', $report), 403);

        return new ReportDocumentDetailResource($report);
    }

    public function download(Request $request, ReportDocument $report): JsonResponse
    {
        abort_unless($request->user()->can('view', $report), 403);

        $url = ReportStorage::disk()->temporaryUrl($report->path, now()->addMinutes(self::URL_TTL_MINUTES));

        return response()->json(['data' => [
            'url' => $url,
            'name' => $report->original_name,
            'expires_in_minutes' => self::URL_TTL_MINUTES,
        ]]);
    }

    public function update(UpdateReportDocumentRequest $request, ReportDocument $report): ReportDocumentResource
    {
        $this->authorize('manage', $report);

        $report->update($request->validated());

        return new ReportDocumentResource($report);
    }

    public function destroy(ReportDocument $report): JsonResponse
    {
        $this->authorize('manage', $report);

        $path = $report->path;
        $report->delete();
        ReportStorage::disk()->delete($path);

        return response()->json(null, 204);
    }

    /** Psychopedagogy's corrections to the anonymized text (same number of pages). */
    public function updateText(UpdateAnonymizedReportTextRequest $request, ReportDocument $report): ReportDocumentDetailResource|JsonResponse
    {
        $this->authorize('manage', $report);

        if (! in_array($report->status, self::REVIEWABLE, true) || $report->anonymized_pages === null) {
            return response()->json(['message' => 'Este informe no está en un estado en el que se pueda corregir el texto.'], 422);
        }

        $pages = array_map(fn (?string $page): string => (string) $page, $request->validated('pages'));

        if (count($pages) !== count($report->anonymized_pages)) {
            return response()->json(['message' => 'La cantidad de páginas no coincide con la del informe.'], 422);
        }

        $report->update(['anonymized_pages' => $pages]);

        return new ReportDocumentDetailResource($report);
    }

    /**
     * Psychopedagogy confirms that the anonymized text, as it now stands, may be
     * sent to the AI. Refused while the student's name (or the school's) is still
     * in the text.
     */
    public function confirmSend(Request $request, ReportDocument $report): ReportDocumentDetailResource|JsonResponse
    {
        $this->authorize('manage', $report);

        if (! AiReportSwitch::allowedFor($report, $report->school)) {
            return response()->json([
                'message' => 'La lectura con IA está apagada (para el colegio, para este informe o por el equipo de Aula+). No se envió nada.',
            ], 422);
        }

        $pages = $report->anonymized_pages;

        if (! in_array($report->status, self::REVIEWABLE, true) || $pages === null) {
            return response()->json(['message' => 'Este informe no está listo para enviarse a la IA.'], 422);
        }

        $anonymizer = ReportAnonymizer::forStudent($report->student, $report->school);

        if ($anonymizer->leaksIdentity($pages)) {
            return response()->json([
                'message' => 'Todavía aparece el nombre del alumno o del colegio en el texto. Quitalo antes de enviarlo.',
            ], 422);
        }

        $report->forceFill([
            'status' => ReportDocumentStatus::Interpreting,
            'send_confirmed_at' => now(),
            'send_confirmed_by_id' => $request->user()->id,
            'error_message' => null,
        ])->save();

        InterpretReportDocument::dispatch($report);

        // Fresh: with a synchronous queue the job has already finished.
        return new ReportDocumentDetailResource($report->fresh());
    }
}
