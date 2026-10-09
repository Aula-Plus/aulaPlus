<?php

namespace App\Http\Controllers;

use App\Contracts\StatusChangeNotifier;
use App\Http\Requests\UploadSubjectSyllabusRequest;
use App\Http\Resources\SubjectResource;
use App\Jobs\ExtractSubjectSyllabusText;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A subject's curricular program PDF (ClickUp 86e3dt6ag). Stored on the
 * default (private) disk under a random name — never a public URL (CLAUDE.md
 * rule 7); reading it always goes through a short-lived temporary URL.
 *
 * Direction uploads, replaces and removes it (SubjectPolicy::manageSyllabus);
 * direction, psychopedagogy and the subject's teachers can open it
 * (SubjectPolicy::viewSyllabus).
 */
class SubjectSyllabusController extends Controller
{
    /** Lifetime of the temporary download URL. */
    protected const URL_TTL_MINUTES = 5;

    public function store(UploadSubjectSyllabusRequest $request, Subject $subject, StatusChangeNotifier $notifier): SubjectResource
    {
        $disk = Storage::disk(config('filesystems.default'));
        $previous = $subject->syllabus_path;

        $file = $request->file('file');
        $path = $file->storeAs(
            "schools/{$subject->school_id}/syllabi",
            Str::uuid().'.pdf',
            ['disk' => config('filesystems.default'), 'visibility' => 'private'],
        );

        $subject->forceFill([
            'syllabus_path' => $path,
            'syllabus_original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'syllabus_size' => $file->getSize(),
            'syllabus_uploaded_at' => now(),
            'syllabus_uploaded_by_id' => $request->user()->id,
            'syllabus_text' => null,
            'syllabus_text_extracted_at' => null,
        ])->save();

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

        return new SubjectResource($subject->fresh());
    }

    public function show(Subject $subject): JsonResponse
    {
        $this->authorize('viewSyllabus', $subject);

        abort_unless($subject->hasSyllabus(), 404);

        $url = Storage::disk(config('filesystems.default'))->temporaryUrl(
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

        if ($subject->hasSyllabus()) {
            Storage::disk(config('filesystems.default'))->delete($subject->syllabus_path);
        }

        $subject->forceFill([
            'syllabus_path' => null,
            'syllabus_original_name' => null,
            'syllabus_size' => null,
            'syllabus_uploaded_at' => null,
            'syllabus_uploaded_by_id' => null,
            'syllabus_text' => null,
            'syllabus_text_extracted_at' => null,
        ])->save();

        return new SubjectResource($subject->fresh());
    }
}
