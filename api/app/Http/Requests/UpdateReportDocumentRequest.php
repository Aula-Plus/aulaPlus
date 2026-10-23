<?php

namespace App\Http\Requests;

use App\Enums\ReportVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Psychopedagogy changes a report's visibility and/or whether the AI may read it.
 * Authorization happens in the controller (ReportDocumentPolicy::manage).
 */
class UpdateReportDocumentRequest extends FormRequest
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
            'visibility' => ['sometimes', Rule::in(ReportVisibility::values())],
            'use_ai' => ['sometimes', 'boolean'],
        ];
    }
}
