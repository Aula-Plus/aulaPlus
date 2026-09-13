<?php

namespace App\Actions\Screening;

use App\Models\Group;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestResult;
use App\Models\ScreeningTestType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a ScreeningTestApplication for a group and, in the same transaction,
 * one ScreeningTestResult per active student (docs/prompts/
 * 11-pruebas-de-sondeo.md §2).
 *
 * The per-student `code` is assigned sequentially ("01", "02", …) in
 * alphabetical order of `full_name`, resolved at creation time. It is unique
 * only WITHIN the application — every new application renumbers from "01" —
 * and is never derived from the student's id or name, so it stays anonymous.
 *
 * "Active student of the group" = a currently-enrolled, non-withdrawn student.
 * The domain model has no `status` column (a withdrawn student is soft-deleted
 * — see the students-table refactor migration), so soft-deleted students are
 * already excluded by the SoftDeletes global scope on Student.
 */
class CreateScreeningTestApplication
{
    public function __invoke(
        Group $group,
        ScreeningTestType $type,
        User $appliedBy,
        ?string $applicationDate = null,
    ): ScreeningTestApplication {
        return DB::transaction(function () use ($group, $type, $appliedBy, $applicationDate): ScreeningTestApplication {
            $application = ScreeningTestApplication::create([
                'screening_test_type_id' => $type->id,
                'group_id' => $group->id,
                'applied_by_id' => $appliedBy->id,
                'application_date' => $applicationDate ?? now()->toDateString(),
            ]);

            $students = $group->students()
                ->orderBy('students.full_name')
                ->orderBy('students.id')
                ->get();

            foreach ($students->values() as $index => $student) {
                ScreeningTestResult::create([
                    'screening_test_application_id' => $application->id,
                    'student_id' => $student->id,
                    'code' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                ]);
            }

            return $application;
        });
    }
}
