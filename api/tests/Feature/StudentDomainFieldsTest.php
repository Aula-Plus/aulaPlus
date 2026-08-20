<?php

use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Support\Tenancy;

it('stores the domain profile fields on a student', function () {
    $school = School::factory()->create();

    $student = Tenancy::forSchool($school, fn () => Student::create([
        'full_name' => 'Ana Gómez',
        'birth_date' => '2015-03-10',
        'enrollment_year' => 2026,
        'has_therapeutic_companion' => true,
        'learning_profile' => ['style' => 'visual'],
        'tracking_notes' => 'Necesita seguimiento en lectoescritura.',
        'individual_profile' => ['pei' => true],
        'related_documents' => ['reports/informe.pdf'],
    ]));

    expect($student->full_name)->toBe('Ana Gómez')
        ->and($student->has_therapeutic_companion)->toBeTrue()
        ->and($student->learning_profile)->toBe(['style' => 'visual'])
        ->and($student->fresh()->individual_profile)->toBe(['pei' => true])
        ->and($student->fresh()->related_documents)->toBe(['reports/informe.pdf']);
});

it('soft deletes a student instead of removing the row', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    $student->delete();

    expect(Student::find($student->id))->toBeNull()
        ->and(Student::withTrashed()->find($student->id))->not->toBeNull();
});

it('tracks a student\'s group membership per school year through the group_student pivot', function () {
    $school = School::factory()->create();
    $group2025 = Group::factory()->create(['school_id' => $school->id, 'school_year' => 2025]);
    $group2026 = Group::factory()->create(['school_id' => $school->id, 'school_year' => 2026]);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $student->groups()->attach($group2025, ['school_year' => 2025]);
    $student->groups()->attach($group2026, ['school_year' => 2026]);

    expect($student->groups()->count())->toBe(2)
        ->and($student->groupForYear(2025)->id)->toBe($group2025->id)
        ->and($student->groupForYear(2026)->id)->toBe($group2026->id);
});
