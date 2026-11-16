<?php

namespace App\Services\Indicators;

use App\Enums\IndicatorSourceType;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\ExternalMeasure;
use App\Models\ExternalMeasureValue;
use App\Models\Indicator;
use App\Models\OfficialScaleLevel;
use App\Models\School;
use App\Models\ScreeningTestResult;
use App\Models\ScreeningTestType;
use App\Models\Subject;
use App\Services\ResultScales;
use App\Support\SchoolTime;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The raw material of screen 30 «Indicadores y cruces» (ClickUp 1dfd3mc4bgp):
 * every datum a student has, read the same way for «Datos», each indicator,
 * each crossing and the chart. One instance per request: values are memoized.
 *
 * The population is the current school year's roster (every student with a
 * group this year, Primaria included — P30-DEC-05). For each source a student
 * gets at most one datum:
 *
 * - Screening: an annual screening («se toma todos los años») uses this
 *   year's take, and a student without one has no datum; any other uses the
 *   latest take of any year and says which year (PO decision, P30-DAT-02).
 *   The score is the original number, read with the design in force.
 * - Subject: this year's results in the subject. Numeric scores are
 *   averaged. A Primaria level of progress is a number only through the
 *   school's own equivalence (ResultScales::equivalences, screen 24); without
 *   it the datum is the latest level, which works like a band (PO decision 6).
 *   Aula+ never converts a level by itself (PA-088).
 * - External: this year's value, or the latest of any year when the datum is
 *   not taken every year.
 * - Indicator: its calculated value (IndicatorCalculator).
 * - Assessment (crossing conditions only): the grade in that assessment.
 *
 * Tenant: models use the SchoolScope; the raw roster query is scoped by hand.
 */
class IndicatorData
{
    /** Scale of a screening score (screen 07: «Puntaje (0 a 100)»). */
    public const SCREENING_SCALE = ['min' => 0.0, 'max' => 100.0];

    /** Scale of a grade (the «Nota de 1 a 10» of the evolution charts). */
    public const GRADE_SCALE = ['min' => 1.0, 'max' => 10.0];

    protected int $year;

    /** @var array<int, array{student_id: int, name: string, group_id: int, group_name: string, school_grade_id: int|null, school_grade: string|null}>|null */
    protected ?array $roster = null;

    /** @var array<string, array<int, array<string, mixed>>> */
    protected array $memo = [];

    /** @var array<int, int>|null */
    protected ?array $equivalences = null;

    /** @var list<int> indicator ids being calculated (cycle guard) */
    protected array $stack = [];

    public function __construct(protected ResultScales $scales)
    {
        $this->year = SchoolTime::today()->year;
    }

    public function schoolYear(): int
    {
        return $this->year;
    }

    /**
     * This year's students, one group each, keyed by student id.
     *
     * @return array<int, array{student_id: int, name: string, group_id: int, group_name: string, school_grade_id: int|null, school_grade: string|null}>
     */
    public function roster(): array
    {
        if ($this->roster !== null) {
            return $this->roster;
        }

        $this->roster = [];

        DB::table('group_student')
            ->join('groups', 'groups.id', '=', 'group_student.group_id')
            ->join('students', 'students.id', '=', 'group_student.student_id')
            ->leftJoin('school_grades', 'school_grades.id', '=', 'groups.school_grade_id')
            ->where('groups.school_id', Tenancy::schoolId())
            ->where('students.school_id', Tenancy::schoolId())
            ->whereNull('students.deleted_at')
            ->where('group_student.school_year', $this->year)
            ->orderBy('group_student.student_id')
            ->orderBy('groups.id')
            ->select([
                'group_student.student_id', 'students.full_name', 'groups.id as group_id', 'groups.name as group_name',
                'groups.school_grade_id', 'school_grades.label as school_grade',
            ])
            ->get()
            ->each(function (object $row): void {
                $id = (int) $row->student_id;
                $this->roster[$id] ??= [
                    'student_id' => $id,
                    'name' => (string) $row->full_name,
                    'group_id' => (int) $row->group_id,
                    'group_name' => (string) $row->group_name,
                    'school_grade_id' => $row->school_grade_id === null ? null : (int) $row->school_grade_id,
                    'school_grade' => $row->school_grade,
                ];
            });

        return $this->roster;
    }

    /**
     * Each roster student's datum for a source. A datum is
     * `['value' => float|null, 'level' => array|null, 'band' => string|null, 'year' => int|null]`;
     * students without one are absent.
     *
     * @return array<int, array{value: float|null, level: array{id: int, name: string, position: int}|null, band: string|null, year: int|null}>
     */
    public function values(string $type, int $id): array
    {
        $key = $type.':'.$id;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $values = match (IndicatorSourceType::tryFrom($type)) {
            IndicatorSourceType::Screening => $this->screening($id),
            IndicatorSourceType::Subject => $this->subject($id),
            IndicatorSourceType::External => $this->external($id),
            IndicatorSourceType::Indicator => $this->indicator($id),
            IndicatorSourceType::Assessment => $this->assessment($id),
            null => [],
        };

        return $this->memo[$key] = array_intersect_key($values, $this->roster());
    }

