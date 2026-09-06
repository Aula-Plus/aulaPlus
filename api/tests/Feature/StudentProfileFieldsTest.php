<?php

use App\Enums\StudentStatus;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Support\Tenancy;

it('defaults a new student to active status and casts it to the enum', function () {
    $school = School::factory()->create();

    $student = Tenancy::forSchool($school, fn () => Student::create([
        'first_name' => 'Ana',
        'last_name' => 'Gómez',
    ]));

    expect($student->status)->toBe(StudentStatus::Active)
        ->and($student->fresh()->status)->toBe(StudentStatus::Active);
});

it('stores the new profile fields on a student', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    $student = Tenancy::forSchool($school, fn () => Student::create([
        'first_name' => 'Ana',
        'last_name' => 'Gómez',
        'group_id' => $group->id,
        'status' => StudentStatus::Inactive->value,
        'family_contact_name' => 'Marcela Gómez',
        'family_contact_phone' => '+54 9 11 5555-5555',
        'family_contact_email' => 'marcela@example.com',
        'pedagogical_notes' => 'Necesita seguimiento en lectoescritura.',
    ]));

    expect($student->status)->toBe(StudentStatus::Inactive)
        ->and($student->family_contact_name)->toBe('Marcela Gómez')
        ->and($student->pedagogical_notes)->toBe('Necesita seguimiento en lectoescritura.');
});
