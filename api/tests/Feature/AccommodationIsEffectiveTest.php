<?php

use App\Models\Accommodation;
use App\Models\School;
use App\Models\Student;

it('isEffective is false for a pending external approval and true once approved', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'active' => true,
        'requires_external_approval' => true,
        'approved' => null,
    ]);

    expect($accommodation->isEffective())->toBeFalse();

    $accommodation->approved = true;

    expect($accommodation->isEffective())->toBeTrue();
});

it('isEffective is false once rejected, and false when inactive even if approved', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    $rejected = Accommodation::factory()->create([
        'student_id' => $student->id,
        'active' => true,
        'requires_external_approval' => true,
        'approved' => false,
    ]);

    $inactiveApproved = Accommodation::factory()->create([
        'student_id' => $student->id,
        'active' => false,
        'requires_external_approval' => true,
        'approved' => true,
    ]);

    expect($rejected->isEffective())->toBeFalse()
        ->and($inactiveApproved->isEffective())->toBeFalse();
});

it('isEffective is true for an active accommodation that never required external approval', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'active' => true,
        'requires_external_approval' => false,
        'approved' => null,
    ]);

    expect($accommodation->isEffective())->toBeTrue();
});
