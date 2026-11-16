<?php

namespace App\Services\SchoolYears;

use App\Enums\SchoolYearStatus;
use App\Models\Group;
use App\Models\GroupWriteWindow;
use App\Models\SchoolYear;
use App\Support\SchoolTime;
use App\Support\Tenancy;

/**
 * The school's year state (screen 26 «Año y calendario», ClickUp 1dfd3mc4bh7)
 * and the single place that decides whether a group's data may still be
 * written:
 *
 * - A group is read-only when its `school_year` is a closed year — or any year
 *   up to the last closed one, so groups older than the first year closed
 *   with Aula+ are history too.
 * - Unless direction enabled loading in that group (a GroupWriteWindow) and
 *   its until-date has not passed (school-local day, inclusive).
 *
 * Bound as a scoped instance: the last closed year is read once per request
 * and forgotten when a year is closed or opened.
 */
class SchoolYearCalendar
{
    /** @var array<int, int|null> last closed year per school id */
    private array $lastClosed = [];

    /**
     * The school's current year: the latest one recorded. A school that never
     * used the screen starts with today's calendar year, open.
     */
    public function current(?int $schoolId = null): SchoolYear
    {
        $schoolId ??= Tenancy::schoolId();

        $latest = SchoolYear::query()->where('school_id', $schoolId)->orderByDesc('year')->first();

        return $latest ?? SchoolYear::query()->forceCreate([
            'school_id' => $schoolId,
            'year' => SchoolTime::today()->year,
            'status' => SchoolYearStatus::Open,
        ]);
    }

    public function lastClosedYear(int $schoolId): ?int
    {
        if (! array_key_exists($schoolId, $this->lastClosed)) {
            $year = SchoolYear::query()
                ->where('school_id', $schoolId)
                ->where('status', SchoolYearStatus::Closed->value)
                ->max('year');
            $this->lastClosed[$schoolId] = $year === null ? null : (int) $year;
        }

        return $this->lastClosed[$schoolId];
    }

    public function forget(): void
    {
        $this->lastClosed = [];
    }

    /** Whether the group's year is closed (regardless of any window). */
    public function isInClosedYear(Group $group): bool
    {
        $lastClosed = $this->lastClosedYear((int) $group->school_id);

        return $lastClosed !== null && $group->school_year !== null && (int) $group->school_year <= $lastClosed;
    }

    /** The window that currently lets the group be written, if any. */
    public function activeWindow(Group $group): ?GroupWriteWindow
    {
        return GroupWriteWindow::query()
            ->where('school_id', $group->school_id)
            ->where('group_id', $group->id)
            ->active()
            ->orderByDesc('until_on')
            ->first();
    }

    /** Whether writes to the group's data must be rejected. */
    public function isReadOnly(Group $group): bool
    {
        return $this->isInClosedYear($group) && $this->activeWindow($group) === null;
    }
}
