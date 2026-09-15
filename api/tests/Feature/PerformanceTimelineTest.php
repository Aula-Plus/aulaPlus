<?php

use App\Enums\CommentTone;
use App\Models\Accommodation;
use App\Models\AccommodationInstanceOverride;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Barrier;
use App\Models\CalendarEvent;
use App\Models\Comment;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * GET /api/v1/students/{student}/performance-timeline
 * (docs/prompts/20-linea-tiempo-alumno.md §2). Read-only aggregator: the
 * results line + a single `marks` array whose contents vary by two orthogonal
 * authorization axes (the clinical-profile gate and comment visibility).
 */

/**
 * A student enrolled in a group led by a teacher, all in one school.
 *
 * @return array{school: School, teacher: User, group: Group, student: Student}
 */
function timelineScenario(): array
{
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    return compact('school', 'teacher', 'group', 'student');
}

function markTypes(array $marks): array
{
    return array_map(fn (array $mark) => $mark['type'], $marks);
}

it('gives a teacher without clinical access the results, calendar events and visible concerning comments, but no clinical marks', function () {
    ['school' => $school, 'teacher' => $teacher, 'group' => $group, 'student' => $student] = timelineScenario();

    // A result on the performance line.
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id, 'administered_at' => '2026-03-10']);
    AssessmentResult::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'created_by_id' => $teacher->id, 'score' => 80]);

    // Clinical sources — must be hidden from a plain teacher.
    Accommodation::factory()->create(['student_id' => $student->id, 'created_by_id' => $teacher->id]);
    Barrier::factory()->create(['student_id' => $student->id, 'created_by_id' => $teacher->id]);

    // A school-wide calendar event (never gated).
    CalendarEvent::factory()->create(['school_id' => $school->id, 'title' => 'Período de exámenes', 'start_at' => '2026-03-05 08:00:00']);

    // A concerning comment visible to everyone (visible_to null): the teacher
    // sees it even without the clinical gate.
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create(['author_id' => $teacher->id]);

    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/students/{$student->id}/performance-timeline")->assertOk();

    expect($response->json('results'))->toHaveCount(1);

    $types = markTypes($response->json('marks'));
    expect($types)->toContain('calendar_event')
        ->toContain('concerning_comment')
        ->not->toContain('accommodation_activated')
        ->not->toContain('accommodation_deactivated')
        ->not->toContain('accommodation_instance_override')
        ->not->toContain('barrier_registered');
});

it('always returns the marks key as an array', function () {
    ['teacher' => $teacher, 'student' => $student] = timelineScenario();
    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/students/{$student->id}/performance-timeline")->assertOk();

    expect($response->json('marks'))->toBeArray();
    $response->assertJsonPath('marks', []);
});

it('gives a psychopedagogue every clinical mark type', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    $accommodation = Accommodation::factory()->create(['student_id' => $student->id, 'created_by_id' => $psychopedagogue->id, 'active' => true]);
    $accommodation->update(['active' => false]); // audit log → accommodation_deactivated

    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $psychopedagogue->id, 'administered_at' => '2026-03-10']);
    AccommodationInstanceOverride::factory()->create([
        'accommodation_id' => $accommodation->id,
        'assessment_id' => $assessment->id,
        'deactivated_by_id' => $psychopedagogue->id,
    ]);
    Barrier::factory()->create(['student_id' => $student->id, 'created_by_id' => $psychopedagogue->id]);

    Sanctum::actingAs($psychopedagogue);

    $response = $this->getJson("/api/v1/students/{$student->id}/performance-timeline")->assertOk();
    $types = markTypes($response->json('marks'));

    expect($types)->toContain('accommodation_activated')
        ->toContain('accommodation_deactivated')
        ->toContain('accommodation_instance_override')
        ->toContain('barrier_registered');

    // The override reason (clinical content) is only ever exposed here.
    $override = collect($response->json('marks'))->firstWhere('type', 'accommodation_instance_override');
    expect($override)->toHaveKey('reason')
        ->and($override['assessment_id'])->toBe($assessment->id);
});

it('derives accommodation_deactivated from the audit log when active flips true to false, with the log date', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id, 'created_by_id' => $director->id, 'active' => true]);

    $this->travelTo(Carbon::parse('2026-04-15 09:30:00'));
    $accommodation->update(['active' => false]);
    $this->travelBack();

    Sanctum::actingAs($director);

    $marks = $this->getJson("/api/v1/students/{$student->id}/performance-timeline")->assertOk()->json('marks');

    $deactivation = collect($marks)->firstWhere('type', 'accommodation_deactivated');
    expect($deactivation)->not->toBeNull()
        ->and($deactivation['accommodation_id'])->toBe($accommodation->id)
        ->and(Carbon::parse($deactivation['date'])->toDateString())->toBe('2026-04-15');
});

it('does not emit accommodation_deactivated when the accommodation was never deactivated', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    // Created active and updated in a way that does NOT touch `active`.
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id, 'created_by_id' => $director->id, 'active' => true]);
    $accommodation->update(['focus_area' => 'attention']);

    Sanctum::actingAs($director);

    $marks = $this->getJson("/api/v1/students/{$student->id}/performance-timeline")->assertOk()->json('marks');

    expect(markTypes($marks))->not->toContain('accommodation_deactivated');
});

