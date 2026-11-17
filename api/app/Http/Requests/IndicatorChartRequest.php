<?php

namespace App\Http\Requests;

use App\Enums\IndicatorSourceType;
use App\Models\Indicator;
use App\Services\Indicators\IndicatorData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * «Gráfico de cruces» (P30-GRA-02): one datum per axis and a group or the
 * whole school. Only Psicopedagogía and Dirección (IndicatorPolicy::viewAny).
 */
class IndicatorChartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Indicator::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $types = Rule::in(IndicatorSourceType::indicatorInputValues());

        return [
            'x_type' => ['required', 'string', $types],
            'x_id' => ['required', 'integer'],
            'y_type' => ['required', 'string', $types],
            'y_id' => ['required', 'integer'],
            'group_id' => ['nullable', 'integer', Rule::exists('groups', 'id')->where('school_id', $this->user()->school_id)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = app(IndicatorData::class);

            foreach (['x', 'y'] as $axis) {
                if (! $data->exists((string) $this->input("{$axis}_type"), (int) $this->input("{$axis}_id"))) {
                    $validator->errors()->add("{$axis}_id", 'The datum does not exist.');
                }
            }
        }];
    }
}
