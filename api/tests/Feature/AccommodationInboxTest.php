<?php

use App\Enums\AccommodationReviewStatus;
use App\Enums\ReportDocumentStatus;
use App\Models\Accommodation;
use App\Models\AccommodationProposal;
use App\Models\Barrier;
use App\Models\Group;
use App\Models\ReportDocument;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentAnnualReview;
use App\Models\User;
use App\Support\SchoolTime;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

/**
 * «Ajustes y PTP» (screen 06, ClickUp 1dfd3mc4bgc): psychopedagogy's inbox
 * of every accommodation of the school, the yearly review and the barrier
 * registered from «Nuevo ajuste». Only psychopedagogy enters (P06-DEC-07).
 */
function inboxScenario(): array
{
    $school = School::factory()->create();
    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create(['name' => 'Laura Ferrando']);
    $director = User::factory()->forSchool($school)->director()->create(['name' => 'Adriana Ruiz']);
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $year = SchoolTime::today()->year;
    $groupA = Group::factory()->create(['school_id' => $school->id, 'name' => '5.°A', 'school_year' => $year]);
    $groupB = Group::factory()->create(['school_id' => $school->id, 'name' => '2°EMS-A', 'school_year' => $year]);
    $tobias = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Tobías Correa']);
    $lucia = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Lucía Pereyra']);
    $tobias->groups()->attach($groupA, ['school_year' => $year]);
    $lucia->groups()->attach($groupB, ['school_year' => $year]);
    leadGroup($groupA, $teacher);

    return compact('school', 'psycho', 'director', 'teacher', 'groupA', 'groupB', 'tobias', 'lucia', 'year');
}

it('lists every accommodation of the school for psychopedagogy, with status, student, group, barrier and who confirmed', function () {
    ['school' => $school, 'psycho' => $psycho, 'director' => $director, 'groupA' => $groupA, 'tobias' => $tobias, 'lucia' => $lucia] = inboxScenario();

    $inForce = Accommodation::factory()->create([
        'school_id' => $school->id, 'student_id' => $tobias->id, 'type' => 'Objetivos propios en Matemática',
        'category' => 'content', 'created_by_id' => $psycho->id,
    ]);
    $barrier = Barrier::factory()->create(['school_id' => $school->id, 'student_id' => $tobias->id, 'description' => 'Lectura de textos largos']);
    $barrier->accommodations()->attach($inForce->id, ['proposed_by_id' => $psycho->id, 'validated' => false]);

    Accommodation::factory()->proposal()->create([
        'school_id' => $school->id, 'student_id' => $lucia->id, 'type' => 'Anticipar los cambios de actividad',
        'category' => 'access', 'proposed_by_id' => $director->id,
    ]);
    Accommodation::factory()->create([
        'school_id' => $school->id, 'student_id' => $lucia->id, 'type' => 'Dado de baja', 'active' => false,
    ]);
    Accommodation::factory()->proposal(AccommodationReviewStatus::Withdrawn)->create([
        'school_id' => $school->id, 'student_id' => $lucia->id, 'type' => 'Retirada',
    ]);
    Accommodation::factory()->create([
        'school_id' => $school->id, 'student_id' => $lucia->id, 'type' => 'Sin tipo', 'category' => null,
    ]);
    // Another school's accommodation never shows.
    Accommodation::factory()->create();

    Sanctum::actingAs($psycho);

    $response = $this->getJson('/api/v1/accommodation-inbox')->assertOk();
    $rows = collect($response->json('data'));

    expect($rows->pluck('type')->all())->toEqualCanonicalizing([
        'Objetivos propios en Matemática', 'Anticipar los cambios de actividad', 'Dado de baja', 'Sin tipo',
    ]);

    $row = $rows->firstWhere('type', 'Objetivos propios en Matemática');
    expect($row['inbox_status'])->toBe('in_force')
        ->and($row['student']['name'])->toBe('Tobías Correa')
        ->and($row['group']['name'])->toBe('5.°A')
        ->and($row['barriers'][0]['description'])->toBe('Lectura de textos largos')
        ->and($row['confirmed_by_name'])->toBe('Laura Ferrando');

    expect($rows->firstWhere('type', 'Anticipar los cambios de actividad')['inbox_status'])->toBe('pending')
        ->and($rows->firstWhere('type', 'Dado de baja')['inbox_status'])->toBe('inactive');

    $this->getJson('/api/v1/accommodation-inbox?status=pending')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'Anticipar los cambios de actividad');
    $this->getJson("/api/v1/accommodation-inbox?group_id={$groupA->id}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.name', 'Tobías Correa');
    $this->getJson('/api/v1/accommodation-inbox?category=none')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'Sin tipo');
    $this->getJson('/api/v1/accommodation-inbox?status=anything')->assertUnprocessable();
    $this->getJson('/api/v1/accommodation-inbox?group_id='.Group::factory()->create()->id)->assertUnprocessable();
});

it('keeps the inbox away from teachers and direction', function () {
    ['teacher' => $teacher, 'director' => $director] = inboxScenario();

    foreach ([$teacher, $director] as $user) {
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/accommodation-inbox')->assertForbidden();
        $this->getJson('/api/v1/annual-reviews')->assertForbidden();
        $this->getJson('/api/v1/annual-reviews/pending')->assertForbidden();
    }
});

