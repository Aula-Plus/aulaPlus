<?php

namespace App\Http\Requests;

use App\Enums\AlertCondition;
use App\Models\AlertRule;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a sustained-low-performance condition (ClickUp 86e3jpzcv).
 * On update every field is optional, but the parameter the (resulting)
 * condition needs must still be present on the rule — checked in
 * {@see self::after()} against the merged state.
 */
class AlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('alertRule');

        return $rule instanceof AlertRule
            ? $this->user()->can('update', $rule)
            : $this->user()->can('create', AlertRule::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'condition' => [$required, Rule::enum(AlertCondition::class)],
            // Scores are stored as decimal(5,2); the school's scale may be 1–12
            // or 0–100, so only bound it to what the column can hold.
            'threshold' => [$required, 'numeric', 'min:0', 'max:999'],
            'consecutive_count' => ['nullable', 'integer', 'min:2', 'max:10'],
            'period_days' => ['nullable', 'integer', 'min:7', 'max:365'],
            'subject_id' => [
                'nullable', 'integer',
                Rule::exists('subjects', 'id')
                    ->where('school_id', Tenancy::schoolId())
                    ->whereNull('deleted_at'),
            ],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            /** @var AlertRule|null $existing */
            $existing = $this->route('alertRule');
            $condition = $this->input('condition', $existing?->condition?->value);

            if ($condition === AlertCondition::ConsecutiveBelow->value
                && $this->input('consecutive_count', $existing?->consecutive_count) === null) {
                $validator->errors()->add('consecutive_count', 'The consecutive count is required for this condition.');
            }

            if ($condition === AlertCondition::AverageBelow->value
                && $this->input('period_days', $existing?->period_days) === null) {
                $validator->errors()->add('period_days', 'The period in days is required for this condition.');
            }
        }];
    }
}
