<?php

namespace App\Jobs;

use App\Models\Subject;
use App\Support\Pdf\BoundedPdfParser;
use App\Support\SyllabusStorage;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Smalot\PdfParser\Config;
use Throwable;

/**
 * Extracts the text of a subject's curricular program PDF (ClickUp 86e3dt6ag)
 * so the AI assistant can use it as provisional planning context until the
 * subject is in the curricular catalog. Queued: parsing a 10 MB PDF does not
 * belong in the upload request.
 *
 * Only the path it was dispatched for is processed: if the PDF was replaced
 * or removed meanwhile, the job does nothing. A PDF that can't be parsed
 * (scanned images, encrypted, too large to decode) just leaves
 * `syllabus_text` empty — the file is still stored and downloadable. Either
 * way `syllabus_text_extracted_at` is set, so the subject never stays
 * "pending" (Subject::syllabusTextStatus): failed() covers a timeout and a
 * worker killed mid-parse (re-reserved after retry_after, then failed for
 * exceeding $tries). Errors are logged by subject id only, never with the
 * document's content.
 */
class ExtractSubjectSyllabusText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Cap on stored text: plenty for a program, bounded for the prompt. */
    public const MAX_TEXT_LENGTH = 100_000;

    /**
     * Per-stream cap when inflating compressed PDF streams. The library default
     * is unlimited, so a few KB of crafted Flate data inside an otherwise valid
     * 10 MB upload could inflate to gigabytes and exhaust the worker's memory
     * (an uncatchable fatal error, not a Throwable).
     */
    public const MAX_DECODED_STREAM_BYTES = 16 * 1024 * 1024;

    /**
     * Cap on all decoded streams of one document together: the per-stream cap
     * alone doesn't bound a PDF made of many compressed streams. Past it the
     * text would be incomplete, so the extraction counts as failed.
     */
    public const MAX_DECODED_TOTAL_BYTES = 64 * 1024 * 1024;

    /** One attempt: a PDF that crashed or hung the parser once would do it again. */
    public int $tries = 1;

    /**
     * Kill a parse that hangs on a malformed PDF instead of blocking the
     * worker. Below the queue's retry_after (90 s), so a running job is never
     * handed to a second worker.
     */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public Subject $subject, public string $path) {}

    public function handle(): void
    {
        $parser = $this->parser();

        Tenancy::forSchool($this->subject->school, function () use ($parser): void {
            $subject = $this->subject->fresh();

            if ($subject === null || $subject->syllabus_path !== $this->path) {
                return;
            }

            try {
                $text = $parser->parseContent(SyllabusStorage::disk()->get($this->path) ?? '')->getText();

                if ($parser->budgetExceeded()) {
                    throw new RuntimeException('PDF decode budget exceeded.');
                }
            } catch (Throwable $e) {
                $this->logFailure($e);
                $text = '';
            }

            $text = $this->clean($text);

            $subject->forceFill([
                'syllabus_text' => $text === '' ? null : mb_substr($text, 0, self::MAX_TEXT_LENGTH),
                'syllabus_text_extracted_at' => now(),
            ])->save();
        });
    }

    /**
     * The job died without finishing (timeout, or the worker was killed, e.g.
     * out of memory): settle the status as failed instead of pending forever.
     */
    public function failed(?Throwable $e): void
    {
        $this->logFailure($e);

        Tenancy::forSchool($this->subject->school_id, function (): void {
            $subject = $this->subject->fresh();

            if ($subject === null || $subject->syllabus_path !== $this->path || $subject->syllabus_text_extracted_at !== null) {
                return;
            }

            $subject->forceFill([
                'syllabus_text' => null,
                'syllabus_text_extracted_at' => now(),
            ])->save();
        });
    }

    protected function logFailure(?Throwable $e): void
    {
        Log::warning('subject.syllabus.extraction_failed', [
            'subject_id' => $this->subject->id,
            'error' => $e === null ? null : $e::class,
        ]);
    }

    /**
     * Valid UTF-8 without control characters: the parser passes NUL bytes
     * through from the PDF (e.g. a `\000` escape), and PostgreSQL rejects NUL
     * in a text column — the save would throw and the job would fail.
     */
    protected function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', ' ', $text);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $text));
    }

    /** A parser hardened against oversized content (see MAX_DECODED_*_BYTES). */
    protected function parser(): BoundedPdfParser
    {
        $config = new Config;
        // Images carry no text; dropping their raw data keeps memory bounded.
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(self::MAX_DECODED_STREAM_BYTES);

        return new BoundedPdfParser($config, self::MAX_DECODED_TOTAL_BYTES);
    }
}
