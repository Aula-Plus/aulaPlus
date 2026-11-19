<?php

namespace App\Actions\ClassSessions;

use App\Enums\ClassSessionStatus;
use App\Enums\ObjectiveCoverage;
use App\Models\ClassSession;
use Illuminate\Support\Facades\DB;

/**
 * The one-tap closing of a class (P15-DEC-03) and what it records for each
 * objective the class works — what AnnualPlanProgress derives the Programa
 * anual from:
 *
 * - «Todo»: every objective of the class covered fully (given);
 * - «Una parte»: the objectives the teacher marked are given; the others are
 *   recorded «una parte» — started, never given, and «Lo terminé» can finish
 *   them later (P12-COB-02);
 * - «Nada»: none covered; they stay as planned.
 *
 * Closing again corrects the same class's record and never touches another
 * class's. «Todo» and «Una parte» mark the class as delivered; «Nada» leaves
 * its status as it was (it may not have been given at all).
 */
class CloseClassSession
{
    /**
     * @param  list<int>  $givenObjectiveIds
     */
    public function __invoke(ClassSession $class, ObjectiveCoverage $result, array $givenObjectiveIds = []): ClassSession
    {
        return DB::transaction(function () use ($class, $result, $givenObjectiveIds): ClassSession {
            $linked = $class->unitObjectives()->pluck('unit_objectives.id');

            $class->unitObjectives()->syncWithoutDetaching(
                $linked->mapWithKeys(fn ($id) => [$id => ['coverage' => match ($result) {
                    ObjectiveCoverage::Full => ObjectiveCoverage::Full->value,
                    ObjectiveCoverage::None => ObjectiveCoverage::None->value,
                    ObjectiveCoverage::Partial => in_array((int) $id, $givenObjectiveIds, true)
                        ? ObjectiveCoverage::Full->value
                        : ObjectiveCoverage::Partial->value,
                }]])->all(),
            );

            $class->update([
                'closing_result' => $result,
                'closed_at' => now(),
                'status' => $result === ObjectiveCoverage::None ? $class->status : ClassSessionStatus::Delivered,
            ]);

            return $class;
        });
    }
}
