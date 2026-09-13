<?php

use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// docs/prompts/21-perfil-de-grupo.md §4 + §5: the group's scheduled follow-ups.
// A teacher who leads the group sees the follow-ups of ALL its students; a
// teacher who does not lead the group can't reach the endpoint at all (403,
// GroupPolicy::view). Group membership is whole-group (no per-subject split),
// so "teaches some students but not others" does not occur in the model.

it('lets a teacher leading the group see follow-ups of all its students', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);

    $ana = Student::factory()->create(['school_id' => $school->id]);
    $beto = Student::factory()->create(['school_id' => $school->id]);
    $ana->groups()->attach($group, ['school_year' => now()->year]);
    $beto->groups()->attach($group, ['school_year' => now()->year]);

    $followUpAna = ScheduledFollowUp::factory()->create(['student_id' => $ana->id, 'created_by_id' => $teacher->id]);
    $followUpBeto = ScheduledFollowUp::factory()->create(['student_id' => $beto->id, 'created_by_id' => $teacher->id]);

    // A follow-up on a student outside the group must not appear.
    $other = Student::factory()->create(['school_id' => $school->id]);
    ScheduledFollowUp::factory()->create(['student_id' => $other->id, 'created_by_id' => $teacher->id]);

    Sanctum::actingAs($teacher);
    $response = $this->getJson("/api/v1/groups/{$group->id}/scheduled-follow-ups")->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toHaveCount(2)
        ->toContain($followUpAna->id)
        ->toContain($followUpBeto->id);
});

it('forbids a teacher who does not lead the group', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    ScheduledFollowUp::factory()->create(['student_id' => $student->id]);

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/groups/{$group->id}/scheduled-follow-ups")->assertForbidden();
});

it('narrows to overdue follow-ups with ?overdue=true', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    $overdue = ScheduledFollowUp::factory()->create([
        'student_id' => $student->id,
        'due_date' => now()->subWeek()->toDateString(),
        'resolved' => false,
    ]);
    // Not overdue: due in the future.
    ScheduledFollowUp::factory()->create([
        'student_id' => $student->id,
        'due_date' => now()->addWeek()->toDateString(),
        'resolved' => false,
    ]);
    // Not overdue: past due but already resolved.
    ScheduledFollowUp::factory()->create([
        'student_id' => $student->id,
        'due_date' => now()->subWeek()->toDateString(),
        'resolved' => true,
    ]);

    Sanctum::actingAs($director);
    $response = $this->getJson("/api/v1/groups/{$group->id}/scheduled-follow-ups?overdue=true")->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toHaveCount(1)->toContain($overdue->id);
});

it('returns every follow-up when overdue is not requested', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    ScheduledFollowUp::factory()->create(['student_id' => $student->id, 'due_date' => now()->subWeek()->toDateString(), 'resolved' => false]);
    ScheduledFollowUp::factory()->create(['student_id' => $student->id, 'due_date' => now()->addWeek()->toDateString(), 'resolved' => false]);

    Sanctum::actingAs($director);
    $this->getJson("/api/v1/groups/{$group->id}/scheduled-follow-ups")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
