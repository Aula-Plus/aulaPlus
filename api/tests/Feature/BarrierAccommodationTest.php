<?php

use App\Models\Accommodation;
use App\Models\Barrier;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets a teacher propose linking an accommodation to a barrier', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodation->id])
        ->assertCreated()
        ->assertJsonPath('data.proposed_by_id', $teacher->id)
        ->assertJsonPath('data.validated', false);

    $this->getJson("/api/v1/barriers/{$barrier->id}/accommodations")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('returns 422 when the user who proposed a link also tries to validate it (four-eyes rule)', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodation->id])
        ->assertCreated();

    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations/{$accommodation->id}/validate")
        ->assertStatus(422);

    expect($barrier->accommodations()->first()->pivot->validated)->toBeFalsy();
});

it('lets a different director/psychopedagogue validate a proposed link', function () {
    $school = School::factory()->create();
    $proposer = User::factory()->forSchool($school)->teacher()->create();
    $validator = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $proposer);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);

    Sanctum::actingAs($proposer);
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodation->id])
        ->assertCreated();

    Sanctum::actingAs($validator);
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations/{$accommodation->id}/validate")
        ->assertOk()
        ->assertJsonPath('data.validated', true)
        ->assertJsonPath('data.validated_by_id', $validator->id);
});

it('rejects a teacher trying to validate a barrier-accommodation link', function () {
    $school = School::factory()->create();
    $proposer = User::factory()->forSchool($school)->psychopedagogue()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);

    Sanctum::actingAs($proposer);
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodation->id])
        ->assertCreated();

    Sanctum::actingAs($otherTeacher);
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations/{$accommodation->id}/validate")
        ->assertForbidden();
});

it('never lets a barrier be linked to an accommodation from another school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $teacher = User::factory()->forSchool($schoolA)->teacher()->create();
    $studentA = Student::factory()->create(['school_id' => $schoolA->id]);
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);
    $groupA = Group::factory()->create(['school_id' => $schoolA->id]);
    leadGroup($groupA, $teacher);
    $studentA->groups()->attach($groupA, ['school_year' => now()->year]);
    $barrier = Barrier::factory()->create(['student_id' => $studentA->id]);
    $accommodationB = Accommodation::factory()->create(['student_id' => $studentB->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodationB->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['accommodation_id']);
});

/**
 * Lucía is in 2°A; Mariana teaches her Matemática, Ana teaches her Ciencias,
 * Pablo teaches only in another group. One barrier, linked to a general
 * accommodation and to one scoped to Matemática (ClickUp 86e3dpbwy follow-up:
 * Accommodation::scopeVisibleTo is the single visibility rule, this endpoint
 * included).
 */
function barrierLinkScenario(): array
{
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $math = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']);
    $science = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Ciencias']);

    $mariana = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $mariana, $math);
    $ana = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $ana, $science);
    $pablo = User::factory()->forSchool($school)->teacher()->create();
    leadGroup(Group::factory()->create(['school_id' => $school->id]), $pablo, $math);
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();

    $lucia = Student::factory()->create(['school_id' => $school->id]);
    $lucia->groups()->attach($group, ['school_year' => now()->year]);

    $barrier = Barrier::factory()->create(['student_id' => $lucia->id]);
    $calculator = Accommodation::factory()->create([
        'student_id' => $lucia->id,
        'type' => 'Calculadora',
        'description' => 'detalle clínico de la calculadora',
        'focus_area' => 'foco clínico',
    ]);
    $calculator->subjects()->attach($math);
    $seating = Accommodation::factory()->create([
        'student_id' => $lucia->id,
        'type' => 'Sentarla adelante',
        'description' => 'detalle clínico del pizarrón',
        'focus_area' => 'foco clínico',
    ]);
    $barrier->accommodations()->attach([
        $calculator->id => ['proposed_by_id' => $psychopedagogue->id, 'validated' => false],
        $seating->id => ['proposed_by_id' => $psychopedagogue->id, 'validated' => false],
    ]);

    return compact('school', 'group', 'math', 'science', 'mariana', 'ana', 'pablo', 'psychopedagogue', 'lucia', 'barrier', 'calculator', 'seating');
}

