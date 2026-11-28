<?php

namespace App\Actions\AI;

use App\Models\Unit;
use App\Services\Units\UnitPlanner;

/**
 * What the AI reads of the unit's planner for a class proposal (screen 13
 * «Unidad» › Planificación; ClickUp 1dfd3mc4bga, Eitán 2026-11-26: «que la IA
 * lea su planificador (campos completos y recursos tildados)»).
 *
 * A class that hangs from no unit reads no planner (P13-DEC-101): it answers
 * "not read" with why, and the proposal says so. A class of a unit always
 * reads it — the planner never blocks and no field is mandatory (P13-DEC-07),
 * so an empty planner is read as empty, not as missing.
 *
 * What is sent:
 * - the fields shown in the unit's planner that the teacher filled in, by
 *   the name the school gives them; a school field marked «Solo para
 *   registro» (`purpose` = `record`) is kept, never sent;
 * - «Recursos disponibles»: the names of the resources the teacher kept
 *   checked for this unit (the group's, as the plan's teacher sees them);
 * - «Diferenciación» is not repeated here: it is the group's accommodations,
 *   which already reach the AI aggregated as pedagogical actions in
 *   `accommodation_input` (CLAUDE.md rule 11 — never names nor per-student
 *   detail), so the field only points there.
 */
class BuildUnitPlannerInput
{
    public const REASON_NO_UNIT = 'class_without_unit';

    public const DIFFERENTIATION_KEY = 'differentiation';

    public function __construct(private readonly UnitPlanner $planner) {}

    /**
     * @return array{read: bool, reason: string|null, values: array<string, mixed>|null}
     */
    public function __invoke(?Unit $unit): array
    {
        if ($unit === null) {
            return ['read' => false, 'reason' => self::REASON_NO_UNIT, 'values' => null];
        }

        $planner = $this->planner->withValues($unit);
        $fields = [];
        $differentiation = false;
        $resourcesShown = false;

        foreach ($planner['fields'] as $field) {
            if (($field['purpose'] ?? null) === 'record') {
                continue;
            }
            if ($field['key'] === UnitPlanner::RESOURCES_KEY) {
                $resourcesShown = true;

                continue;
            }
            if ($field['key'] === self::DIFFERENTIATION_KEY) {
                $differentiation = true;

                continue;
            }

            $value = $field['value'] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $fields[] = array_filter([
                'name' => $field['school_name'] ?? $field['official_name'],
                'description' => $field['description'] ?? null,
                'value' => $value,
            ], fn ($part) => $part !== null);
        }

        $unchecked = $resourcesShown ? ($this->resourcesValue($planner['fields']) ?? []) : [];
        $resources = $resourcesShown
            ? array_values(array_map(
                fn (array $resource): string => $resource['name'],
                array_filter($planner['resources'], fn (array $resource) => ! in_array($resource['key'], $unchecked, true)),
            ))
            : [];

        return [
            'read' => true,
            'reason' => null,
            'values' => [
                'fields' => $fields,
                'resources' => $resources,
                'differentiation' => $differentiation ? 'accommodation_input' : null,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<string>|null
     */
    protected function resourcesValue(array $fields): ?array
    {
        foreach ($fields as $field) {
            if ($field['key'] === UnitPlanner::RESOURCES_KEY) {
                return is_array($field['value'] ?? null) ? $field['value'] : null;
            }
        }

        return null;
    }
}