it('hides an author_only concerning comment from another user even when concerning', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    // Private to its author (the psychopedagogue).
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->authorOnly()
        ->create(['author_id' => $psychopedagogue->id]);

    // The director requests the timeline: must not see the author-only comment.
    Sanctum::actingAs($director);
    $marks = $this->getJson("/api/v1/students/{$student->id}/performance-timeline")->assertOk()->json('marks');
    expect(markTypes($marks))->not->toContain('concerning_comment');

    // The author does see it.
    Sanctum::actingAs($psychopedagogue);
    $marks = $this->getJson("/api/v1/students/{$student->id}/performance-timeline")->assertOk()->json('marks');
    expect(markTypes($marks))->toContain('concerning_comment');
});

it('only counts concerning-toned comments, not positive or neutral ones', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    Comment::factory()->forSubject($student)->tone(CommentTone::Positive)->create(['author_id' => $director->id]);
    Comment::factory()->forSubject($student)->tone(CommentTone::Neutral)->create(['author_id' => $director->id]);
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create(['author_id' => $director->id]);

    Sanctum::actingAs($director);
    $marks = $this->getJson("/api/v1/students/{$student->id}/performance-timeline")->assertOk()->json('marks');

    expect(collect(markTypes($marks))->filter(fn ($t) => $t === 'concerning_comment'))->toHaveCount(1);
});

it('filters all six sources by from and to', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    $in = '2026-03-15 10:00:00';
    $out = '2026-06-15 10:00:00';

    // 1. results — filtered by the assessment's administered_at.
    $assessmentIn = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $director->id, 'administered_at' => '2026-03-15']);
    $assessmentOut = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $director->id, 'administered_at' => '2026-06-15']);
    AssessmentResult::factory()->create(['assessment_id' => $assessmentIn->id, 'student_id' => $student->id, 'created_by_id' => $director->id, 'score' => 70]);
    AssessmentResult::factory()->create(['assessment_id' => $assessmentOut->id, 'student_id' => $student->id, 'created_by_id' => $director->id, 'score' => 71]);

    // 2. accommodation_activated — by created_at.
    Accommodation::factory()->create(['student_id' => $student->id, 'created_by_id' => $director->id, 'created_at' => $in]);
    Accommodation::factory()->create(['student_id' => $student->id, 'created_by_id' => $director->id, 'created_at' => $out]);

    // 3. accommodation_deactivated — by the audit log's created_at.
    foreach (['2026-03-15 11:00:00' => true, '2026-06-15 11:00:00' => false] as $when => $inWindow) {
        $acc = Accommodation::factory()->create(['student_id' => $student->id, 'created_by_id' => $director->id, 'active' => true, 'created_at' => '2026-01-01']);
        $this->travelTo(Carbon::parse($when));
        $acc->update(['active' => false]);
        $this->travelBack();
    }

    // 4. accommodation_instance_override — by created_at.
    $ovAcc = Accommodation::factory()->create(['student_id' => $student->id, 'created_by_id' => $director->id, 'created_at' => '2026-01-01']);
    AccommodationInstanceOverride::factory()->create(['accommodation_id' => $ovAcc->id, 'assessment_id' => $assessmentIn->id, 'deactivated_by_id' => $director->id, 'created_at' => $in]);
    AccommodationInstanceOverride::factory()->create(['accommodation_id' => $ovAcc->id, 'assessment_id' => $assessmentOut->id, 'deactivated_by_id' => $director->id, 'created_at' => $out]);

    // 5. barrier_registered — by created_at.
    Barrier::factory()->create(['student_id' => $student->id, 'created_by_id' => $director->id, 'created_at' => $in]);
    Barrier::factory()->create(['student_id' => $student->id, 'created_by_id' => $director->id, 'created_at' => $out]);

    // 6. concerning_comment — by created_at.
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create(['author_id' => $director->id, 'created_at' => $in]);
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create(['author_id' => $director->id, 'created_at' => $out]);

    // 7. calendar_event — by start_at.
    CalendarEvent::factory()->create(['school_id' => $school->id, 'start_at' => $in]);
    CalendarEvent::factory()->create(['school_id' => $school->id, 'start_at' => $out]);

    Sanctum::actingAs($director);

    $response = $this->getJson("/api/v1/students/{$student->id}/performance-timeline?from=2026-03-01&to=2026-03-31")->assertOk();

    // Exactly one result, and exactly one mark of each of the six mark types.
    expect($response->json('results'))->toHaveCount(1);

    $counts = collect(markTypes($response->json('marks')))->countBy();
    expect($counts['accommodation_activated'] ?? 0)->toBe(1)
        ->and($counts['accommodation_deactivated'] ?? 0)->toBe(1)
        ->and($counts['accommodation_instance_override'] ?? 0)->toBe(1)
        ->and($counts['barrier_registered'] ?? 0)->toBe(1)
        ->and($counts['concerning_comment'] ?? 0)->toBe(1)
        ->and($counts['calendar_event'] ?? 0)->toBe(1);
});

it('forbids a teacher with no relation to the student', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/students/{$student->id}/performance-timeline")->assertForbidden();
});

it('never resolves a student from another school (tenant isolation)', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);
    Sanctum::actingAs($directorA);

    $this->getJson("/api/v1/students/{$studentB->id}/performance-timeline")->assertNotFound();
});

it('rejects an invalid date window (422)', function () {
    ['teacher' => $teacher, 'student' => $student] = timelineScenario();
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/students/{$student->id}/performance-timeline?from=2026-03-31&to=2026-03-01")
        ->assertStatus(422);
    $this->getJson("/api/v1/students/{$student->id}/performance-timeline?from=not-a-date")
        ->assertStatus(422);
});
