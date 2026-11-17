<?php

namespace App\Services\Indicators;

use App\Enums\IndicatorCalculation;
use App\Enums\ZScorePopulation;
use App\Models\Indicator;

/**
 * Calculates an indicator for every student of this year's roster (screen 30
 * «Indicadores», P30-IND-03). Plain arithmetic the school configured; nothing
 * comes from a model (P30-DEC-04).
 *
 * - `raw`: the datum as it is.
 * - `average`: the mean of the data; a student missing any of them has no
 *   value (an indicator over half the data would not say the same thing).
 * - `z_score`: (x − mean) / standard deviation within the population the
 *   indicator chose (PO decision 4): same grade and school year (default; a
 *   group without a grade falls back to its own group), own group, or the
 *   whole school. A population of fewer than two values, or with no spread,
 *   gives no value. The population used goes with each value.
 * - `difference`: A − B.
 *
 * With `base_100` each input on a bounded scale is put on a 100 base
 * (value × 100 / scale max, as in the prototype) before calculating; the
 * original numbers stay stored where they came from. A level of progress
 * without the school's equivalence has no number, so it never enters a
 * calculation (it is usable in crossings and on the chart).
 */
class IndicatorCalculator
{
    public function __construct(protected IndicatorData $data) {}

    /**
     * @return array{calculation: string, inputs: list<array{type: string, id: int, grades?: array{rule: string, category: string|null}}>, base_100: bool, z_population: string|null, bands: list<array<string, mixed>>}
     */
    public static function specOf(Indicator $indicator): array
    {
        return [
            'calculation' => $indicator->calculation->value,
            'inputs' => array_values(array_map(static fn (array $input): array => array_filter([
                'type' => (string) $input['type'],
                'id' => (int) $input['id'],
                // A subject always says which grades it reads (every one by default).
                'grades' => SubjectGrades::forSource((string) $input['type'], $input['grades'] ?? null),
            ], static fn ($value): bool => $value !== null), $indicator->inputs ?? [])),
            'base_100' => (bool) $indicator->base_100,
            'z_population' => $indicator->z_population?->value,
            'bands' => $indicator->bands ?? [],
        ];
    }

    /**
     * The scale of an indicator's result, null when unbounded.
     *
     * @param  list<array{min: float, max: float}|null>  $inputScales
     * @return array{min: float, max: float}|null
     */
    public static function outputScale(string $calculation, bool $base100, array $inputScales): ?array
    {
        return match (IndicatorCalculation::tryFrom($calculation)) {
            IndicatorCalculation::Raw => $base100 && ($inputScales[0] ?? null) !== null
                ? ['min' => 0.0, 'max' => 100.0]
                : ($inputScales[0] ?? null),
            IndicatorCalculation::Average => $base100 && ! in_array(null, $inputScales, true)
                ? ['min' => 0.0, 'max' => 100.0]
                : (count(array_unique(array_map('json_encode', $inputScales))) === 1 ? $inputScales[0] : null),
            default => null,
        };
    }

    /**
     * @param  array{calculation: string, inputs: list<array{type: string, id: int, grades?: array{rule: string, category: string|null}}>, base_100: bool, z_population: string|null, bands: list<array<string, mixed>>}  $spec
     * @return array<int, array{value: float, band: string|null, band_color: string|null, population: array{kind: string, label: string|null, size: int}|null}>
     */
    public function compute(array $spec): array
    {
        $calculation = IndicatorCalculation::tryFrom($spec['calculation']);
        $inputs = $spec['inputs'];

        if ($calculation === null || $inputs === []) {
            return [];
        }

        $series = array_map(function (array $input) use ($spec): array {
            $scale = $this->data->scale($input['type'], $input['id']);
            $numbers = [];

            foreach ($this->data->values($input['type'], $input['id'], $input['grades'] ?? null) as $studentId => $datum) {
                if ($datum['value'] === null) {
                    continue;
                }

                $numbers[$studentId] = $spec['base_100'] && $scale !== null && $scale['max'] != 0.0
                    ? $datum['value'] * 100 / $scale['max']
                    : $datum['value'];
            }

            return $numbers;
        }, $inputs);

        $populations = [];
        $values = match ($calculation) {
            IndicatorCalculation::Raw => $series[0],
            IndicatorCalculation::Average => $this->average($series),
            IndicatorCalculation::Difference => count($series) < 2 ? [] : $this->difference($series[0], $series[1]),
            IndicatorCalculation::ZScore => $this->zScores(
                $series[0],
                ZScorePopulation::tryFrom((string) $spec['z_population']) ?? ZScorePopulation::GradeYear,
                $populations,
            ),
        };

        $result = [];

        foreach ($values as $studentId => $value) {
            $value = round($value, 2);
            $band = Indicator::bandIn($spec['bands'], $value);
            $result[$studentId] = [
                'value' => $value,
                'band' => $band['name'] ?? null,
                'band_color' => $band['color'] ?? null,
                'population' => $populations[$studentId] ?? null,
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * @param  list<array<int, float>>  $series
     * @return array<int, float>
     */
    protected function average(array $series): array
    {
        $common = array_keys(array_intersect_key(...$series));
        $values = [];

        foreach ($common as $studentId) {
            $values[$studentId] = array_sum(array_map(static fn (array $one): float => $one[$studentId], $series)) / count($series);
        }

        return $values;
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     * @return array<int, float>
     */
    protected function difference(array $a, array $b): array
    {
        $values = [];

        foreach (array_intersect_key($a, $b) as $studentId => $value) {
            $values[$studentId] = $value - $b[$studentId];
        }

        return $values;
    }

    /**
     * @param  array<int, float>  $numbers
     * @param  array<int, array{kind: string, label: string|null, size: int}>  $populations  filled with each student's population
     * @return array<int, float>
     */
    protected function zScores(array $numbers, ZScorePopulation $population, array &$populations): array
    {
        $roster = $this->data->roster();
        $buckets = [];
        $meta = [];

        foreach ($numbers as $studentId => $value) {
            $student = $roster[$studentId] ?? null;

            if ($student === null) {
                continue;
            }

            [$key, $kind, $label] = match (true) {
                $population === ZScorePopulation::School => ['school', 'school', null],
                $population === ZScorePopulation::GradeYear && $student['school_grade_id'] !== null => [
                    'grade-'.$student['school_grade_id'], 'grade_year', $student['school_grade'],
                ],
                default => ['group-'.$student['group_id'], 'group', $student['group_name']],
            };

            $buckets[$key][$studentId] = $value;
            $meta[$key] = ['kind' => $kind, 'label' => $label];
        }

        $values = [];

        foreach ($buckets as $key => $bucket) {
            $size = count($bucket);

            if ($size < 2) {
                continue;
            }

            $mean = array_sum($bucket) / $size;
            $deviation = sqrt(array_sum(array_map(static fn (float $x): float => ($x - $mean) ** 2, $bucket)) / $size);

            if ($deviation == 0.0) {
                continue;
            }

            foreach ($bucket as $studentId => $value) {
                $values[$studentId] = ($value - $mean) / $deviation;
                $populations[$studentId] = [...$meta[$key], 'size' => $size];
            }
        }

        return $values;
    }
}
