<?php

use App\Enums\CommentTone;
use App\Models\Accommodation;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Barrier;
use App\Models\CalendarEvent;
use App\Models\Comment;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// docs/prompts/21-perfil-de-grupo.md §2: the group performance line (one point
// per assessment, aggregated marks by (type, date), never a student
// identifier), with per-type authorization mirroring the student timeline.

it('emits one point per assessment with a loaded result and skips those with none', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $students = Student::factory()->count(3)->create(['school_id' => $school->id]);
    foreach ($students as $student) {
        $student->groups()->attach($group, ['school_year' => now()->year]);
    }

    $graded = Assessment::factory()->create([
        'group_id' => $group->id,
        'type' => 'written',
        'administered_at' => '2026-05-10',
    ]);
    // Two students graded 6 and 8 → average 7.0, results_count 2 (third has no
    // score loaded yet, so it doesn't inflate results_count).
    AssessmentResult::factory()->create(['assessment_id' => $graded->id, 'student_id' => $students[0]->id, 'score' => 6]);
    AssessmentResult::factory()->create(['assessment_id' => $graded->id, 'student_id' => $students[1]->id, 'score' => 8]);

    // An assessment with no results at all → no point.
    Assessment::factory()->create(['group_id' => $group->id, 'administered_at' => '2026-05-12']);

    Sanctum::actingAs($director);
    $response = $this->getJson("/api/v1/groups/{$group->id}/performance-timeline")->assertOk();

    $results = collect($response->json('results'));
    expect($results)->toHaveCount(1);
    expect($results->first())->toMatchArray([
        'assessment_id' => $graded->id,
        'assessment_type' => 'written',
        'administered_at' => '2026-05-10',
        'average_score' => 7.0,
        'results_count' => 2,
    ]);
});

it('never includes a student_id in any mark', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'tiempo extra', 'active' => true]);
    Barrier::factory()->create(['student_id' => $student->id, 'active' => true]);
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create();
    CalendarEvent::factory()->create(['school_id' => $school->id, 'title' => 'Semana de exámenes']);

    Sanctum::actingAs($director);
    $response = $this->getJson("/api/v1/groups/{$group->id}/performance-timeline")->assertOk();

    $marks = collect($response->json('marks'));
    expect($marks)->not->toBeEmpty();
    foreach ($marks as $mark) {
        expect($mark)->not->toHaveKey('student_id');
    }
    expect($response->getContent())->not->toContain('student_id');
});

it('aggregates accommodation activations by type and date, counting distinct students', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $ana = Student::factory()->create(['school_id' => $school->id]);
    $beto = Student::factory()->create(['school_id' => $school->id]);
    $ana->groups()->attach($group, ['school_year' => now()->year]);
    $beto->groups()->attach($group, ['school_year' => now()->year]);

    // Two students activated "tiempo extra" on the same day → count 2 (distinct
    // students), aggregated into a single mark.
    Accommodation::factory()->create(['student_id' => $ana->id, 'type' => 'tiempo extra', 'active' => true, 'created_at' => '2026-04-01 09:00:00']);
    Accommodation::factory()->create(['student_id' => $beto->id, 'type' => 'tiempo extra', 'active' => true, 'created_at' => '2026-04-01 15:00:00']);

    Sanctum::actingAs($director);
    $marks = collect($this->getJson("/api/v1/groups/{$group->id}/performance-timeline")->assertOk()->json('marks'));

    $activation = $marks->firstWhere('type', 'accommodation_activated');
    expect($activation)->toMatchArray([
        'type' => 'accommodation_activated',
        'date' => '2026-04-01',
        'accommodation_type' => 'tiempo extra',
        'count' => 2,
    ]);
});