    /**
     * The original scale of a source, null when it has none (a Z score, a
     * difference).
     *
     * @return array{min: float, max: float}|null
     */
    public function scale(string $type, int $id): ?array
    {
        return match (IndicatorSourceType::tryFrom($type)) {
            IndicatorSourceType::Screening => self::SCREENING_SCALE,
            IndicatorSourceType::Subject, IndicatorSourceType::Assessment => self::GRADE_SCALE,
            IndicatorSourceType::External => ($measure = ExternalMeasure::query()->find($id))
                ? ['min' => $measure->scale_min, 'max' => $measure->scale_max]
                : null,
            IndicatorSourceType::Indicator => ($indicator = Indicator::query()->find($id))
                ? IndicatorCalculator::outputScale($indicator->calculation->value, $indicator->base_100, $this->inputScales($indicator->inputs ?? []))
                : null,
            null => null,
        };
    }

    /**
     * @param  list<array{type: string, id: int|string}>  $inputs
     * @return list<array{min: float, max: float}|null>
     */
    public function inputScales(array $inputs): array
    {
        return array_map(fn (array $input): ?array => $this->scale((string) $input['type'], (int) $input['id']), $inputs);
    }

    /**
     * The levels of progress a source can take, in the scale's order: only
     * for a subject or assessment recorded with a Primaria scale.
     *
     * @return list<array{id: int, name: string, position: int}>
     */
    public function levels(string $type, int $id): array
    {
        $levelIds = match (IndicatorSourceType::tryFrom($type)) {
            IndicatorSourceType::Subject => $this->subjectResults($id)->pluck('official_scale_level_id'),
            IndicatorSourceType::Assessment => AssessmentResult::query()->where('assessment_id', $id)->pluck('official_scale_level_id'),
            default => collect(),
        };
        $levelIds = $levelIds->filter()->unique()->values();

        if ($levelIds->isEmpty()) {
            return [];
        }

        $scaleIds = OfficialScaleLevel::query()->whereIn('id', $levelIds)->pluck('official_scale_id')->unique();

        return OfficialScaleLevel::query()
            ->whereIn('official_scale_id', $scaleIds)
            ->orderBy('official_scale_id')
            ->orderBy('position')
            ->get()
            ->map(fn (OfficialScaleLevel $level): array => ['id' => $level->id, 'name' => $level->name, 'position' => (int) $level->position])
            ->values()
            ->all();
    }

    /**
     * The band names of a screening (design in force) or an indicator.
     *
     * @return list<array{name: string, color: string, max: float|null}>
     */
    public function bands(string $type, int $id): array
    {
        $bands = match (IndicatorSourceType::tryFrom($type)) {
            IndicatorSourceType::Screening => ScreeningTestType::query()->find($id)?->currentApprovedDesign()?->bandList() ?? [],
            IndicatorSourceType::Indicator => Indicator::query()->find($id)?->bands ?? [],
            default => [],
        };

        return array_values(array_map(static fn (array $band): array => [
            'name' => (string) $band['name'],
            'color' => (string) $band['color'],
            'max' => isset($band['max']) ? (float) $band['max'] : null,
        ], $bands));
    }

