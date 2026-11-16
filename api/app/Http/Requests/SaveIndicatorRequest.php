<?php

namespace App\Http\Requests;

use App\Enums\CrossingOperator;
use App\Enums\IndicatorCalculation;
use App\Enums\IndicatorDisplayPlace;
use App\Enums\IndicatorSourceType;
use App\Enums\ZScorePopulation;
use App\Http\Requests\Concerns\ValidatesScreeningBands;
use App\Models\Crossing;
use App\Models\Indicator;
use App\Services\Indicators\IndicatorData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating, editing or previewing an indicator (P30-IND-03): name, what is
 * calculated, with which data, the bands with their mandatory meaning (same
 * rules as a screening design: 2 to 5, increasing), where it shows and
 * whether teachers see it. Authorization delegates to IndicatorPolicy.
 *
 * Checked here as well: each datum exists in this school; the number of data
 * matches the calculation; an average or difference never mixes a bounded
 * scale with an unbounded one; and an indicator never uses itself, directly
 * or through another indicator.
 */
class SaveIndicatorRequest extends FormRequest
{
    use ValidatesScreeningBands;

    public function authorize(): bool
    {
        $indicator = $this->route('indicator');

        return $indicator instanceof Indicator
            ? $this->user()->can('update', $indicator)
            : $this->user()->can('create', Indicator::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->is('*/indicators/preview') ? 'nullable' : 'required', 'string', 'max:120'],
            'calculation' => ['required', Rule::enum(IndicatorCalculation::class)],
            'inputs' => ['required', 'array', 'min:1', 'max:6'],
            'inputs.*' => ['required', 'array:type,id'],
            'inputs.*.type' => ['required', 'string', Rule::in(IndicatorSourceType::indicatorInputValues())],
            'inputs.*.id' => ['required', 'integer'],
            'base_100' => ['sometimes', 'boolean'],
            'z_population' => ['nullable', Rule::enum(ZScorePopulation::class)],
            ...$this->bandRules(),
            // A crossing names the band it reads: two bands with the same
            // name would make it ambiguous.
            'bands.*.name' => ['required', 'string', 'max:60', 'distinct'],
            'display_places' => ['sometimes', 'array'],
            'display_places.*' => ['distinct', Rule::enum(IndicatorDisplayPlace::class)],
            'teacher_visible' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateBandOrder($validator),
            fn (Validator $validator) => $this->validateInputs($validator),
            fn (Validator $validator) => $this->validateBandsUsedByCrossings($validator),
        ];
    }

    /**
     * The validated indicator, normalised, ready to store or calculate.
     *
     * @return array<string, mixed>
     */
    public function indicatorData(): array
    {
        $calculation = (string) $this->validated('calculation');

        return [
            'name' => trim((string) $this->validated('name', '')),
            'calculation' => $calculation,
            'inputs' => $this->inputs(),
            'base_100' => $calculation !== IndicatorCalculation::ZScore->value && (bool) $this->validated('base_100', false),
            // Same grade + school year unless the school chose otherwise.
            'z_population' => $calculation === IndicatorCalculation::ZScore->value
                ? ($this->validated('z_population') ?? ZScorePopulation::GradeYear->value)
                : null,
            'bands' => $this->bands(),
            'display_places' => array_values($this->validated('display_places', [])),
            'teacher_visible' => (bool) $this->validated('teacher_visible', false),
        ];
    }

    /**
     * @return list<array{type: string, id: int}>
     */
    protected function inputs(): array
    {
        return array_values(array_map(static fn (array $input): array => [
            'type' => (string) $input['type'],
            'id' => (int) $input['id'],
        ], (array) $this->input('inputs', [])));
    }

    protected function validateInputs(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $calculation = IndicatorCalculation::from((string) $this->input('calculation'));
        $inputs = $this->inputs();
        [$min, $max] = $calculation->inputRange();

        if (count($inputs) < $min || count($inputs) > $max) {
            $validator->errors()->add('inputs', "This calculation takes between {$min} and {$max} data.");

            return;
        }

        if (count(array_unique(array_map(static fn (array $input): string => $input['type'].':'.$input['id'], $inputs))) !== count($inputs)) {
            $validator->errors()->add('inputs', 'The same datum is used twice.');

            return;
        }

        $data = app(IndicatorData::class);
        /** @var Indicator|null $self */
        $self = $this->route('indicator');

        foreach ($inputs as $index => $input) {
            if (! $data->exists($input['type'], $input['id'])) {
                $validator->errors()->add("inputs.{$index}.id", 'The datum does not exist.');

                continue;
            }

            if ($self !== null && $input['type'] === IndicatorSourceType::Indicator->value
                && $this->dependsOn($input['id'], $self->id)) {
                $validator->errors()->add("inputs.{$index}.id", 'An indicator cannot use itself, directly or through another indicator.');
            }
        }

        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if (in_array($calculation, [IndicatorCalculation::Average, IndicatorCalculation::Difference], true)) {
            $bounded = array_map(static fn (?array $scale): bool => $scale !== null, $data->inputScales($inputs));

            if (count(array_unique($bounded)) > 1) {
                $validator->errors()->add('inputs', 'A datum with a scale cannot be combined with one without (a Z score, a difference).');
            }
        }
    }

    /**
     * A crossing's band condition names the band it reads, so editing an
     * indicator cannot drop or rename a band a crossing uses: the crossing
     * would silently stop matching anyone. Change the crossing first.
     */
    protected function validateBandsUsedByCrossings(Validator $validator): void
    {
        /** @var Indicator|null $self */
        $self = $this->route('indicator');

        if (! $self instanceof Indicator || $validator->errors()->isNotEmpty()) {
            return;
        }

        $names = array_map(static fn (array $band): string => trim((string) ($band['name'] ?? '')), array_values((array) $this->input('bands', [])));

        $missing = Crossing::query()->get()
            ->flatMap(fn (Crossing $crossing) => collect($crossing->conditions ?? [])
                ->filter(fn (array $condition): bool => ($condition['source']['type'] ?? null) === IndicatorSourceType::Indicator->value
                    && (int) ($condition['source']['id'] ?? 0) === $self->id
                    && ($condition['operator'] ?? null) === CrossingOperator::Band->value)
                ->map(fn (array $condition): string => (string) $condition['value']))
            ->unique()
            ->reject(fn (string $band): bool => in_array($band, $names, true));

        if ($missing->isNotEmpty()) {
            $validator->errors()->add('bands', 'A band used by a crossing cannot be removed or renamed: '.$missing->implode(', ').'.');
        }
    }

    /**
     * Whether indicator $id uses indicator $target, directly or transitively.
     */
    protected function dependsOn(int $id, int $target, int $depth = 0): bool
    {
        if ($id === $target) {
            return true;
        }

        if ($depth > 10) {
            return true;
        }

        $inputs = Indicator::query()->find($id)?->inputs ?? [];

        foreach ($inputs as $input) {
            if (($input['type'] ?? null) === IndicatorSourceType::Indicator->value
                && $this->dependsOn((int) $input['id'], $target, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
