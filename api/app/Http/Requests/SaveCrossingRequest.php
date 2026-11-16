<?php

namespace App\Http\Requests;

use App\Enums\CrossingOperator;
use App\Enums\IndicatorSourceType;
use App\Models\Crossing;
use App\Services\Indicators\IndicatorData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating, editing or previewing a crossing (P30-CRU-02): one or two
 * conditions and the reading the school writes. Authorization delegates to
 * CrossingPolicy (Psicopedagogía and Dirección).
 *
 * Each condition reads one datum:
 * - `band`: an indicator's or screening's band, by its name;
 * - `level`: a level of progress of a subject or an assessment recorded with
 *   a Primaria scale (PO decision 6);
 * - `below` / `above`: a number, for any datum with numbers — the grade in an
 *   assessment included (PO decision 2).
 */
class SaveCrossingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $crossing = $this->route('crossing');

        return $crossing instanceof Crossing
            ? $this->user()->can('update', $crossing)
            : $this->user()->can('create', Crossing::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // The preview calibrates before saving: name and reading may be empty.
        $preview = $this->is('*/crossings/preview');

        return [
            'name' => [$preview ? 'nullable' : 'required', 'string', 'max:120'],
            'reading' => [$preview ? 'nullable' : 'required', 'string', 'max:2000'],
            'conditions' => ['required', 'array', 'min:1', 'max:2'],
            'conditions.*' => ['required', 'array:source,operator,value'],
            'conditions.*.source' => ['required', 'array:type,id'],
            'conditions.*.source.type' => ['required', 'string', Rule::in(IndicatorSourceType::values())],
            'conditions.*.source.id' => ['required', 'integer'],
            'conditions.*.operator' => ['required', Rule::enum(CrossingOperator::class)],
            'conditions.*.value' => ['required'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateConditions($validator)];
    }

    /**
     * The validated conditions, normalised.
     *
     * @return list<array{source: array{type: string, id: int}, operator: string, value: string|int|float}>
     */
    public function conditions(): array
    {
        return array_values(array_map(static function (array $condition): array {
            $operator = (string) $condition['operator'];

            return [
                'source' => ['type' => (string) $condition['source']['type'], 'id' => (int) $condition['source']['id']],
                'operator' => $operator,
                'value' => match ($operator) {
                    CrossingOperator::Band->value => (string) $condition['value'],
                    CrossingOperator::Level->value => (int) $condition['value'],
                    default => (float) $condition['value'],
                },
            ];
        }, (array) $this->input('conditions', [])));
    }

    protected function validateConditions(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $data = app(IndicatorData::class);

        foreach ((array) $this->input('conditions') as $index => $condition) {
            $type = (string) $condition['source']['type'];
            $id = (int) $condition['source']['id'];
            $value = $condition['value'];

            if (! $data->exists($type, $id)) {
                $validator->errors()->add("conditions.{$index}.source.id", 'The datum does not exist.');

                continue;
            }

            $error = match (CrossingOperator::from((string) $condition['operator'])) {
                CrossingOperator::Band => ! in_array($type, [IndicatorSourceType::Indicator->value, IndicatorSourceType::Screening->value], true)
                    ? 'Only an indicator or a screening has bands.'
                    : (! in_array((string) $value, array_column($data->bands($type, $id), 'name'), true) ? 'The band does not exist.' : null),
                CrossingOperator::Level => ! in_array($type, [IndicatorSourceType::Subject->value, IndicatorSourceType::Assessment->value], true)
                    ? 'Only grades have levels of progress.'
                    : (! in_array((int) $value, array_column($data->levels($type, $id), 'id'), true) ? 'The level does not exist.' : null),
                CrossingOperator::Below, CrossingOperator::Above => ! is_numeric($value) ? 'The value must be a number.' : null,
            };

            if ($error !== null) {
                $validator->errors()->add("conditions.{$index}.value", $error);
            }
        }
    }
}