it('lists to a teacher only the linked accommodations they may see, without clinical detail', function () {
    ['mariana' => $mariana, 'ana' => $ana, 'barrier' => $barrier] = barrierLinkScenario();

    Sanctum::actingAs($mariana);
    $response = $this->getJson("/api/v1/barriers/{$barrier->id}/accommodations")->assertOk();
    expect(collect($response->json('data'))->pluck('type')->sort()->values()->all())
        ->toBe(['Calculadora', 'Sentarla adelante']);
    foreach ($response->json('data') as $link) {
        expect($link)->not->toHaveKey('description')->not->toHaveKey('focus_area');
    }
    expect($response->getContent())->not->toContain('clínico');

    // Teaches Ciencias: the Matemática-scoped one doesn't exist for her.
    Sanctum::actingAs($ana);
    $response = $this->getJson("/api/v1/barriers/{$barrier->id}/accommodations")->assertOk();
    expect(collect($response->json('data'))->pluck('type')->all())->toBe(['Sentarla adelante']);
    expect($response->getContent())->not->toContain('Calculadora')->not->toContain('clínico');
});

it('keeps listing every linked accommodation with its detail to psychopedagogy', function () {
    ['psychopedagogue' => $psychopedagogue, 'barrier' => $barrier] = barrierLinkScenario();
    Sanctum::actingAs($psychopedagogue);

    $response = $this->getJson("/api/v1/barriers/{$barrier->id}/accommodations")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('description')->sort()->values()->all())
        ->toBe(['detalle clínico de la calculadora', 'detalle clínico del pizarrón']);
});

it('forbids a teacher who does not teach the student from reading or linking the barrier', function () {
    ['pablo' => $pablo, 'barrier' => $barrier, 'seating' => $seating] = barrierLinkScenario();
    Sanctum::actingAs($pablo);

    $this->getJson("/api/v1/barriers/{$barrier->id}/accommodations")->assertForbidden();
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $seating->id])
        ->assertForbidden();
});

it('forbids a teacher from linking an accommodation they may not see, without echoing it', function () {
    ['ana' => $ana, 'barrier' => $barrier, 'calculator' => $calculator, 'lucia' => $lucia] = barrierLinkScenario();
    $barrier->accommodations()->detach($calculator->id);
    Sanctum::actingAs($ana);

    $response = $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $calculator->id])
        ->assertForbidden();

    expect($response->getContent())->not->toContain('clínico')->not->toContain('Calculadora');
    expect($barrier->accommodations()->whereKey($calculator->id)->exists())->toBeFalse();
});

it('echoes a teacher\'s new link without clinical detail', function () {
    ['mariana' => $mariana, 'barrier' => $barrier, 'calculator' => $calculator] = barrierLinkScenario();
    $barrier->accommodations()->detach($calculator->id);
    Sanctum::actingAs($mariana);

    $response = $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $calculator->id])
        ->assertCreated()
        ->assertJsonPath('data.id', $calculator->id)
        ->assertJsonPath('data.proposed_by_id', $mariana->id);

    expect($response->json('data'))->not->toHaveKey('description')->not->toHaveKey('focus_area');
});

it('rejects linking a barrier to an accommodation of another student', function () {
    ['school' => $school, 'group' => $group, 'psychopedagogue' => $psychopedagogue, 'barrier' => $barrier] = barrierLinkScenario();
    $otherStudent = Student::factory()->create(['school_id' => $school->id]);
    $otherStudent->groups()->attach($group, ['school_year' => now()->year]);
    $foreign = Accommodation::factory()->create(['student_id' => $otherStudent->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accommodation_id']);
});

it('never shows a barrier of another school', function () {
    ['barrier' => $barrier] = barrierLinkScenario();
    $otherSchool = School::factory()->create();
    $outsider = User::factory()->forSchool($otherSchool)->psychopedagogue()->create();
    Sanctum::actingAs($outsider);

    $this->getJson("/api/v1/barriers/{$barrier->id}/accommodations")->assertNotFound();
});
