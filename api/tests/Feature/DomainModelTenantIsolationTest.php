<?php

use App\Models\Accommodation;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\Barrier;
use App\Models\CalendarEvent;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\TechnicalReport;
use App\Models\Unit;
use App\Support\Tenancy;

afterEach(fn () => Tenancy::forget());

/**
 * The most important test in this session: every tenant-scoped domain model
 * introduced here must stay invisible across schools, not just Group/Student.
 */
it('never leaks a domain record across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    Tenancy::useSchool($schoolA);
    $groupA = Group::factory()->create(['school_id' => $schoolA->id]);
    $studentA = Student::factory()->create(['school_id' => $schoolA->id]);
    $annualPlanA = AnnualPlan::factory()->create(['group_id' => $groupA->id]);
    Unit::factory()->create(['annual_plan_id' => $annualPlanA->id]);
    Assessment::factory()->create(['group_id' => $groupA->id]);
    ClassSession::factory()->create(['group_id' => $groupA->id]);
    Accommodation::factory()->create(['student_id' => $studentA->id]);
    Barrier::factory()->create(['student_id' => $studentA->id]);
    TechnicalReport::factory()->create(['student_id' => $studentA->id]);
    CalendarEvent::factory()->create(['school_id' => $schoolA->id]);

    Tenancy::useSchool($schoolB);
    Group::factory()->create(['school_id' => $schoolB->id]);
    Student::factory()->create(['school_id' => $schoolB->id]);
    CalendarEvent::factory()->create(['school_id' => $schoolB->id]);

    Tenancy::useSchool($schoolA);

    expect(Group::count())->toBe(1)
        ->and(Student::count())->toBe(1)
        ->and(AnnualPlan::count())->toBe(1)
        ->and(Assessment::count())->toBe(1)
        ->and(ClassSession::count())->toBe(1)
        ->and(Accommodation::count())->toBe(1)
        ->and(Barrier::count())->toBe(1)
        ->and(TechnicalReport::count())->toBe(1)
        ->and(CalendarEvent::count())->toBe(1);

    Tenancy::useSchool($schoolB);

    expect(Group::count())->toBe(1)
        ->and(Student::count())->toBe(1)
        ->and(AnnualPlan::count())->toBe(0)
        ->and(Assessment::count())->toBe(0)
        ->and(ClassSession::count())->toBe(0)
        ->and(Accommodation::count())->toBe(0)
        ->and(Barrier::count())->toBe(0)
        ->and(TechnicalReport::count())->toBe(0)
        ->and(CalendarEvent::count())->toBe(1);
});
