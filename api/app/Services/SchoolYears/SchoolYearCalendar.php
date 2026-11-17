<?php

namespace App\Services\SchoolYears;

use App\Enums\SchoolYearStatus;
use App\Models\Group;
use App\Models\GroupWriteWindow;
use App\Models\SchoolYear;
use App\Support\SchoolTime;
use App\Support\Tenancy;
use Illuminate\Support\Collection;

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
 * Bound as a scoped instance: the last closed year and the windows in force
 * are read once per request and forgotten when a year is closed or opened or
 * a window is enabled or ended.
 */
class SchoolYearCalendar
{
    /** @var array<int, int|null> last closed year per school id */
    private array $lastClosed = [];

    /**
     * The windows in force per school and school-local day, keyed by group id
     * (newest until-date first). Loaded once, so listing many groups of a
     * closed year costs one query instead of one per group.
     *
     * @var array<string, Collection<int|string, Collection<int, GroupWriteWindow>>>
     */
    private array $activeWindows = [];

    /**
     * The school's current year: the latest one recorded. A school that never
     * used the screen starts with its groups' latest year that is not in the
     * future (so a first visit in January or February still opens on the year
     * that is ending), or today's calendar year without groups — open.
     * Callers authorize before calling it (it may create that first row).
     */
    public function current(?int $schoolId = null): SchoolYear
    {
        $schoolId ??= Tenancy::schoolId();

        $latest = SchoolYear::query()->where('school_id', $schoolId)->orderByDesc('year')->first();
        if ($latest !== null) {
            return $latest;
        }

        $today = SchoolTime::today()->year;
        $groupsYear = Group::query()->where('school_id', $schoolId)
            ->whereNotNull('school_year')
            ->where('school_year', '<=', $today)
            ->max('school_year');

        return SchoolYear::query()->forceCreate([
            'school_id' => $schoolId,
            'year' => $groupsYear !== null ? (int) $groupsYear : $today,
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
        $this->activeWindows = [];
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
        $schoolId = (int) $group->school_id;
        $key = $schoolId.'|'.SchoolTime::today()->toDateString();

        $this->activeWindows[$key] ??= GroupWriteWindow::query()
            ->where('school_id', $schoolId)
            ->active()
            ->orderByDesc('until_on')
            ->orderByDesc('id')
            ->get()
            ->groupBy('group_id');

        return $this->activeWindows[$key]->get($group->id)?->first();
    }

    /** Whether writes to the group's data must be rejected. */
    public function isReadOnly(Group $group): bool
    {
        return $this->isInClosedYear($group) && $this->activeWindow($group) === null;
    }
}
