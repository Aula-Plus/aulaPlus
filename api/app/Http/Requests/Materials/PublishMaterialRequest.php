<?php

namespace App\Http\Requests\Materials;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Proponer al catálogo» for an uploaded file (screen 19, PA-109): only its
 * owner, and only after confirming «Revisé que no tenga datos de alumnos».
 */
class PublishMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Material $material */
        $material = $this->route('material');

        return $this->user()->can('publish', $material);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'confirm_no_student_data' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_no_student_data.accepted' => 'Para proponerlo al catálogo, confirmá que revisaste que no tenga datos de alumnos.',
        ];
    }
}
