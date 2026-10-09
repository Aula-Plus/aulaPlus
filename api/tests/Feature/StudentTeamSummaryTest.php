<?php

use App\Models\Accommodation;
use App\Models\Barrier;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function teamSummaryPayload(): array
{
    return [
        'strengths' => 'Buena comprensión oral.',
        'difficulties' => 'Leer textos largos.',
        'adjustments' => 'Tiempo extendido 25%.',
    ];
}

it('lets psychopedagogy write and confirm the team summary, and the teacher then reads it', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/students/{$student->id}/tracking")
        ->assertOk()
        ->assertJsonPath('data.team_summary', null);

    Sanctum::actingAs($psychopedagogue);
    $this->putJson("/api/v1/students/{$student->id}/team-summary", teamSummaryPayload())
        ->assertOk()
        ->assertJsonPath('data.strengths', 'Buena comprensión oral.');

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/students/{$student->id}/tracking")
        ->assertOk()
        ->assertJsonPath('data.team_summary.adjustments', 'Tiempo extendido 25%.');

    expect($student->fresh()->team_summary_confirmed_by_id)->toBe($psychopedagogue->id);
});

it('forbids teachers and directors from writing the team summary', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    foreach ([$teacher, $director] as $user) {
        Sanctum::actingAs($user);
        $this->putJson("/api/v1/students/{$student->id}/team-summary", teamSummaryPayload())
            ->assertForbidden();
    }
});

it('validates the team summary and rejects another school\'s student', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $other = Student::factory()->create(['school_id' => School::factory()->create()->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->putJson("/api/v1/students/{$student->id}/team-summary", ['strengths' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['difficulties', 'adjustments']);

    $this->putJson("/api/v1/students/{$other->id}/team-summary", teamSummaryPayload())
        ->assertNotFound();
});

it('gives a teacher the names of current supports but nothing clinical', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Accommodation::factory()->create([
        'student_id' => $student->id, 'active' => true, 'type' => 'Tiempo extendido 25%',
        'description' => 'detalle clínico',
    ]);
    Barrier::factory()->create([
        'student_id' => $student->id, 'active' => true, 'description' => 'Lectura de textos largos',
        'coping_strategy' => 'estrategia clínica',
    ]);
    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk();

    $response->assertJsonPath('data.support_chips.accommodations.0.label', 'Tiempo extendido 25%')
        ->assertJsonPath('data.support_chips.barriers.0.label', 'Lectura de textos largos')
        ->assertJsonMissingPath('data.accommodations');
    expect($response->getContent())->not->toContain('detalle clínico')
        ->not->toContain('estrategia clínica');
});
