<?php

namespace App\Jobs;

use App\Models\Subject;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extracts the text of a subject's curricular program PDF (ClickUp 86e3dt6ag)
 * so the AI assistant can use it as provisional planning context until the
 * subject is in the curricular catalog. Queued: parsing a 10 MB PDF does not
 * belong in the upload request.
 *
 * Only the path it was dispatched for is processed: if the PDF was replaced
 * or removed meanwhile, the job does nothing. A PDF that can't be parsed
 * (scanned images, encrypted) just leaves `syllabus_text` empty — the file is
 * still stored and downloadable. Errors are logged by subject id only, never
 * with the document's content.
 */
class ExtractSubjectSyllabusText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Cap on stored text: plenty for a program, bounded for the prompt. */
    public const MAX_TEXT_LENGTH = 100_000;

    public function __construct(public Subject $subject, public string $path) {}

    public function handle(Parser $parser): void
    {
        Tenancy::forSchool($this->subject->school, function () use ($parser): void {
            $subject = $this->subject->fresh();

            if ($subject === null || $subject->syllabus_path !== $this->path) {
                return;
            }

            try {
                $contents = Storage::disk(config('filesystems.default'))->get($this->path);
                $text = $contents === null ? '' : $parser->parseContent($contents)->getText();
            } catch (Throwable $e) {
                Log::warning('subject.syllabus.extraction_failed', [
                    'subject_id' => $subject->id,
                    'error' => $e::class,
                ]);
                $text = '';
            }

            $text = trim((string) preg_replace('/[ \t]+/u', ' ', $text));

            $subject->forceFill([
                'syllabus_text' => $text === '' ? null : mb_substr($text, 0, self::MAX_TEXT_LENGTH),
                'syllabus_text_extracted_at' => now(),
            ])->save();
        });
    }
}
