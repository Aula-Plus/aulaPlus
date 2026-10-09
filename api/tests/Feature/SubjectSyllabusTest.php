<?php

use App\Actions\AI\BuildProposalContext;
use App\Enums\AIProposalType;
use App\Models\AIProposal;
use App\Models\CurricularItem;
use App\Models\Group;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3dt6ag — "Agregar campo de programa curricular a la sección
// Materias": an optional program PDF per subject, private, temporary URL only,
// readable by the subject's teachers and psychopedagogy, and used as
// provisional AI context until the subject is in the curricular catalog.

/** A tiny but valid one-page PDF whose text layer reads $text. */
function syllabusPdf(string $text): string
{
    $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    return $pdf;
}

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration) => 'https://files.test/'.$path.'?expires='.$expiration->getTimestamp()
    );

    $this->school = School::factory()->create();
    $this->director = User::factory()->forSchool($this->school)->director()->create();
    $this->psychopedagogue = User::factory()->forSchool($this->school)->psychopedagogue()->create();
    $this->teacher = User::factory()->forSchool($this->school)->teacher()->create();
    $this->otherTeacher = User::factory()->forSchool($this->school)->teacher()->create();
    $this->subject = Subject::factory()->create(['school_id' => $this->school->id, 'name' => 'Ayuda Social']);
    $this->group = Group::factory()->create(['school_id' => $this->school->id]);
    leadGroup($this->group, $this->teacher, $this->subject);
    leadGroup($this->group, $this->otherTeacher);

    $this->upload = fn (string $content = '', string $name = 'Programa Ayuda Social 2027.pdf') => $this->post(
        "/api/v1/subjects/{$this->subject->id}/syllabus",
        ['file' => UploadedFile::fake()->createWithContent($name, $content === '' ? syllabusPdf('Unidad 1 Proyecto comunitario') : $content)],
        ['Accept' => 'application/json'],
    );
});

it('lets direction upload the program PDF privately and extracts its text', function () {
    Log::shouldReceive('info')->once()->with('status-change.subject.syllabus_uploaded', Mockery::on(
        fn (array $context) => $context['subject_id'] === $this->subject->id && $context['in_catalog'] === false
    ));
    Sanctum::actingAs($this->director);

    ($this->upload)()
        ->assertOk()
        ->assertJsonPath('data.syllabus.name', 'Programa Ayuda Social 2027.pdf')
        ->assertJsonPath('data.syllabus.text_extracted', true)
        ->assertJsonPath('data.in_catalog', false)
        ->assertJsonMissingPath('data.syllabus.path');

    $subject = $this->subject->fresh();
    Storage::disk('local')->assertExists($subject->syllabus_path);
    expect($subject->syllabus_path)->toStartWith("schools/{$this->school->id}/syllabi/")
        ->and($subject->syllabus_path)->not->toContain('Ayuda')
        ->and($subject->syllabus_text)->toContain('Proyecto comunitario');
});

it('replaces the previous PDF (one per subject) and can remove it', function () {
    Sanctum::actingAs($this->director);
    ($this->upload)()->assertOk();
    $first = $this->subject->fresh()->syllabus_path;

    ($this->upload)('', 'v2.pdf')->assertOk()->assertJsonPath('data.syllabus.name', 'v2.pdf');
    $second = $this->subject->fresh()->syllabus_path;

    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($second);

    $this->deleteJson("/api/v1/subjects/{$this->subject->id}/syllabus")
        ->assertOk()
        ->assertJsonPath('data.syllabus', null);
    Storage::disk('local')->assertMissing($second);
    expect($this->subject->fresh()->syllabus_text)->toBeNull();
});