it('derives accommodation_deactivated from the audit trail when active flips true to false', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    $stays = Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'apoyo visual', 'active' => true]);
    $turnedOff = Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'tiempo extra', 'active' => true]);

    // Only this one is deactivated (active true → false) → the only mark.
    $turnedOff->update(['active' => false]);
    // A non-active-flip update must NOT produce a deactivation mark.
    $stays->update(['focus_area' => 'attention']);

    $marks = collect($this->getJson("/api/v1/groups/{$group->id}/performance-timeline")->assertOk()->json('marks'));

    $deactivations = $marks->where('type', 'accommodation_deactivated');
    expect($deactivations)->toHaveCount(1);
    expect($deactivations->first())->toMatchArray([
        'type' => 'accommodation_deactivated',
        'accommodation_type' => 'tiempo extra',
        'count' => 1,
    ]);
});

it('hides clinical marks from a teacher without view-clinical-profile but keeps results, calendar and comments', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    // Clinical sources — must be absent for the teacher.
    Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'tiempo extra', 'active' => true]);
    Barrier::factory()->create(['student_id' => $student->id, 'active' => true]);
    // Non-clinical sources — must be present.
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'administered_at' => '2026-03-01']);
    AssessmentResult::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 9]);
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create(); // visible_to null → visible to all roles
    CalendarEvent::factory()->create(['school_id' => $school->id, 'title' => 'Feriado']);

    Sanctum::actingAs($teacher);
    $response = $this->getJson("/api/v1/groups/{$group->id}/performance-timeline")->assertOk();

    $markTypes = collect($response->json('marks'))->pluck('type')->unique();
    expect($markTypes)
        ->not->toContain('accommodation_activated')
        ->not->toContain('accommodation_deactivated')
        ->not->toContain('barrier_registered')
        ->toContain('concerning_comment')
        ->toContain('calendar_event');
    expect($response->json('results'))->toHaveCount(1);
});

it('omits a concerning_comment that is author_only for anyone but its author', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->authorOnly()
        ->create(['author_id' => $psychopedagogue->id]);

    // The director is not the author → no concerning_comment mark.
    Sanctum::actingAs($director);
    $directorMarks = collect($this->getJson("/api/v1/groups/{$group->id}/performance-timeline")->assertOk()->json('marks'));
    expect($directorMarks->pluck('type'))->not->toContain('concerning_comment');

    // The author sees their own author_only comment.
    Sanctum::actingAs($psychopedagogue);
    $authorMarks = collect($this->getJson("/api/v1/groups/{$group->id}/performance-timeline")->assertOk()->json('marks'));
    expect($authorMarks->pluck('type'))->toContain('concerning_comment');
});

it('filters results and marks by the from/to window', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    $inRange = Assessment::factory()->create(['group_id' => $group->id, 'administered_at' => '2026-06-15']);
    AssessmentResult::factory()->create(['assessment_id' => $inRange->id, 'student_id' => $student->id, 'score' => 7]);
    $outOfRange = Assessment::factory()->create(['group_id' => $group->id, 'administered_at' => '2026-01-01']);
    AssessmentResult::factory()->create(['assessment_id' => $outOfRange->id, 'student_id' => $student->id, 'score' => 5]);

    CalendarEvent::factory()->create(['school_id' => $school->id, 'title' => 'Dentro', 'start_at' => '2026-06-10 10:00:00']);
    CalendarEvent::factory()->create(['school_id' => $school->id, 'title' => 'Fuera', 'start_at' => '2026-12-01 10:00:00']);

    Sanctum::actingAs($director);
    $response = $this->getJson("/api/v1/groups/{$group->id}/performance-timeline?from=2026-06-01&to=2026-06-30")->assertOk();

    expect(collect($response->json('results'))->pluck('assessment_id')->all())->toBe([$inRange->id]);
    $calendarTitles = collect($response->json('marks'))->where('type', 'calendar_event')->pluck('title');
    expect($calendarTitles)->toContain('Dentro')->not->toContain('Fuera');
});

it('rejects an invalid date window', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    Sanctum::actingAs($director);
    $this->getJson("/api/v1/groups/{$group->id}/performance-timeline?from=2026-06-30&to=2026-06-01")
        ->assertStatus(422);
});

it('forbids a teacher who does not lead the group from the timeline', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/groups/{$group->id}/performance-timeline")->assertForbidden();
});