it('lists the reports still waiting without their file name or content', function () {
    ['school' => $school, 'psycho' => $psycho, 'tobias' => $tobias] = inboxScenario();

    Tenancy::forSchool($school, function () use ($tobias, $psycho): void {
        $report = ReportDocument::create([
            'student_id' => $tobias->id, 'uploaded_by_id' => $psycho->id,
            'path' => 'x.pdf', 'original_name' => 'Informe_neuropsicologico_2026.pdf', 'format' => 'pdf', 'size' => 10,
            'status' => ReportDocumentStatus::Proposed, 'pages' => ['x'], 'anonymized_pages' => ['x'],
        ]);
        foreach (['Lectura en voz alta', 'Tiempo extendido'] as $type) {
            AccommodationProposal::create([
                'report_document_id' => $report->id, 'student_id' => $tobias->id, 'type' => $type,
                'category' => 'access', 'description' => 'Contenido reservado.', 'focus_area' => 'lectura', 'basis' => 'explicit',
            ]);
        }
    });

    Sanctum::actingAs($psycho);
    $response = $this->getJson('/api/v1/accommodation-inbox')->assertOk()
        ->assertJsonCount(1, 'report_proposals')
        ->assertJsonPath('report_proposals.0.pending_count', 2)
        ->assertJsonPath('report_proposals.0.student.name', 'Tobías Correa')
        ->assertJsonPath('report_proposals.0.group.name', '5.°A');

    expect($response->getContent())->not->toContain('Informe_neuropsicologico_2026.pdf')
        ->not->toContain('Contenido reservado.');

    $this->getJson('/api/v1/accommodation-inbox?status=in_force')->assertOk()->assertJsonCount(0, 'report_proposals');
});

it('lists the students to review and marks this year’s review with date and who', function () {
    ['school' => $school, 'psycho' => $psycho, 'tobias' => $tobias, 'lucia' => $lucia, 'year' => $year] = inboxScenario();
    Accommodation::factory()->count(2)->create(['school_id' => $school->id, 'student_id' => $tobias->id]);
    Accommodation::factory()->create(['school_id' => $school->id, 'student_id' => $lucia->id]);
    // Only a switched-off accommodation: nothing to review.
    $other = Student::factory()->create(['school_id' => $school->id]);
    Accommodation::factory()->create(['school_id' => $school->id, 'student_id' => $other->id, 'active' => false]);
    Tenancy::forSchool($school, fn () => StudentAnnualReview::query()->create([
        'student_id' => $lucia->id, 'school_year' => $year - 1,
        'reviewed_on' => ($year - 1).'-11-28', 'reviewed_by_id' => $psycho->id,
    ]));

    Sanctum::actingAs($psycho);

    $this->getJson('/api/v1/annual-reviews')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.student.name', 'Lucía Pereyra')
        ->assertJsonPath('data.0.last_review.reviewed_on', ($year - 1).'-11-28')
        ->assertJsonPath('data.0.reviewed_this_year', false)
        ->assertJsonPath('data.1.student.name', 'Tobías Correa')
        ->assertJsonPath('data.1.accommodations_in_force_count', 2)
        ->assertJsonPath('data.1.group.name', '5.°A')
        ->assertJsonPath('data.1.last_review', null);

    $this->getJson('/api/v1/annual-reviews/pending')->assertOk()->assertJsonPath('data.count', 2);

    $this->postJson("/api/v1/students/{$tobias->id}/annual-review")->assertCreated()
        ->assertJsonPath('data.reviewed_on', SchoolTime::today()->toDateString())
        ->assertJsonPath('data.reviewed_by_name', 'Laura Ferrando');
    // Marking twice the same year keeps one row.
    $this->postJson("/api/v1/students/{$tobias->id}/annual-review")->assertCreated();
    expect(StudentAnnualReview::query()->where('student_id', $tobias->id)->count())->toBe(1);

    $this->getJson('/api/v1/annual-reviews/pending')->assertOk()->assertJsonPath('data.count', 1);
    // Nothing was switched off by a missing review (P06-DEC-04).
    expect(Accommodation::query()->where('student_id', $lucia->id)->effective()->count())->toBe(1);
});

it('lets only psychopedagogy mark a review, and only for its own school', function () {
    ['teacher' => $teacher, 'director' => $director, 'psycho' => $psycho, 'tobias' => $tobias] = inboxScenario();

    foreach ([$teacher, $director] as $user) {
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/students/{$tobias->id}/annual-review")->assertForbidden();
    }

    Sanctum::actingAs($psycho);
    $this->postJson('/api/v1/students/'.Student::factory()->create()->id.'/annual-review')->assertNotFound();
});

it('registers a barrier for a student from «Nuevo ajuste», psychopedagogy only', function () {
    ['psycho' => $psycho, 'teacher' => $teacher, 'director' => $director, 'tobias' => $tobias] = inboxScenario();

    Sanctum::actingAs($psycho);
    $barrierId = $this->postJson("/api/v1/students/{$tobias->id}/barriers", [
        'description' => 'Lectura de textos largos',
    ])->assertCreated()->json('data.id');

    $this->getJson("/api/v1/students/{$tobias->id}/barriers")->assertOk()
        ->assertJsonPath('data.0.id', $barrierId)
        ->assertJsonPath('data.0.description', 'Lectura de textos largos');

    $this->postJson("/api/v1/students/{$tobias->id}/barriers", [])->assertUnprocessable()
        ->assertJsonValidationErrorFor('description');

    foreach ([$teacher, $director] as $user) {
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/students/{$tobias->id}/barriers", ['description' => 'x'])->assertForbidden();
        $this->getJson("/api/v1/students/{$tobias->id}/barriers")->assertForbidden();
    }
});
