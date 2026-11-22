<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * «Solicitar corrección» of the ANEP regime (screen 29): only direction, a
 * short free text of what does not match. The form warns not to include
 * student data; the text is never logged.
 */
class RequestRegimeCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewOnboarding', $this->user()->school);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:5', 'max:3000'],
        ];
    }
}
