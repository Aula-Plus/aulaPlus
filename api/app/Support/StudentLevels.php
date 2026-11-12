<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The levels of each student's groups in a school year (ClickUp 1dfd3mc2wx0),
 * for the alert generators: a condition only fires for students whose group
 * is at one of the levels it applies to. Groups "Sin nivel" are left out, so a
 * student with no levelled group this year is in no alert.
 *
 * Tenant-scoped by hand (this is a raw pivot query, not a model): the caller
 * must have set the tenant (Tenancy::forSchool).
 */
class StudentLevels
{
    /**
     * @return array<int, list<int>> student id => education level ids
     */
    public static function forYear(int $schoolYear): array
    {
        $levels = [];

        DB::table('group_student')
            ->join('groups', 'groups.id', '=', 'group_student.group_id')
            ->where('groups.school_id', Tenancy::schoolId())
            ->where('group_student.school_year', $schoolYear)
            ->whereNotNull('groups.education_level_id')
            ->select(['group_student.student_id', 'groups.education_level_id'])
            ->distinct()
            ->get()
            ->each(function (object $row) use (&$levels): void {
                $levels[(int) $row->student_id][] = (int) $row->education_level_id;
            });

        return $levels;
    }
}