    /**
     * Whether the source exists in the current school (SchoolScope).
     */
    public function exists(string $type, int $id): bool
    {
        return match (IndicatorSourceType::tryFrom($type)) {
            IndicatorSourceType::Screening => ScreeningTestType::query()->whereKey($id)->exists(),
            IndicatorSourceType::Subject => Subject::query()->whereKey($id)->exists(),
            IndicatorSourceType::External => ExternalMeasure::query()->whereKey($id)->exists(),
            IndicatorSourceType::Indicator => Indicator::query()->whereKey($id)->exists(),
            IndicatorSourceType::Assessment => Assessment::query()->whereKey($id)->exists(),
            null => false,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function screening(int $typeId): array
    {
        $type = ScreeningTestType::query()->find($typeId);

        if ($type === null) {
            return [];
        }

        $design = $type->currentApprovedDesign();
        $values = [];

        ScreeningTestResult::query()
            ->join('screening_test_applications', 'screening_test_applications.id', '=', 'screening_test_results.screening_test_application_id')
            ->where('screening_test_applications.screening_test_type_id', $type->id)
            ->whereNotNull('screening_test_results.score')
            ->whereIn('screening_test_results.student_id', array_keys($this->roster()))
            ->when($type->taken_every_year, fn ($query) => $query
                ->whereBetween('screening_test_applications.application_date', ["{$this->year}-01-01", "{$this->year}-12-31"]))
            ->orderByDesc('screening_test_applications.application_date')
            ->orderByDesc('screening_test_results.id')
            ->select(['screening_test_results.student_id', 'screening_test_results.score', 'screening_test_results.band', 'screening_test_applications.application_date'])
            ->get()
            ->each(function ($row) use (&$values, $design): void {
                $studentId = (int) $row->student_id;

                if (isset($values[$studentId])) {
                    return;
                }

                $score = (float) $row->score;
                $values[$studentId] = [
                    'value' => $score,
                    'level' => null,
                    // Read with the design in force (screen 07): the stored
                    // band only when the screening has no design any more.
                    'band' => $design?->bandFor($score)['name'] ?? $row->band,
                    'year' => (int) substr((string) $row->application_date, 0, 4),
                ];
            });

        return $values;
    }

    /**
     * @return Collection<int, object>
     */
    protected function subjectResults(int $subjectId)
    {
        return AssessmentResult::query()
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->join('groups', 'groups.id', '=', 'assessments.group_id')
            ->where('assessments.subject_id', $subjectId)
            ->where('groups.school_year', $this->year)
            ->whereIn('assessment_results.student_id', array_keys($this->roster()))
            ->where(fn ($loaded) => $loaded
                ->whereNotNull('assessment_results.score')
                ->orWhereNotNull('assessment_results.official_scale_level_id'))
            ->orderByDesc('assessments.administered_at')
            ->orderByDesc('assessment_results.id')
            ->select(['assessment_results.student_id', 'assessment_results.score', 'assessment_results.official_scale_level_id'])
            ->get()
            ->toBase();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function subject(int $subjectId): array
    {
        return $this->fromResults($this->subjectResults($subjectId));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function assessment(int $assessmentId): array
    {
        $results = AssessmentResult::query()
            ->where('assessment_id', $assessmentId)
            ->where(fn ($loaded) => $loaded->whereNotNull('score')->orWhereNotNull('official_scale_level_id'))
            ->orderByDesc('id')
            ->get(['student_id', 'score', 'official_scale_level_id'])
            ->toBase();

        return $this->fromResults($results);
    }

    /**
     * Results newest first → one datum per student: the average of the
     * numbers (scores, or levels through the school's equivalence), else the
     * latest level of progress.
     *
     * @param  Collection<int, object>  $results
     * @return array<int, array<string, mixed>>
     */
    protected function fromResults($results): array
    {
        $equivalences = $this->equivalences();
        $levelIds = $results->pluck('official_scale_level_id')->filter()->unique();
        $levels = $levelIds->isEmpty()
            ? collect()
            : OfficialScaleLevel::query()->whereIn('id', $levelIds)->get()->keyBy('id');

        $values = [];

        foreach ($results->groupBy('student_id') as $studentId => $rows) {
            $numbers = [];
            $latestLevel = null;

            foreach ($rows as $row) {
                if ($row->score !== null) {
                    $numbers[] = (float) $row->score;
                } elseif ($row->official_scale_level_id !== null) {
                    $levelId = (int) $row->official_scale_level_id;

                    if (isset($equivalences[$levelId])) {
                        $numbers[] = (float) $equivalences[$levelId];
                    }

                    if ($latestLevel === null && $levels->has($levelId)) {
                        $level = $levels[$levelId];
                        $latestLevel = ['id' => $level->id, 'name' => $level->name, 'position' => (int) $level->position];
                    }
                }
            }

            if ($numbers === [] && $latestLevel === null) {
                continue;
            }

            $values[(int) $studentId] = [
                'value' => $numbers === [] ? null : round(array_sum($numbers) / count($numbers), 2),
                'level' => $latestLevel,
                'band' => null,
                'year' => $this->year,
            ];
        }

        return $values;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function external(int $measureId): array
    {
        $measure = ExternalMeasure::query()->find($measureId);

        if ($measure === null) {
            return [];
        }

        $values = [];

        ExternalMeasureValue::query()
            ->where('external_measure_id', $measure->id)
            ->when($measure->taken_every_year, fn ($query) => $query->where('school_year', $this->year))
            ->orderByDesc('school_year')
            ->get(['student_id', 'school_year', 'value'])
            ->each(function (ExternalMeasureValue $row) use (&$values): void {
                $values[$row->student_id] ??= [
                    'value' => (float) $row->value,
                    'level' => null,
                    'band' => null,
                    'year' => (int) $row->school_year,
                ];
            });

        return $values;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function indicator(int $indicatorId): array
    {
        // A cycle (A uses B uses A) is refused on save; this guard only keeps
        // a corrupted row from recursing forever.
        if (in_array($indicatorId, $this->stack, true) || count($this->stack) >= 5) {
            return [];
        }

        $indicator = Indicator::query()->find($indicatorId);

        if ($indicator === null) {
            return [];
        }

        $this->stack[] = $indicatorId;

        try {
            $computed = (new IndicatorCalculator($this))->compute(IndicatorCalculator::specOf($indicator));
        } finally {
            array_pop($this->stack);
        }

        return array_map(static fn (array $row): array => [
            'value' => $row['value'],
            'level' => null,
            'band' => $row['band'],
            'year' => null,
            'population' => $row['population'],
        ], $computed);
    }

    /**
     * @return array<int, int>
     */
    protected function equivalences(): array
    {
        return $this->equivalences ??= $this->scales->equivalences(School::query()->findOrFail(Tenancy::schoolId()));
    }
}
