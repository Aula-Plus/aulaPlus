<?php

use App\Enums\GradesVisibility;
use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\AccommodationInstanceOverride;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * Living document screen 04 · Evaluaciones: «cada instancia con su nota y, al
 * lado, qué ajustes estaban activos ese día» — now possible because every
 * accommodation has «Vigente desde» and the day it was switched off (PO,
 * ClickUp 86e3d1bwa, option A).
 *
 * Lucía is in 2°A. Mariana teaches her Matemática; Historia is someone else's.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->group = Group::factory()->create();
    $this->school = $this->group->school;
    Tenancy::useSchool($this->school->id);

    $this->student = Student::factory()->for($this->school)->create();
    $this->group->students()->attach($this->student->id, ['school_year' => now()->year]);

    $this->math = Subject::factory()->for($this->school)->create(['name' => 'Matemática']);
    $this->history = Subject::factory()->for($this->school)->create(['name' => 'Historia']);

    $this->teacher = User::factory()->create(['school_id' => $this->school->id]);
    $this->teacher->assignRole(Role::Teacher->value);
    $this->group->teachers()->attach($this->teacher->id, ['subject_id' => $this->math->id]);

    $this->psychopedagogue = User::factory()->create(['school_id' => $this->school->id]);
    $this->psychopedagogue->assignRole(Role::Psychopedagogue->value);

    $grade = function (Subject $subject, string $type, string $day): Assessment {
        $assessment = Assessment::factory()->for($this->group)->create([
            'subject_id' => $subject->id,
            'type' => $type,
            'administered_at' => $day,
        ]);
        AssessmentResult::factory()->for($assessment)->for($this->student)->create(['score' => 7]);

        return $assessment;
    };

    $this->mathEarly = $grade($this->math, 'written', '2026-03-01');
    $this->mathWritten = $grade($this->math, 'written', '2026-04-10');
    $this->mathOral = $grade($this->math, 'oral', '2026-04-20');
    $this->historyWritten = $grade($this->history, 'written', '2026-04-10');

    $accommodation = fn (array $attributes) => Accommodation::factory()->create([
        'student_id' => $this->student->id,
        'description' => 'detalle clínico',
        ...$attributes,
    ]);

    $extraTime = $accommodation(['type' => 'Tiempo extendido', 'starts_on' => '2026-03-15']);
    $calculator = $accommodation(['type' => 'Calculadora', 'starts_on' => '2026-04-01', 'assessment_types' => ['written']]);
    $calculator->subjects()->attach($this->math);
    $readAloud = $accommodation(['type' => 'Lectura en voz alta', 'starts_on' => '2026-03-01']);
    $readAloud->subjects()->attach($this->history);
    // Switched off on April 15th.
    $accommodation(['type' => 'Ortografía no puntúa', 'starts_on' => '2026-03-01', 'active' => false, 'deactivated_on' => '2026-04-15']);
    // Still waiting for external approval: never in force.
    $accommodation(['type' => 'Pendiente', 'starts_on' => '2026-03-01', 'requires_external_approval' => true, 'approved' => null]);

    // Extended time was switched off just for the oral exam.
    AccommodationInstanceOverride::factory()->create([
        'school_id' => $this->school->id,
        'accommodation_id' => $extraTime->id,
        'assessment_id' => $this->mathOral->id,
        'reason' => 'motivo confidencial',
    ]);

    $this->url = "/api/v1/students/{$this->student->id}/tracking";
});

/**
 * @return array<int, list<string>> assessment id => labels
 */
function accommodationsByAssessment($response): array
{
    return collect($response->json('data.grades'))
        ->mapWithKeys(fn (array $grade) => [$grade['assessment_id'] => collect($grade['accommodations'])->pluck('label')->all()])
        ->all();
}

it('lists, next to each grade, the accommodations in force that day for that subject and type', function () {
    actingAs($this->psychopedagogue);

    $byAssessment = accommodationsByAssessment(getJson($this->url)->assertOk());

    expect($byAssessment[$this->mathEarly->id])->toBe(['Ortografía no puntúa'])
        ->and($byAssessment[$this->mathWritten->id])->toBe(['Calculadora', 'Ortografía no puntúa', 'Tiempo extendido'])
        // Calculator is written-only, spelling was switched off on 04-15, and
        // extended time was deactivated for this very instance.
        ->and($byAssessment[$this->mathOral->id])->toBe([])
        ->and($byAssessment[$this->historyWritten->id])->toBe(['Lectura en voz alta', 'Ortografía no puntúa', 'Tiempo extendido']);
});

it('gives only the name of each accommodation, never clinical detail or the override reason', function () {
    actingAs($this->psychopedagogue);

    $response = getJson($this->url)->assertOk();

    $grade = collect($response->json('data.grades'))->firstWhere('assessment_id', $this->mathWritten->id);
    foreach ($grade['accommodations'] as $item) {
        expect(array_keys($item))->toBe(['id', 'label']);
    }
    expect(json_encode($response->json('data.grades')))
        ->not->toContain('detalle clínico')
        ->not->toContain('motivo confidencial');
});

it('shows a teacher only the accommodations they may see, on the grades they may see', function () {
    actingAs($this->teacher);

    // Default «solo su materia»: only the Matemática grades.
    $byAssessment = accommodationsByAssessment(getJson($this->url)->assertOk());
    expect(array_keys($byAssessment))->toEqualCanonicalizing([$this->mathEarly->id, $this->mathWritten->id, $this->mathOral->id])
        ->and($byAssessment[$this->mathWritten->id])->toBe(['Calculadora', 'Ortografía no puntúa', 'Tiempo extendido']);

    // With every subject open, the Historia grade shows up, but never the
    // accommodation scoped to Historia (a subject Mariana doesn't teach).
    $this->school->update(['grades_visibility' => GradesVisibility::AllLive]);
    $byAssessment = accommodationsByAssessment(getJson($this->url)->assertOk());
    expect($byAssessment[$this->historyWritten->id])->toBe(['Ortografía no puntúa', 'Tiempo extendido']);
});

it('loads the accommodations once per request, however many grades there are', function () {
    actingAs($this->psychopedagogue);

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        getJson($this->url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // Warm the 60s aggregate cache so both measured requests read it.
    getJson($this->url)->assertOk();
    $before = $countQueries();

    foreach (['2026-04-11', '2026-04-12', '2026-04-13'] as $day) {
        $assessment = Assessment::factory()->for($this->group)->create([
            'subject_id' => $this->math->id,
            'type' => 'written',
            'administered_at' => $day,
        ]);
        AssessmentResult::factory()->for($assessment)->for($this->student)->create(['score' => 6]);
    }

    expect($countQueries())->toBe($before);
});
