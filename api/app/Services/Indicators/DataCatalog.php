<?php

namespace App\Services\Indicators;

use App\Enums\IndicatorSourceType;
use App\Models\Assessment;
use App\Models\ExternalMeasure;
use App\Models\Indicator;
use App\Models\ScreeningTestType;
use App\Models\Subject;

/**
 * «Datos disponibles» (P30-DAT-02): every datum screen 30 can use, with its
 * original scale and how many of this year's students have it. Aggregate
 * only — no student appears here. Rubric criteria are not listed until
 * per-criterion grading exists (screen 17, PO decision 2).
 */
class DataCatalog
{
    public function __construct(protected IndicatorData $data) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function sources(): array
    {
        $sources = [];

        foreach (ScreeningTestType::query()->orderBy('name')->get() as $type) {
            $sources[] = $this->describe(IndicatorSourceType::Screening->value, $type->id, $type->name, [
                'taken_every_year' => (bool) $type->taken_every_year,
            ]);
        }

        $subjectIds = collect(array_keys($this->data->roster()))->isEmpty()
            ? collect()
            : Assessment::query()
                ->join('groups', 'groups.id', '=', 'assessments.group_id')
                ->where('groups.school_year', $this->data->schoolYear())
                ->whereNotNull('assessments.subject_id')
                ->distinct()
                ->pluck('assessments.subject_id');

        foreach (Subject::query()->whereIn('id', $subjectIds)->orderBy('name')->get() as $subject) {
            $source = $this->describe(IndicatorSourceType::Subject->value, $subject->id, $subject->name);

            if ($source['coverage'] > 0) {
                $sources[] = $source;
            }
        }

        foreach (ExternalMeasure::query()->orderBy('name')->get() as $measure) {
            $sources[] = $this->describe(IndicatorSourceType::External->value, $measure->id, $measure->name, [
                'taken_every_year' => $measure->taken_every_year,
            ]);
        }

        foreach (Indicator::query()->orderBy('name')->get() as $indicator) {
            $sources[] = $this->describe(IndicatorSourceType::Indicator->value, $indicator->id, $indicator->name, [
                'in_cross_chart' => in_array('cross_chart', $indicator->display_places ?? [], true),
            ]);
        }

        return $sources;
    }

    /**
     * This year's assessments, for a crossing on the grade of one of them.
     *
     * @return list<array<string, mixed>>
     */
    public function assessments(): array
    {
        $assessments = Assessment::query()
            ->join('groups', 'groups.id', '=', 'assessments.group_id')
            ->leftJoin('subjects', 'subjects.id', '=', 'assessments.subject_id')
            ->where('groups.school_year', $this->data->schoolYear())
            ->orderBy('groups.name')
            ->orderBy('subjects.name')
            ->orderBy('assessments.administered_at')
            ->get(['assessments.id', 'assessments.type', 'assessments.administered_at', 'groups.name as group_name', 'subjects.name as subject_name']);

        // The levels of every assessment in a fixed number of queries.
        $levels = $this->data->levelsOfAssessments($assessments->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $assessments
            ->map(fn ($assessment): array => [
                'id' => $assessment->id,
                'type' => $assessment->type?->value,
                'administered_at' => $assessment->administered_at?->toDateString(),
                'group_name' => $assessment->group_name,
                'subject_name' => $assessment->subject_name,
                'levels' => $levels[$assessment->id] ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * The name of a datum, for listing an indicator's inputs or a crossing's
     * conditions. Null when it no longer exists.
     */
    public function name(string $type, int $id): ?string
    {
        return match (IndicatorSourceType::tryFrom($type)) {
            IndicatorSourceType::Screening => ScreeningTestType::query()->find($id)?->name,
            IndicatorSourceType::Subject => Subject::query()->find($id)?->name,
            IndicatorSourceType::External => ExternalMeasure::query()->find($id)?->name,
            IndicatorSourceType::Indicator => Indicator::query()->find($id)?->name,
            IndicatorSourceType::Assessment => ($assessment = Assessment::query()->with(['group:id,name', 'subject:id,name'])->find($id))
                ? trim(($assessment->subject?->name ?? '').' · '.($assessment->group?->name ?? '').' · '.($assessment->administered_at?->format('d/m/Y') ?? ''), ' ·')
                : null,
            null => null,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function describe(string $type, int $id, string $name, array $extra = []): array
    {
        $values = $this->data->values($type, $id);
        $years = array_filter(array_column($values, 'year'));

        return [
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'scale' => $this->data->scale($type, $id),
            'coverage' => count($values),
            'number_coverage' => count(array_filter($values, static fn (array $datum): bool => $datum['value'] !== null)),
            'level_coverage' => count(array_filter($values, static fn (array $datum): bool => $datum['value'] === null && $datum['level'] !== null)),
            'latest_year' => $years === [] ? null : max($years),
            'bands' => $this->data->bands($type, $id),
            'levels' => $this->data->levels($type, $id),
            ...$extra,
        ];
    }
}
