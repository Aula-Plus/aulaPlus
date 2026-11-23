<?php

namespace App\Actions\AI;

use App\Models\Unit;

/**
 * What the AI reads of the unit's planner for a class proposal (screen 13
 * «Unidad»). Screens 14–15 start WITHOUT a planner (ClickUp 86e3jujh0, Q3 of
 * Eitán, P14-DEC-01): this is the one place to connect it once the Unidad
 * screen stores the planner values — return them here and the context, the
 * prompt and the proposal's «Datos usados» pick them up unchanged.
 *
 * Until then it always answers "not read", with why: the class hangs from no
 * unit (a free class never reads a planner, Q2) or the planner is not
 * available yet. The proposal says so (P15: «la indicación de planificador
 * sin lectura»).
 */
class BuildUnitPlannerInput
{
    public const REASON_NO_UNIT = 'class_without_unit';

    public const REASON_NOT_AVAILABLE = 'planner_not_available';

    /**
     * @return array{read: bool, reason: string|null, values: array<string, mixed>|null}
     */
    public function __invoke(?Unit $unit): array
    {
        if ($unit === null) {
            return ['read' => false, 'reason' => self::REASON_NO_UNIT, 'values' => null];
        }

        // TODO(screen 13): read the unit's planner values here.
        return ['read' => false, 'reason' => self::REASON_NOT_AVAILABLE, 'values' => null];
    }
}
