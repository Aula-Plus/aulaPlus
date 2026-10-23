<?php

namespace App\Http\Requests;

use App\Enums\ReportVisibility;
use App\Models\ReportDocument;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload a student report (ClickUp 86e3jujgx): one PDF or Word (.docx) file, at
 * most 10 MB, checked by extension and by detected MIME type. Only
 * psychopedagogy (ReportDocumentPolicy::create). Visibility defaults to
 * clinical; `use_ai` lets psychopedagogy skip the AI for this report.
 */
class UploadReportDocumentRequest extends FormRequest
{
    public const MAX_KILOBYTES = 10 * 1024;

    public function authorize(): bool
    {
        /** @var Student $student */
        $student = $this->route('student');

        return $this->user()->can('create', [ReportDocument::class, $student]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file', 'mimes:pdf,docx',
                'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip',
                'max:'.self::MAX_KILOBYTES,
            ],
            'visibility' => ['sometimes', Rule::in(ReportVisibility::values())],
            'use_ai' => ['sometimes', 'boolean'],
        ];
    }
}
