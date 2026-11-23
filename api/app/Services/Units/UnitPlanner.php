<?php

namespace App\Services\Units;

use App\Enums\PlannerFieldType;
use App\Enums\ResourceVisibility;
use App\Models\GroupAddedResource;
use App\Models\GroupResourceExclusion;
use App\Models\SchoolResource;
use App\Models\Unit;
use App\Services\CurricularStructure\PlannerFields;

/**
 * The planner of a unit (screen 13 › Planificación, ClickUp 1dfd3mc4bga): the
 * fields the school's configuration resolves for the plan's group-subject
 * (screen 23 › Planificadores) with what the teacher wrote in each.
 *
 * No field is ever mandatory (P13-DEC-07): a value may be left empty, and an
 * empty value is simply not stored. `automatic` fields (resources,
 * differentiation) are filled by Aula+ and never take a typed value; the only
 * thing the teacher stores in one is which of the group's resources they do
 * not use in this unit (`resources`, PA-118).
 */
class UnitPlanner
{
    public const MAX_TEXT = 5000;

    /** The automatic field that lists the group's available resources. */
    public const RESOURCES_KEY = 'resources';

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

        $resources = $this->resources($unit);
        $planner['fields'] = array_map(fn (array $field): array => [
            ...$field,
            'value' => $field['key'] === self::RESOURCES_KEY
                ? $this->stillOffered($values[$field['key']] ?? null, $resources)
                : ($values[$field['key']] ?? null),
        ], $planner['fields']);
        $planner['resources'] = $resources;

        return $planner;
    }

    /**
     * «Recursos disponibles» for the unit (ClickUp 1dfd3mc4bga, answer of
     * 2026-11-21, PA-118): the same list as the group's card «Del colegio y
     * del grupo» (screen 23, ClickUp 1dfd3mc4bh4), seen as the plan's teacher
     * sees it — the school's resources the group has, the whole-group ones
     * and that teacher's own, never another teacher's own
     * (GroupAddedResourcePolicy::view). Whoever can open the unit (its
     * teacher, Psicopedagogía, Dirección) may see all of these. Each one is
     * keyed `school:<id>` or `group:<id>`; the planner's `resources` value
     * holds the keys the teacher unchecked for this unit.
     *
     * @return list<array{key: string, name: string, source: string}>
     */
    public function resources(Unit $unit): array
    {
        $plan = $unit->loadMissing('annualPlan')->annualPlan;
        if ($plan->group_id === null) {
            return [];
        }

        // Explicit school_id on top of SchoolScope (defence in depth).
        $excluded = GroupResourceExclusion::query()->where('group_id', $plan->group_id)->pluck('school_resource_id');
        $school = SchoolResource::query()->where('school_id', $plan->school_id)->whereNotIn('id', $excluded)->orderBy('position')->orderBy('id')->get()
            ->map(fn (SchoolResource $resource): array => [
                'key' => 'school:'.$resource->id,
                'name' => $resource->name,
                'source' => 'school',
            ]);
        $group = GroupAddedResource::query()->where('school_id', $plan->school_id)->where('group_id', $plan->group_id)
            ->where(fn ($visible) => $visible->where('visibility', ResourceVisibility::Group->value)
                ->orWhere('created_by_id', $plan->teacher_id))
            ->orderBy('id')->get()
            ->map(fn (GroupAddedResource $resource): array => [
                'key' => 'group:'.$resource->id,
                'name' => $resource->name,
                'source' => $resource->visibility === ResourceVisibility::Group ? 'group' : 'own',
            ]);

        return [...$school->all(), ...$group->all()];
    }

    /**
     * The unchecked keys that still name a listed resource: one removed from
     * the group (or unchecked for the whole group) since is dropped, so saving
     * the field again never fails on it.
     *
     * @param  list<array{key: string, name: string, source: string}>  $resources
     * @return list<string>|null
     */
    protected function stillOffered(mixed $unchecked, array $resources): ?array
    {
        if (! is_array($unchecked)) {
            return null;
        }
        $kept = array_values(array_intersect($unchecked, array_column($resources, 'key')));

        return $kept === [] ? null : $kept;
    }

    /**
     * Checks the resources the teacher unchecked for this unit: only keys of
     * resources() are accepted. Returns the keys to store (null = every
     * resource checked) or an error message.
     *
     * @return array{ok: bool, value?: list<string>|null, error?: string}
     */
    public function normalizeResources(Unit $unit, mixed $value): array
    {
        if ($value === null || $value === []) {
            return ['ok' => true, 'value' => null];
        }

        $offered = array_column($this->resources($unit), 'key');
        if (! is_array($value) || ! array_is_list($value)
            || ! collect($value)->every(fn ($key) => is_string($key) && in_array($key, $offered, true))) {
            return ['ok' => false, 'error' => 'Every value must be one of the group\'s resources.'];
        }

        return ['ok' => true, 'value' => array_values(array_unique($value))];
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
