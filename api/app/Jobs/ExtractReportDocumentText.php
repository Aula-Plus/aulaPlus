<?php

namespace App\Jobs;

use App\Enums\ReportDocumentStatus;
use App\Models\ReportDocument;
use App\Support\Reports\AiReportSwitch;
use App\Support\Reports\ReportAnonymizer;
use App\Support\Reports\ReportStorage;
use App\Support\Reports\ReportTextExtractor;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads an uploaded report (ClickUp 86e3jujgx): extracts its text page by page
 * and runs the automatic anonymization, then leaves it waiting for
 * psychopedagogy's review (or simply ready when the AI is not in play).
 * Nothing leaves the system here. Queued: parsing a 10 MB PDF does not belong
 * in the upload request.
 *
 * A file that can't be read (scanned images, encrypted, too large to decode)
 * ends as `extraction_failed`; the file stays stored. Errors are logged by
 * report id only, never with the document's content.
 */
class ExtractReportDocumentText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One attempt: a file that crashed or hung the parser once would do it again. */
    public int $tries = 1;

    /** Below the queue's retry_after, so a running job is never handed to a second worker. */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public ReportDocument $report) {}

    public function handle(ReportTextExtractor $extractor): void
    {
        Tenancy::forSchool($this->report->school, function () use ($extractor): void {
            $report = $this->report->fresh();

            if ($report === null || $report->status !== ReportDocumentStatus::Processing) {
                return;
            }

            try {
                $pages = $extractor->extract(ReportStorage::disk()->get($report->path) ?? '', $report->format);
            } catch (Throwable $e) {
                $this->logFailure($e);
                $pages = [];
            }

            if ($pages === []) {
                $report->forceFill([
                    'status' => ReportDocumentStatus::ExtractionFailed,
                    'extracted_at' => now(),
                ])->save();

                return;
            }

            $anonymizer = ReportAnonymizer::forStudent($report->student, $report->student->school);

            $report->forceFill([
                'pages' => $pages,
                'anonymized_pages' => $anonymizer->anonymizePages($pages),
                'extracted_at' => now(),
                'status' => AiReportSwitch::allowedFor($report, $report->student->school)
                    ? ReportDocumentStatus::AwaitingReview
                    : ReportDocumentStatus::Ready,
            ])->save();
        });
    }

    /** The job died without finishing (timeout, killed worker): settle as failed, not "processing" forever. */
    public function failed(?Throwable $e): void
    {
        $this->logFailure($e);

        Tenancy::forSchool($this->report->school_id, function (): void {
            $report = $this->report->fresh();

            if ($report !== null && $report->status === ReportDocumentStatus::Processing) {
                $report->forceFill([
                    'status' => ReportDocumentStatus::ExtractionFailed,
                    'extracted_at' => now(),
                ])->save();
            }
        });
    }

    protected function logFailure(?Throwable $e): void
    {
        Log::warning('report.extraction_failed', [
            'report_document_id' => $this->report->id,
            'error' => $e === null ? null : $e::class,
        ]);
    }
}
