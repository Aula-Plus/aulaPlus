<?php

namespace App\Http\Requests;

use App\Models\ScreeningTestType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /screening-test-types. Authorization delegates to
 * ScreeningTestTypePolicy::create (psychopedagogy only) — never a parallel
 * rule.
 */
class StoreScreeningTestTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ScreeningTestType::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
