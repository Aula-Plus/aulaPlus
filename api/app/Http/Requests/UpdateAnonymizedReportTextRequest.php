<?php

namespace App\Http\Requests;

use App\Support\Reports\ReportTextExtractor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Psychopedagogy's corrected anonymized text, one entry per page. The number of
 * pages is checked against the report in the controller. Authorization happens
 * there too (ReportDocumentPolicy::manage).
 */
class UpdateAnonymizedReportTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'pages' => ['required', 'array', 'list', 'min:1', 'max:'.ReportTextExtractor::MAX_PAGES],
            'pages.*' => ['present', 'nullable', 'string', 'max:'.ReportTextExtractor::MAX_TEXT_LENGTH],
        ];
    }
}
