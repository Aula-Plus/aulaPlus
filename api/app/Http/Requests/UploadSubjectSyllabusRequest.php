<?php

namespace App\Http\Requests;

use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload (or replace) a subject's curricular program (ClickUp 86e3dt6ag): one
 * PDF, at most 10 MB. Checked both by extension and by detected MIME type.
 */
class UploadSubjectSyllabusRequest extends FormRequest
{
    public const MAX_KILOBYTES = 10 * 1024;

    public function authorize(): bool
    {
        /** @var Subject $subject */
        $subject = $this->route('subject');

        return $this->user()->can('manageSyllabus', $subject);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.self::MAX_KILOBYTES],
        ];
    }
}
