<?php

namespace App\Http\Requests;

use App\Models\ExternalMeasure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or editing a datum brought from outside (screen 30 «Datos»): its
 * name, its original scale and whether it is taken every year. Authorization
 * delegates to ExternalMeasurePolicy (Psicopedagogía and Dirección).
 */
class SaveExternalMeasureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $measure = $this->route('externalMeasure');

        return $measure instanceof ExternalMeasure
            ? $this->user()->can('update', $measure)
            : $this->user()->can('create', ExternalMeasure::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->route('externalMeasure') ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'scale_min' => [$required, 'numeric', 'min:-100000', 'max:100000'],
            'scale_max' => [$required, 'numeric', 'min:-100000', 'max:100000', 'gt:scale_min'],
            'taken_every_year' => ['sometimes', 'boolean'],
        ];
    }
}
