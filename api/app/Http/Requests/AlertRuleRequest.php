<?php

namespace App\Http\Requests;

use App\Enums\AlertCondition;
use App\Models\AlertRule;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update an alert condition: a sustained-low-performance one
 * (ClickUp 86e3jpzcv) or the «Señal de dificultad» one (ClickUp 86e3qkwpn).
 * On update every field is optional, but the parameters the (resulting)
 * condition needs must still be present on the rule — checked in
 * {@see self::after()} against the merged state.
 *
 * The «Señal de dificultad» condition has no product default: both
 * `signal_count` and `period_days` must be given by the school.
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
            // or 0–100, so only bound it to what the column can hold. Required
            // by the performance conditions only (see after()).
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'consecutive_count' => ['nullable', 'integer', 'min:2', 'max:10'],
            // «Señal de dificultad»: N signals (same bounds as the comment
            // trend mark's count).
            'signal_count' => ['nullable', 'integer', 'min:2', 'max:50'],
            // The performance average needs at least a week; the difficulty
            // signals period may be as short as one day (see after()).
            'period_days' => ['nullable', 'integer', 'min:1', 'max:365'],
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
            $condition = AlertCondition::tryFrom((string) $this->input('condition', $existing?->condition?->value));

            if ($condition === null) {
                return;
            }

            // Merged state: what the request sends, else what the rule has
            // (stored rules never keep a parameter their condition doesn't
            // use — see AlertSettingsController::normalized()).
            $value = fn (string $field) => $this->input($field, $existing?->{$field});

            if ($condition->isPerformance() && $value('threshold') === null) {
                $validator->errors()->add('threshold', 'The threshold is required for this condition.');
            }

            if ($condition === AlertCondition::ConsecutiveBelow && $value('consecutive_count') === null) {
                $validator->errors()->add('consecutive_count', 'The consecutive count is required for this condition.');
            }

            if ($condition === AlertCondition::AverageBelow) {
                $periodDays = $value('period_days');

                if ($periodDays === null) {
                    $validator->errors()->add('period_days', 'The period in days is required for this condition.');
                } elseif (is_numeric($periodDays) && (int) $periodDays < 7) {
                    $validator->errors()->add('period_days', 'The period must be at least 7 days for this condition.');
                }
            }

            if ($condition === AlertCondition::DifficultySignals) {
                if ($value('signal_count') === null) {
                    $validator->errors()->add('signal_count', 'The number of difficulty signals is required for this condition.');
                }

                if ($value('period_days') === null) {
                    $validator->errors()->add('period_days', 'The period in days is required for this condition.');
                }
            }
        }];
    }
}
