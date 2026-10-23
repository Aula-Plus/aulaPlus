<?php

namespace App\Http\Requests;

use App\Models\ScreeningTestType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /screening-test-types/{type} (screen 08 "Datos generales").
 * Renaming keeps the same record, so every application and result stays
 * linked and the audit log keeps the old name: the history is not lost.
 * Authorization delegates to ScreeningTestTypePolicy::update.
 */
class UpdateScreeningTestTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ScreeningTestType $type */
        $type = $this->route('type');

        return $this->user()->can('update', $type);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'area' => ['sometimes', 'nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            ...StoreScreeningTestTypeRequest::generalDataRules(),
        ];
    }
}
