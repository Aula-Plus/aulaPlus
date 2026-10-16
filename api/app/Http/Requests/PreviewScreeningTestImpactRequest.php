<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesScreeningBands;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /screening-test-types/{type}/impact — "Ver cómo queda el
 * grupo con este corte" before saving. Same band rules as saving a design and
 * the same people may ask for it (whoever may author a design).
 */
class PreviewScreeningTestImpactRequest extends FormRequest
{
    use ValidatesScreeningBands;

    public function authorize(): bool
    {
        /** @var ScreeningTestType $type */
        $type = $this->route('type');

        return $this->user()->can('create', [ScreeningTestDesign::class, $type]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->bandRules();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBandOrder($validator)];
    }
}
