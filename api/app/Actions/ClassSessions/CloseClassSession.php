<?php

namespace App\Actions\ClassSessions;

use App\Enums\ClassSessionStatus;
use App\Enums\ObjectiveCoverage;
use App\Models\ClassSession;
use Illuminate\Support\Facades\DB;

/**
 * The one-tap closing of a class (P15-DEC-03, P15-PRO-05) and what it records
 * for each objective the class works — what AnnualPlanProgress derives the
 * Programa anual from:
 *
 * - «Todo»: every objective of the class covered fully (given);
 * - «Una parte»: per objective, what the teacher marked — «Se dio» is
 *   recorded given (`full`), «Se empezó» is recorded started (`partial`,
 *   never given; «Lo terminé» can finish it later, P12-COB-02 / PA-092).
 *   An objective not marked gets no record: it stays as planned (ClickUp
 *   answer of 22/11);
 * - «Nada»: none covered (`none`); they stay as planned.
 *
 * Closing again corrects the same class's record — every objective of the
 * class is rewritten, so nothing of a previous close survives — and never
 * touches another class's. «Todo» and «Una parte» mark the class as
 * delivered; «Nada» leaves it planned (it may not have been given at all).
 */
class CloseClassSession
{
    /**
     * @param  list<int>  $givenObjectiveIds  «Se dio» (only with «Una parte»)
     * @param  list<int>  $startedObjectiveIds  «Se empezó» (only with «Una parte»)
     */
    public function __invoke(
        ClassSession $class,
        ObjectiveCoverage $result,
        array $givenObjectiveIds = [],
        array $startedObjectiveIds = [],
    ): ClassSession {
        return DB::transaction(function () use ($class, $result, $givenObjectiveIds, $startedObjectiveIds): ClassSession {
            $linked = $class->unitObjectives()->pluck('unit_objectives.id');

            $class->unitObjectives()->syncWithoutDetaching(
                $linked->mapWithKeys(fn ($id) => [$id => ['coverage' => match ($result) {
                    ObjectiveCoverage::Full => ObjectiveCoverage::Full->value,
                    ObjectiveCoverage::None => ObjectiveCoverage::None->value,
                    ObjectiveCoverage::Partial => match (true) {
                        in_array((int) $id, $givenObjectiveIds, true) => ObjectiveCoverage::Full->value,
                        in_array((int) $id, $startedObjectiveIds, true) => ObjectiveCoverage::Partial->value,
                        // Not touched in this class: still planned, no record.
                        default => null,
                    },
                }]])->all(),
            );

            $class->update([
                'closing_result' => $result,
                'closed_at' => now(),
                // Closing again corrects the record: «Nada» after «Todo» is a
                // class that was not given (a cancelled one can't be closed).
                'status' => $result === ObjectiveCoverage::None ? ClassSessionStatus::Planned : ClassSessionStatus::Delivered,
            ]);

            return $class;
        });
    }
}
