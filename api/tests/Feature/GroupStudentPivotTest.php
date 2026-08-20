<?php

use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('enforces a single group per student per school year', function () {
    $school = School::factory()->create();
    $groupA = Group::factory()->create(['school_id' => $school->id, 'school_year' => 2026]);
    $groupB = Group::factory()->create(['school_id' => $school->id, 'school_year' => 2026]);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $student->groups()->attach($groupA, ['school_year' => 2026]);

    expect(fn () => DB::table('group_student')->insert([
        'group_id' => $groupB->id,
        'student_id' => $student->id,
        'school_year' => 2026,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('lets the same student join a different group in a different school year', function () {
    $school = School::factory()->create();
    $group2025 = Group::factory()->create(['school_id' => $school->id, 'school_year' => 2025]);
    $group2026 = Group::factory()->create(['school_id' => $school->id, 'school_year' => 2026]);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $student->groups()->attach($group2025, ['school_year' => 2025]);
    $student->groups()->attach($group2026, ['school_year' => 2026]);

    expect($student->groups()->count())->toBe(2);
});
