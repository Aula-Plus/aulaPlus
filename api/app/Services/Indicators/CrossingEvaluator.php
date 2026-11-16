<?php

namespace App\Services\Indicators;

use App\Enums\CrossingOperator;

/**
 * Which students a crossing reaches today (screen 30 «Cruces», P30-CRU-02):
 * every condition must hold. The preview is counted per group and never
 * names anybody — «3 alumnos de 5.°A · 1 de 5.°B» (PO decision 5).
 */
class CrossingEvaluator
{
    public function __construct(protected IndicatorData $data) {}

    /**
     * @param  list<array{source: array{type: string, id: int|string}, operator: string, value: mixed}>  $conditions
     * @return list<int> student ids
     */
    public function matches(array $conditions): array
    {
        if ($conditions === []) {
            return [];
        }

        $candidates = null;

        foreach ($conditions as $condition) {
            $values = $this->data->values((string) $condition['source']['type'], (int) $condition['source']['id']);
            $hits = [];

            foreach ($values as $studentId => $datum) {
                if ($this->holds($condition, $datum)) {
                    $hits[$studentId] = true;
                }
            }

            $candidates = $candidates === null ? $hits : array_intersect_key($candidates, $hits);
        }

        $ids = array_keys($candidates ?? []);
        sort($ids);

        return $ids;
    }

    /**
     * Counts per group, in group-name order (never ranked).
     *
     * @param  list<array{source: array{type: string, id: int|string}, operator: string, value: mixed}>  $conditions
     * @return array{total: int, groups: list<array{group_id: int, group_name: string, count: int}>}
     */
    public function preview(array $conditions): array
    {
        $roster = $this->data->roster();
        $groups = [];

        foreach ($this->matches($conditions) as $studentId) {
            $student = $roster[$studentId];
            $groups[$student['group_id']] ??= ['group_id' => $student['group_id'], 'group_name' => $student['group_name'], 'count' => 0];
            $groups[$student['group_id']]['count']++;
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => strnatcasecmp($a['group_name'], $b['group_name']));

        return [
            'total' => array_sum(array_column($groups, 'count')),
            'groups' => $groups,
        ];
    }

    /**
     * @param  array{source: array{type: string, id: int|string}, operator: string, value: mixed}  $condition
     * @param  array{value: float|null, level: array{id: int, name: string, position: int}|null, band: string|null, year: int|null}  $datum
     */
    protected function holds(array $condition, array $datum): bool
    {
        return match (CrossingOperator::tryFrom((string) $condition['operator'])) {
            CrossingOperator::Band => $datum['band'] !== null && $datum['band'] === (string) $condition['value'],
            CrossingOperator::Level => $datum['level'] !== null && $datum['level']['id'] === (int) $condition['value'],
            CrossingOperator::Below => $datum['value'] !== null && $datum['value'] < (float) $condition['value'],
            CrossingOperator::Above => $datum['value'] !== null && $datum['value'] > (float) $condition['value'],
            null => false,
        };
    }
}
