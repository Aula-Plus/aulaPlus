<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesScreeningBands;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /screening-test-types/{type}/designs (screen 08). Authorization
 * delegates to ScreeningTestDesignPolicy::create (Psicopedagogía or Dirección,
 * same school as the route type). `reason` is the optional "Motivo".
 */
class StoreScreeningTestDesignRequest extends FormRequest
{
    use ValidatesScreeningBands;

    public function authorize(): bool
    {
        return $this->user()->can('create', [ScreeningTestDesign::class, $this->type()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->bandRules(),
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBandOrder($validator)];
    }

    protected function type(): ScreeningTestType
    {
        /** @var ScreeningTestType */
        return $this->route('type');
    }
}
