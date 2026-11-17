<?php

namespace App\Http\Requests;

use App\Enums\IndicatorSourceType;
use App\Http\Requests\Concerns\ValidatesScreeningBands;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use App\Services\Indicators\CrossingBandUsage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /screening-test-types/{type}/designs (screen 08). Authorization
 * delegates to ScreeningTestDesignPolicy::create (Psicopedagogía or Dirección,
 * same school as the route type). `reason` is the optional "Motivo".
 *
 * A new design cannot drop or rename a band a crossing of screen 30 uses
 * (CrossingBandUsage): the crossing would silently stop matching anyone.
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
            // A crossing names the band it reads: two bands with the same
            // name would make it ambiguous.
            'bands.*.name' => ['required', 'string', 'max:60', 'distinct'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateBandOrder($validator),
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $names = array_map(static fn (array $band): string => trim((string) ($band['name'] ?? '')), array_values((array) $this->input('bands', [])));
                CrossingBandUsage::validate($validator, IndicatorSourceType::Screening->value, $this->type()->id, $names);
            },
        ];
    }

    protected function type(): ScreeningTestType
    {
        /** @var ScreeningTestType */
        return $this->route('type');
    }
}
