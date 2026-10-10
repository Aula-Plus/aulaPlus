<?php

namespace App\Http\Controllers;

use App\Contracts\StatusChangeNotifier;
use App\Http\Requests\UploadSubjectSyllabusRequest;
use App\Http\Resources\SubjectResource;
use App\Jobs\ExtractSubjectSyllabusText;
use App\Models\Subject;
use App\Support\SyllabusStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A subject's curricular program PDF (ClickUp 86e3dt6ag). Stored on the
 * private `syllabi` disk (SyllabusStorage) under a random name — never a
 * public URL (CLAUDE.md rule 7); reading it always goes through a short-lived
 * temporary URL.
 *
 * Direction uploads, replaces and removes it (SubjectPolicy::manageSyllabus);
 * direction, psychopedagogy and the subject's teachers can open it
 * (SubjectPolicy::viewSyllabus).
 */
class SubjectSyllabusController extends Controller
{
    /** Lifetime of the temporary download URL. */
    protected const URL_TTL_MINUTES = 5;

    public function store(UploadSubjectSyllabusRequest $request, Subject $subject, StatusChangeNotifier $notifier): JsonResponse|SubjectResource
    {
        if (! SyllabusStorage::isDurable()) {
            // Misconfiguration, not a user error: no details beyond the driver.
            Log::error('subject.syllabus.storage_not_durable', ['disk_driver' => 'local']);

            return response()->json([
                'message' => 'Por ahora no se pueden subir programas: falta configurar el almacenamiento de archivos. Avisale al equipo de Aula+.',
            ], 503);
        }

        $disk = SyllabusStorage::disk();
        $file = $request->file('file');

        // Upload before taking the row lock: a 10 MB transfer must not hold it.
        $path = $file->storeAs(
            "schools/{$subject->school_id}/syllabi",
            Str::uuid().'.pdf',
            ['disk' => SyllabusStorage::DISK, 'visibility' => 'private'],
        );

        try {
            // Locked read of the current path: two concurrent replaces each
            // delete the file the other one replaced, never orphaning one.
            $previous = DB::transaction(function () use ($subject, $file, $path, $request): ?string {
                $locked = Subject::query()->lockForUpdate()->findOrFail($subject->id);
                $previous = $locked->syllabus_path;

                $locked->forceFill([
                    'syllabus_path' => $path,
                    'syllabus_original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'syllabus_size' => $file->getSize(),
                    'syllabus_uploaded_at' => now(),
                    'syllabus_uploaded_by_id' => $request->user()->id,
                    'syllabus_text' => null,
                    'syllabus_text_extracted_at' => null,
                ])->save();

                return $previous;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        if ($previous !== null && $previous !== $path) {
            $disk->delete($previous);
        }

        ExtractSubjectSyllabusText::dispatch($subject, $path);

        // "Avisanos cuando se suba uno": the Aula+ team loads the program into
        // the curricular catalog. Ids only — never the file or its contents.
        $notifier->notify('subject.syllabus_uploaded', [
            'school_id' => $subject->school_id,
            'subject_id' => $subject->id,
            'in_catalog' => $subject->curricular_item_id !== null,
        ]);

        // Usually `text_status: pending` — the extraction is queued; the SPA
        // polls the subject until it settles.
        return new SubjectResource($subject->refresh());
    }

    public function show(Subject $subject): JsonResponse
    {
        $this->authorize('viewSyllabus', $subject);

        abort_unless($subject->hasSyllabus(), 404);

        $url = SyllabusStorage::disk()->temporaryUrl(
            $subject->syllabus_path,
            now()->addMinutes(self::URL_TTL_MINUTES),
            ['ResponseContentType' => 'application/pdf'],
        );

        return response()->json(['data' => [
            'url' => $url,
            'name' => $subject->syllabus_original_name,
            'expires_in_minutes' => self::URL_TTL_MINUTES,
        ]]);
    }

    public function destroy(Subject $subject): SubjectResource
    {
        $this->authorize('manageSyllabus', $subject);

        $previous = DB::transaction(function () use ($subject): ?string {
            $locked = Subject::query()->lockForUpdate()->findOrFail($subject->id);
            $previous = $locked->syllabus_path;

            $locked->forceFill([
                'syllabus_path' => null,
                'syllabus_original_name' => null,
                'syllabus_size' => null,
                'syllabus_uploaded_at' => null,
                'syllabus_uploaded_by_id' => null,
                'syllabus_text' => null,
                'syllabus_text_extracted_at' => null,
            ])->save();

            return $previous;
        });

        if ($previous !== null) {
            SyllabusStorage::disk()->delete($previous);
        }

        return new SubjectResource($subject->refresh());
    }
}
