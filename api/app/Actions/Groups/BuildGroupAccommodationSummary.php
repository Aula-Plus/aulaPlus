<?php

namespace App\Actions\Groups;

use App\Models\Accommodation;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Active accommodations of a group's students aggregated by `type`. Strictly
 * aggregate: no student identifier.
 *
 * Grouped by the free-text `type` (not `category`: two accommodations may share
 * a category yet be different concrete adjustments); `student_count` counts
 * DISTINCT students, and only effective accommodations are counted.
 *
 * Only the accommodations the viewer may see are counted
 * (Accommodation::scopeVisibleTo, the same rule as the student file): a teacher
 * gets the general ones plus those of the subjects they teach, so the table
 * never counts an adjustment the student list next to it says isn't there.
 */
class BuildGroupAccommodationSummary
{
    /**
     * @return list<array{type: string, category: string|null, student_count: int}>
     */
    public function __invoke(Group $group, User $viewer): array
    {
        $studentIds = $group->students()->pluck('students.id');

        return Accommodation::query()
            ->visibleTo($viewer)
            // Query twin of isEffective(): don't hydrate inactive rows only to
            // drop them in PHP.
            ->effective()
            ->whereIn('accommodations.student_id', $studentIds)
            // Fixed row order, so "the first" category below is always the
            // same one between requests.
            ->orderBy('accommodations.id')
            ->get(['accommodations.id', 'accommodations.student_id', 'accommodations.type', 'accommodations.category'])
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
}
