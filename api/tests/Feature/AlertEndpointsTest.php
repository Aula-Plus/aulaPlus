<?php

use App\Models\Alert;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets a psychopedagogue resolve an open alert', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $alert = Alert::factory()->create(['student_id' => $student->id, 'resolved' => false]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/alerts/{$alert->id}/resolve")
        ->assertOk()
        ->assertJsonPath('data.resolved', true)
        ->assertJsonPath('data.resolved_by_id', $psychopedagogue->id);

    expect($alert->fresh()->resolved)->toBeTrue();
});

it('rejects a teacher (even one who teaches the student) from resolving an alert', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    $alert = Alert::factory()->create(['student_id' => $student->id, 'resolved' => false]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/alerts/{$alert->id}/resolve")->assertForbidden();
    expect($alert->fresh()->resolved)->toBeFalse();
});

it('lets a director list alerts for a student and for a group', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Alert::factory()->create(['student_id' => $student->id, 'resolved' => false]);
    Sanctum::actingAs($director);

    $this->getJson("/api/v1/students/{$student->id}/alerts")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/groups/{$group->id}/alerts")->assertOk()->assertJsonCount(1, 'data');
});

it('forbids a teacher from listing alerts for a student or group', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Alert::factory()->create(['student_id' => $student->id, 'resolved' => false]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/students/{$student->id}/alerts")->assertForbidden();
    $this->getJson("/api/v1/groups/{$group->id}/alerts")->assertForbidden();
});
