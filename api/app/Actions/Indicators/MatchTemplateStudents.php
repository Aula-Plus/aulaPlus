<?php

namespace App\Actions\Indicators;

use App\Services\Indicators\IndicatorData;
use Illuminate\Support\Str;

/**
 * Screen 30 «Datos», loading an external datum from a CSV (PO answer of 17/11
 * on ClickUp 1dfd3mc4bgp): each row says who it is by the Aula+ code
 * («id_alumno», the student id the template carries) or, when the file has
 * no code, by the student's name (and group, when given).
 *
 * - A code of this year's roster identifies the student.
 * - Otherwise the name is compared with the roster's names (ignoring case,
 *   accents and extra spaces), narrowed by the group when one matches.
 *   One student → matched. Several → `ambiguous`, with each candidate's code
 *   and group, so the person says which one the row is: Aula+ never guesses.
 *   None → `unknown`.
 *
 * Candidates only ever come from the roster of the current school
 * (IndicatorData::roster(): this year's students of the person's school,
 * which Psicopedagogía and Dirección see in full). Names are never logged.
 */
class MatchTemplateStudents
{
    public function __construct(protected IndicatorData $data) {}

    /**
     * @param  list<array{student_id?: int|string|null, name?: string|null, group?: string|null}>  $rows
     * @return list<array{status: string, student_id: int|null, candidates: list<array{student_id: int, name: string, group_name: string}>}>
     */
    public function __invoke(array $rows): array
    {
        $roster = $this->data->roster();
        $byName = [];

        foreach ($roster as $student) {
            $byName[self::normalize($student['name'])][] = $student;
        }

        return array_map(function (array $row) use ($roster, $byName): array {
            $id = isset($row['student_id']) && $row['student_id'] !== '' ? (int) $row['student_id'] : null;

            if ($id !== null && isset($roster[$id])) {
                return ['status' => 'matched', 'student_id' => $id, 'candidates' => []];
            }

            $name = self::normalize((string) ($row['name'] ?? ''));
            $candidates = $name === '' ? [] : ($byName[$name] ?? []);
            $group = self::normalize((string) ($row['group'] ?? ''));

            if ($group !== '' && count($candidates) > 1) {
                $inGroup = array_values(array_filter($candidates, static fn (array $student): bool => self::normalize($student['group_name']) === $group));
                $candidates = $inGroup === [] ? $candidates : $inGroup;
            }

            return match (count($candidates)) {
                0 => ['status' => 'unknown', 'student_id' => null, 'candidates' => []],
                1 => ['status' => 'matched', 'student_id' => $candidates[0]['student_id'], 'candidates' => []],
                default => [
                    'status' => 'ambiguous',
                    'student_id' => null,
                    'candidates' => array_values(array_map(static fn (array $student): array => [
                        'student_id' => $student['student_id'],
                        'name' => $student['name'],
                        'group_name' => $student['group_name'],
                    ], $candidates)),
                ],
            };
        }, $rows);
    }

    public static function normalize(string $value): string
    {
        return (string) Str::of(Str::ascii($value))->lower()->squish();
    }
}
