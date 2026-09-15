<?php

use App\Models\Accommodation;
use App\Models\Alert;
use App\Models\Barrier;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// docs/prompts/22-grupos-listado.md — the groups listing (GET /api/groups,
// NOT under the v1 prefix) carries an aggregate `active_tracking_count` per
// group: how many of its students have active tracking. Aggregate only,
// never a roster, visible to any role that sees the listing.

/**
 * Enrol a student in a group for the current school year (group_student pivot).
 */
function enrolInGroup(Student $student, Group $group): void
{
    $student->groups()->attach($group, ['school_year' => now()->year]);
}

it('counts a student with an effective accommodation AND an open alert only once', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($student, $group);

    // Same student matches two sources; the count is a union of students,
    // not a sum of matches, so this must be 1, not 2.
    Accommodation::factory()->create(['student_id' => $student->id, 'active' => true]);
    Alert::factory()->create(['student_id' => $student->id, 'resolved' => false]);

    Sanctum::actingAs($director);

    $this->getJson('/api/groups')
        ->assertOk()
        ->assertJsonPath('data.0.active_tracking_count', 1);
});

it('reports 0 (present, not null or absent) for a group with no tracked students', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    // A student with no active tracking at all still yields 0.
    $student = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($student, $group);

    Sanctum::actingAs($director);

    $data = $this->getJson('/api/groups')->assertOk()->json('data.0');

    expect($data)->toHaveKey('active_tracking_count')
        ->and($data['active_tracking_count'])->toBe(0);
});

it('includes the column for a teacher who leads the group', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($student, $group);
    Barrier::factory()->create(['student_id' => $student->id, 'active' => true]);

    Sanctum::actingAs($teacher);

    $this->getJson('/api/groups')
        ->assertOk()
        ->assertJsonPath('data.0.active_tracking_count', 1);
});

it('counts each tracking source and ignores inactive/resolved rows', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    // One student per source, each in the "active tracking" state → 4 counted.
    $accStudent = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($accStudent, $group);
    Accommodation::factory()->create(['student_id' => $accStudent->id, 'active' => true]);

    $barrierStudent = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($barrierStudent, $group);
    Barrier::factory()->create(['student_id' => $barrierStudent->id, 'active' => true]);

    $alertStudent = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($alertStudent, $group);
    Alert::factory()->create(['student_id' => $alertStudent->id, 'resolved' => false]);

    $followUpStudent = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($followUpStudent, $group);
    ScheduledFollowUp::factory()->create(['student_id' => $followUpStudent->id, 'resolved' => false]);

    // Students whose only tracking is NOT active → not counted.
    $inactiveStudent = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($inactiveStudent, $group);
    Accommodation::factory()->create(['student_id' => $inactiveStudent->id, 'active' => false]);
    Barrier::factory()->create(['student_id' => $inactiveStudent->id, 'active' => false]);
    Alert::factory()->resolved()->create(['student_id' => $inactiveStudent->id]);
    ScheduledFollowUp::factory()->resolved()->create(['student_id' => $inactiveStudent->id]);

    Sanctum::actingAs($director);

    $this->getJson('/api/groups')
        ->assertOk()
        ->assertJsonPath('data.0.active_tracking_count', 4);
});

it('treats an accommodation pending external approval as not effective', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    // Active but awaiting external approval (approved null) → NOT effective.
    $pendingStudent = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($pendingStudent, $group);
    Accommodation::factory()->create([
        'student_id' => $pendingStudent->id,
        'active' => true,
        'requires_external_approval' => true,
        'approved' => null,
    ]);

    // Active, requires approval, and it was granted → effective.
    $approvedStudent = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($approvedStudent, $group);
    Accommodation::factory()->create([
        'student_id' => $approvedStudent->id,
        'active' => true,
        'requires_external_approval' => true,
        'approved' => true,
    ]);

    Sanctum::actingAs($director);

    // Only the approved one counts.
    $this->getJson('/api/groups')
        ->assertOk()
        ->assertJsonPath('data.0.active_tracking_count', 1);
});

it('never exposes student names or clinical detail, only the count', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Ana Gómez']);
    enrolInGroup($student, $group);
    Accommodation::factory()->create([
        'student_id' => $student->id,
        'active' => true,
        'description' => 'Sensitive clinical note about the minor',
    ]);

    Sanctum::actingAs($director);

    $body = $this->getJson('/api/groups')->assertOk()->getContent();

    expect($body)->not->toContain('Ana Gómez')
        ->and($body)->not->toContain('Sensitive clinical note')
        ->and($body)->not->toContain('students');
});

it('does not count tracking that belongs to another school', function () {
    $school = School::factory()->create();
    $otherSchool = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();

    $group = Group::factory()->create(['school_id' => $school->id]);
    $ourStudent = Student::factory()->create(['school_id' => $school->id]);
    enrolInGroup($ourStudent, $group);
    Alert::factory()->create(['student_id' => $ourStudent->id, 'resolved' => false]);

    // A group + tracked student living entirely in another tenant.
    $otherGroup = Group::factory()->create(['school_id' => $otherSchool->id]);
    $otherStudent = Student::factory()->create(['school_id' => $otherSchool->id]);
    $otherStudent->groups()->attach($otherGroup, ['school_year' => now()->year]);
    Alert::factory()->create(['student_id' => $otherStudent->id, 'resolved' => false]);

    Sanctum::actingAs($director);

    $response = $this->getJson('/api/groups')->assertOk();

    // Only our school's group is visible, and its count reflects only our data.
    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $group->id)
        ->assertJsonPath('data.0.active_tracking_count', 1);
});

it('computes the counts without an N+1: query count is independent of group count', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $makeTrackedGroup = function () use ($school): void {
        $group = Group::factory()->create(['school_id' => $school->id]);
        $student = Student::factory()->create(['school_id' => $school->id]);
        $student->groups()->attach($group, ['school_year' => now()->year]);
        Accommodation::factory()->create(['student_id' => $student->id, 'active' => true]);
        Alert::factory()->create(['student_id' => $student->id, 'resolved' => false]);
    };

    // Baseline: 2 groups.
    $makeTrackedGroup();
    $makeTrackedGroup();

    // Warm up caches shared across requests (e.g. the Spatie permission
    // cache) so the two measured requests differ only in group count.
    $this->getJson('/api/groups')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/groups')->assertOk()->assertJsonCount(2, 'data');
    $baseline = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    // Add four more tracked groups (with logging OFF so the factory inserts
    // don't pollute the measurement). If the count were computed per group,
    // the next request's query count would climb; it must stay flat.
    $makeTrackedGroup();
    $makeTrackedGroup();
    $makeTrackedGroup();
    $makeTrackedGroup();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/groups')->assertOk()->assertJsonCount(6, 'data');
    $scaled = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($scaled)->toBe($baseline);
});