it('only accepts a PDF of at most 10 MB', function () {
    Sanctum::actingAs($this->director);

    $this->post("/api/v1/subjects/{$this->subject->id}/syllabus", [
        'file' => UploadedFile::fake()->create('programa.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->post("/api/v1/subjects/{$this->subject->id}/syllabus", [
        'file' => UploadedFile::fake()->create('programa.pdf', 10 * 1024 + 1, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->post("/api/v1/subjects/{$this->subject->id}/syllabus", [], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('keeps the file when its text cannot be extracted', function () {
    Sanctum::actingAs($this->director);

    $this->post("/api/v1/subjects/{$this->subject->id}/syllabus", [
        'file' => UploadedFile::fake()->create('escaneado.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.syllabus.text_extracted', false);

    expect($this->subject->fresh()->syllabus_path)->not->toBeNull();
});

it('strips control characters such as NUL from the extracted text (PostgreSQL rejects them)', function () {
    Sanctum::actingAs($this->director);

    ($this->upload)(syllabusPdf('Unidad\\000 uno'))->assertOk()->assertJsonPath('data.syllabus.text_extracted', true);

    $text = $this->subject->fresh()->syllabus_text;
    expect($text)->toContain('Unidad')
        ->and($text)->toContain('uno')
        ->and($text)->not->toContain("\0");
});

it('only lets direction upload or remove it', function () {
    foreach ([$this->teacher, $this->psychopedagogue] as $user) {
        Sanctum::actingAs($user);
        ($this->upload)()->assertForbidden();
        $this->deleteJson("/api/v1/subjects/{$this->subject->id}/syllabus")->assertForbidden();
    }
});

it('serves it through a temporary URL to direction, psychopedagogy and the subject teachers only', function () {
    Sanctum::actingAs($this->director);
    ($this->upload)()->assertOk();

    foreach ([$this->director, $this->psychopedagogue, $this->teacher] as $user) {
        Sanctum::actingAs($user);
        $this->getJson("/api/v1/subjects/{$this->subject->id}/syllabus")
            ->assertOk()
            ->assertJsonPath('data.name', 'Programa Ayuda Social 2027.pdf')
            ->assertJsonPath('data.expires_in_minutes', 5)
            ->assertJson(fn ($json) => $json->where('data.url', fn ($url) => str_starts_with($url, 'https://files.test/schools/'))->etc());
    }

    Sanctum::actingAs($this->teacher);
    $this->getJson('/api/v1/subjects')->assertOk()->assertJsonPath('data.0.syllabus.can_view', true);

    Sanctum::actingAs($this->otherTeacher);
    $this->getJson("/api/v1/subjects/{$this->subject->id}/syllabus")->assertForbidden();
    $this->getJson('/api/v1/subjects')->assertOk()->assertJsonPath('data.0.syllabus.can_view', false);
});

it('returns 404 when the subject has no program, and isolates schools', function () {
    Sanctum::actingAs($this->director);
    $this->getJson("/api/v1/subjects/{$this->subject->id}/syllabus")->assertNotFound();

    $foreignDirector = User::factory()->forSchool(School::factory()->create())->director()->create();
    Sanctum::actingAs($foreignDirector);
    ($this->upload)()->assertNotFound();
    $this->getJson("/api/v1/subjects/{$this->subject->id}/syllabus")->assertNotFound();
});

it('sends the PDF text to the AI until the subject is in the catalog, then the catalog', function () {
    $proposal = AIProposal::factory()->create([
        'group_id' => $this->group->id,
        'requested_by_id' => $this->teacher->id,
        'type' => AIProposalType::Unit,
        'input_parameters' => ['subject_id' => $this->subject->id],
    ]);
    $build = fn () => Tenancy::forSchool($this->school, fn () => (new BuildProposalContext)($proposal));

    expect($build()['subject_program'])->toBeNull();

    $this->subject->forceFill(['syllabus_path' => 'x.pdf', 'syllabus_text' => 'Unidad 1: Proyecto comunitario'])->save();
    expect($build()['subject_program'])->toBe([
        'source' => 'school_program_pdf',
        'subject' => 'Ayuda Social',
        'text' => 'Unidad 1: Proyecto comunitario',
    ]);

    $item = CurricularItem::factory()->create(['name' => 'Ayuda Social', 'parent_id' => null]);
    CurricularItem::factory()->create([
        'curricular_catalog_id' => $item->curricular_catalog_id,
        'parent_id' => $item->id,
        'name' => 'Unidad: Comunidad',
    ]);
    $this->subject->forceFill(['curricular_item_id' => $item->id])->save();

    $program = $build()['subject_program'];
    expect($program['source'])->toBe('curricular_catalog')
        ->and(collect($program['items'])->pluck('name')->all())->toBe(['Ayuda Social', 'Unidad: Comunidad'])
        ->and(json_encode($program))->not->toContain('Proyecto comunitario');
});
