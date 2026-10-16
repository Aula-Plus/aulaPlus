<?php

namespace App\Actions\Screening;

use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestResult;
use App\Models\ScreeningTestType;

/**
 * "Ver cómo queda el grupo con este corte" (screen 08): how many students fall
 * into each band with the bands in force and with the proposed ones, counting
 * the latest loaded take of each student of the screening. Counts only — no
 * names, codes or scores ever leave this action.
 */
class ComputeScreeningImpact
{
    /**
     * @param  list<array{name: string, color: string, max: float|null, meaning?: string|null}>  $proposed
     * @return array{students: int, current: list<array{name: string, color: string, max: float|null, count: int}>|null, proposed: list<array{name: string, color: string, max: float|null, count: int}>}
     */
    public function __invoke(ScreeningTestType $type, array $proposed, ?ScreeningTestDesign $current = null): array
    {
        $scores = $this->latestScores($type);
        $proposedDesign = new ScreeningTestDesign(['bands' => $proposed]);

        return [
            'students' => count($scores),
            'current' => $current ? $this->count($current, $scores) : null,
            'proposed' => $this->count($proposedDesign, $scores),
        ];
    }

    /**
     * The latest loaded score of each student for this screening: by
     * application date, then by most recent application.
     *
     * @return array<int, float> student_id => score
     */
    protected function latestScores(ScreeningTestType $type): array
    {
        $rows = ScreeningTestResult::query()
            ->join('screening_test_applications', 'screening_test_applications.id', '=', 'screening_test_results.screening_test_application_id')
            ->where('screening_test_applications.screening_test_type_id', $type->id)
            ->whereNotNull('screening_test_results.score')
            ->orderByDesc('screening_test_applications.application_date')
            ->orderByDesc('screening_test_applications.id')
            ->get(['screening_test_results.student_id', 'screening_test_results.score']);

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->student_id] ??= (float) $row->score;
        }

        return $latest;
    }

    /**
     * @param  array<int, float>  $scores
     * @return list<array{name: string, color: string, max: float|null, count: int}>
     */
    protected function count(ScreeningTestDesign $design, array $scores): array
    {
        $bands = array_map(static fn (array $band): array => [
            'name' => $band['name'],
            'color' => $band['color'],
            'max' => $band['max'],
            'count' => 0,
        ], $design->bandList());

        foreach ($scores as $score) {
            $bands[$design->bandFor($score)['index']]['count']++;
        }

        return $bands;
    }
}
