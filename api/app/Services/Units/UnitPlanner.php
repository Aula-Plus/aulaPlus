<?php

namespace App\Services\Units;

use App\Enums\PlannerFieldType;
use App\Models\Unit;
use App\Services\CurricularStructure\PlannerFields;

/**
 * The planner of a unit (screen 13 › Planificación, ClickUp 1dfd3mc4bga): the
 * fields the school's configuration resolves for the plan's group-subject
 * (screen 23 › Planificadores) with what the teacher wrote in each.
 *
 * No field is ever mandatory (P13-DEC-07): a value may be left empty, and an
 * empty value is simply not stored. `automatic` fields (resources,
 * differentiation) are filled by Aula+ and never take a typed value.
 */
class UnitPlanner
{
    public const MAX_TEXT = 5000;

    public function __construct(private readonly PlannerFields $plannerFields) {}

    /**
     * @return array{frameworks: list<array<string, mixed>>, fields: list<array<string, mixed>>}
     */
    public function resolved(Unit $unit): array
    {
        $plan = $unit->loadMissing('annualPlan.group', 'annualPlan.subject')->annualPlan;

        if ($plan->group === null || $plan->subject === null) {
            return ['frameworks' => [], 'fields' => []];
        }

        return $this->plannerFields->forGroupSubject($plan->group, $plan->subject);
    }

    /**
     * The resolved fields, each with its stored value. Values of fields that
     * are no longer part of the planner (hidden or removed by the school) are
     * kept in the row but not shown.
     *
     * @return array{frameworks: list<array<string, mixed>>, fields: list<array<string, mixed>>}
     */
    public function withValues(Unit $unit): array
    {
        $planner = $this->resolved($unit);
        $values = $unit->planner_values ?? [];

        $planner['fields'] = array_map(fn (array $field): array => [
            ...$field,
            'value' => $values[$field['key']] ?? null,
        ], $planner['fields']);

        return $planner;
    }

    /**
     * Checks one submitted value against its field. Returns the value to
     * store (null = clear it) or an error message.
     *
     * @param  array<string, mixed>  $field
     * @return array{ok: bool, value?: mixed, error?: string}
     */
    public function normalize(array $field, mixed $value): array
    {
        $type = PlannerFieldType::tryFrom((string) $field['type']);

        if ($type === null || $type === PlannerFieldType::Automatic) {
            return ['ok' => false, 'error' => 'This field is filled by Aula+.'];
        }

        if ($value === null || $value === '' || $value === []) {
            return ['ok' => true, 'value' => null];
        }

        $options = array_values((array) ($field['options'] ?? []));

        return match ($type) {
            PlannerFieldType::ShortText, PlannerFieldType::LongText => is_string($value) && mb_strlen($value) <= self::MAX_TEXT
                ? ['ok' => true, 'value' => $value]
                : ['ok' => false, 'error' => 'The value must be a text of up to '.self::MAX_TEXT.' characters.'],
            PlannerFieldType::Number => is_numeric($value) && (float) $value >= 0 && (float) $value <= 10000
                ? ['ok' => true, 'value' => $value + 0]
                : ['ok' => false, 'error' => 'The value must be a number between 0 and 10000.'],
            PlannerFieldType::List => is_string($value) && in_array($value, $options, true)
                ? ['ok' => true, 'value' => $value]
                : ['ok' => false, 'error' => 'The value must be one of the field options.'],
            PlannerFieldType::MultipleChoice => is_array($value) && array_is_list($value)
                && collect($value)->every(fn ($option) => is_string($option) && in_array($option, $options, true))
                ? ['ok' => true, 'value' => array_values(array_unique($value))]
                : ['ok' => false, 'error' => 'Every value must be one of the field options.'],
        };
    }
}
