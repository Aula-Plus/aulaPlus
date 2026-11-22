<?php

namespace App\Http\Requests\Materials;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Sacar del catálogo» (screen 19, P19-INS-01): Psicopedagogía or Dirección,
 * with an optional reason that is sent to the owner in their notice.
 */
class RemoveFromCatalogRequest extends FormRequest
{
    public const REASON_MAX = 500;

    public function authorize(): bool
    {
        /** @var Material $material */
        $material = $this->route('material');

        return $this->user()->can('removeFromCatalog', $material);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $reason = trim($this->input('reason'));
            $this->merge(['reason' => $reason === '' ? null : $reason]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:'.self::REASON_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.max' => 'El motivo puede tener hasta '.self::REASON_MAX.' caracteres.',
        ];
    }

    public function reason(): ?string
    {
        return $this->validated('reason');
    }
}
