<?php

namespace App\Actions\Groups;

use App\Models\Accommodation;
use App\Models\Group;
use Illuminate\Support\Collection;

/**
 * Active accommodations of a group's students aggregated by `type`. Strictly
 * aggregate: no student identifier. Shared by the group-profile table and the
 * AI summary so both read exactly the same data.
 *
 * Grouped by the free-text `type` (not `category`: two accommodations may share
 * a category yet be different concrete adjustments); `student_count` counts
 * DISTINCT students, and only effective accommodations are counted.
 */
class BuildGroupAccommodationSummary
{
    /**
     * @return list<array{type: string, category: string|null, student_count: int}>
     */
    public function __invoke(Group $group): array
    {
        $studentIds = $group->students()->pluck('students.id');

        return Accommodation::query()
            ->whereIn('student_id', $studentIds)
            ->get()
            ->filter->isEffective()
            ->groupBy('type')
            ->map(fn (Collection $rows, string $type): array => [
                'type' => $type,
                // A given `type` is expected to carry one category; if the data
                // ever disagrees we take the first deterministically.
                'category' => $rows->first()->category?->value,
                'student_count' => $rows->pluck('student_id')->unique()->count(),
            ])
            ->sortBy('type')
            ->values()
            ->all();
    }

    /**
     * Stable hash of the summary, to tell whether it changed since a text was
     * generated.
     *
     * @param  list<array<string, mixed>>  $summary
     */
    public function fingerprint(array $summary): string
    {
        return hash('sha256', json_encode($summary, JSON_UNESCAPED_UNICODE));
    }
}
