<?php

use App\Enums\ProposalStatus;
use App\Enums\ReportDocumentStatus;
use App\Enums\ReportVisibility;
use App\Jobs\ExtractReportDocumentText;
use App\Jobs\InterpretReportDocument;
use App\Models\Accommodation;
use App\Models\AccommodationProposal;
use App\Models\Group;
use App\Models\ReportDocument;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Reports\ReportAnonymizer;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3jujgx — psychopedagogy uploads a student report (PDF/Word), the
// text is extracted and anonymized, a person reviews it and confirms the send,
// and only then the AI proposes adjustments that psychopedagogy approves,
// modifies or rejects one by one. Three switches gate the AI.

/** A tiny valid PDF with one page per entry of $pagesText. */
function reportPdf(array $pagesText): string
{
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>'];
    $kids = [];
    $n = count($pagesText);
    for ($i = 0; $i < $n; $i++) {
        $kids[] = (3 + $i * 2).' 0 R';
    }
    $objects[] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$n.' >>';
    foreach ($pagesText as $i => $text) {
        $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.(4 + $i * 2).' 0 R /Resources << /Font << /F1 '.(3 + $n * 2).' 0 R >> >> >>';
        $objects[] = '<< /Length '.strlen($stream).">>\nstream\n{$stream}\nendstream";
    }
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

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

    return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
}

/** A minimal .docx whose body is the given paragraphs; "\f" starts a new page. */
function reportDocx(array $paragraphs): string
{
    $body = '';
    foreach ($paragraphs as $paragraph) {
        $body .= $paragraph === "\f"
            ? '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
            : '<w:p><w:r><w:t>'.htmlspecialchars($paragraph).'</w:t></w:r></w:p>';
    }
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>';
    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    $content = file_get_contents($path);
    unlink($path);

    return $content;
}

function anthropicProposals(array $proposals): array
{
    return ['content' => [['type' => 'text', 'text' => json_encode(['proposals' => $proposals])]], 'stop_reason' => 'end_turn'];
}

beforeEach(function () {
    Storage::fake('reports');
    Storage::disk('reports')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration) => 'https://files.test/'.$path.'?expires='.$expiration->getTimestamp()
    );

    $this->school = School::factory()->create(['name' => 'Colegio San Martín', 'ai_report_interpretation_enabled' => true]);
    $this->psycho = User::factory()->forSchool($this->school)->psychopedagogue()->create();
    $this->director = User::factory()->forSchool($this->school)->director()->create();
    $this->teacher = User::factory()->forSchool($this->school)->teacher()->create();
    $this->otherTeacher = User::factory()->forSchool($this->school)->teacher()->create();
    $this->student = Student::factory()->create(['school_id' => $this->school->id, 'full_name' => 'Lucía Pereyra']);
    $this->group = Group::factory()->create(['school_id' => $this->school->id]);
    $this->group->students()->attach($this->student->id, ['school_year' => now()->year]);
    leadGroup($this->group, $this->teacher);

    $this->upload = fn (?string $content = null, string $name = 'informe.pdf', array $extra = []) => $this->post(
        "/api/v1/students/{$this->student->id}/reports",
        ['file' => UploadedFile::fake()->createWithContent($name, $content ?? reportPdf(['Lucia Pereyra necesita tiempo extendido en escritos.'])), ...$extra],
        ['Accept' => 'application/json'],
    );

    $this->readyReport = function (array $attributes = []): ReportDocument {
        return Tenancy::forSchool($this->school, fn () => ReportDocument::create([
            'student_id' => $this->student->id,
            'uploaded_by_id' => $this->psycho->id,
            'path' => 'schools/'.$this->school->id.'/reports/x.pdf',
            'original_name' => 'informe.pdf',
            'format' => 'pdf',
            'size' => 10,
            'status' => ReportDocumentStatus::AwaitingReview,
            'pages' => ['Lucía Pereyra lee con dificultad textos largos.'],
            'anonymized_pages' => ['[ALUMNO] lee con dificultad textos largos.'],
            ...$attributes,
        ]));
    };

    $this->proposal = fn (ReportDocument $report, array $attributes = []): AccommodationProposal => Tenancy::forSchool(
        $this->school,
        fn () => AccommodationProposal::create([
            'report_document_id' => $report->id, 'student_id' => $this->student->id, 'type' => 'X',
            'category' => 'access', 'description' => 'x', 'focus_area' => 'x', 'basis' => 'inferred',
            ...$attributes,
        ]),
    );
});

it('lets psychopedagogy upload a PDF privately, extracts its text and anonymizes it', function () {
    Sanctum::actingAs($this->psycho);

    $response = ($this->upload)()->assertCreated()
        ->assertJsonPath('data.format', 'pdf')
        ->assertJsonMissingPath('data.path');

    $report = ReportDocument::findOrFail($response->json('data.id'));
    Storage::disk('reports')->assertExists($report->path);
    expect($report->path)->toStartWith("schools/{$this->school->id}/reports/")
        ->and($report->path)->not->toContain('informe')
        ->and($report->status)->toBe(ReportDocumentStatus::AwaitingReview)
        ->and($report->pages[0])->toContain('Lucia Pereyra')
        ->and($report->anonymized_pages[0])->not->toContain('Pereyra')
        ->and($report->anonymized_pages[0])->toContain('[ALUMNO]')
        ->and($report->anonymized_pages[0])->toContain('tiempo extendido');
});

it('stores the extracted text encrypted', function () {
    Sanctum::actingAs($this->psycho);
    $id = ($this->upload)()->json('data.id');

    $raw = DB::table('report_documents')->where('id', $id)->value('anonymized_pages');

    expect($raw)->not->toContain('tiempo extendido');
});

it('reads a Word file and splits it at page breaks', function () {
    Sanctum::actingAs($this->psycho);
    $docx = reportDocx(['Primera página con lectura lenta.', "\f", 'Segunda página con ortografía.']);

    $id = ($this->upload)($docx, 'informe.docx')->assertCreated()->assertJsonPath('data.format', 'docx')->json('data.id');

    $report = ReportDocument::findOrFail($id);
    expect($report->pages)->toHaveCount(2)
        ->and($report->pages[1])->toContain('ortografía');
});

it('marks a file without readable text as extraction_failed and keeps the file', function () {
    Sanctum::actingAs($this->psycho);

    $id = ($this->upload)("%PDF-1.4\nnot really a pdf")->assertCreated()->json('data.id');

    $report = ReportDocument::findOrFail($id);
    expect($report->status)->toBe(ReportDocumentStatus::ExtractionFailed);
    Storage::disk('reports')->assertExists($report->path);
});

it('leaves the report ready, without review, when the school has the AI off', function () {
    $this->school->update(['ai_report_interpretation_enabled' => false]);
    Sanctum::actingAs($this->psycho);

    $id = ($this->upload)()->json('data.id');

    expect(ReportDocument::findOrFail($id)->status)->toBe(ReportDocumentStatus::Ready);
});

it('rejects files that are not PDF or Word', function () {
    Sanctum::actingAs($this->psycho);

    ($this->upload)('hola', 'informe.txt')->assertUnprocessable();
    ($this->upload)('<?php echo 1;', 'informe.php')->assertUnprocessable();
});

it('only lets psychopedagogy upload', function () {
    foreach ([$this->director, $this->teacher] as $user) {
        Sanctum::actingAs($user);
        ($this->upload)()->assertForbidden();
    }
});

it('does not log the report text', function () {
    Log::spy();
    Sanctum::actingAs($this->psycho);

    ($this->upload)("%PDF-1.4\nbroken");

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains(json_encode($context), 'broken'));
});

it('anonymizes names, dates, documents, phones and labelled lines', function () {
    $anonymizer = ReportAnonymizer::forStudent($this->student, $this->school);

    $text = $anonymizer->anonymize(
        "Nombre: Lucía Pereyra\nPereyra, LUCIA nació el 12/03/2015 (CI 1.234.567-8). Tel 099 123 456, mail ana@correo.com.\nMadre: Ana Gómez\nAsiste a Colegio San Martín. Lee lento y necesita más tiempo."
    );

    expect($text)->not->toContain('Pereyra')
        ->and($text)->not->toContain('Lucía')
        ->and($text)->not->toContain('12/03/2015')
        ->and($text)->not->toContain('1.234.567-8')
        ->and($text)->not->toContain('099 123 456')
        ->and($text)->not->toContain('ana@correo.com')
        ->and($text)->not->toContain('Ana Gómez')
        ->and($text)->not->toContain('San Martín')
        ->and($text)->toContain('Lee lento y necesita más tiempo');
});

it('lets psychopedagogy correct the anonymized text, but not change the number of pages', function () {
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)();

    $this->putJson("/api/v1/reports/{$report->id}/anonymized-text", ['pages' => ['[ALUMNO] lee lento.']])
        ->assertOk()
        ->assertJsonPath('data.anonymized_pages.0', '[ALUMNO] lee lento.');

    $this->putJson("/api/v1/reports/{$report->id}/anonymized-text", ['pages' => ['a', 'b']])->assertUnprocessable();
});

it('refuses to send while the student name is still in the text', function () {
    Queue::fake();
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)(['anonymized_pages' => ['Lucía lee lento.']]);

    $this->postJson("/api/v1/reports/{$report->id}/confirm-send")->assertUnprocessable();

    Queue::assertNothingPushed();
    expect($report->fresh()->send_confirmed_at)->toBeNull();
});

it('sends only the confirmed anonymized text to the provider and stores pending proposals', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicProposals([
        ['type' => 'Lectura en voz alta', 'category' => 'access', 'description' => 'Leerle las consignas.', 'focus_area' => 'lectura', 'basis' => 'explicit', 'page' => 1, 'fragment' => 'lee con dificultad textos largos'],
        ['type' => 'Tiempo extra', 'category' => 'criteria', 'description' => 'Más tiempo en escritos.', 'focus_area' => 'escritura', 'basis' => 'inferred', 'page' => 1, 'fragment' => 'frase inventada que no está'],
        ['type' => 'Inválido', 'category' => 'nope', 'description' => 'x', 'basis' => 'explicit', 'page' => 1, 'fragment' => ''],
    ]), 200)]);
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)();

    $this->postJson("/api/v1/reports/{$report->id}/confirm-send")
        ->assertOk()
        ->assertJsonPath('data.status', 'proposed');

    Http::assertSent(function ($request) {
        $payload = json_encode($request->data());

        return str_contains($payload, 'lee con dificultad textos largos')
            && ! str_contains($payload, 'Pereyra')
            && ! str_contains($payload, 'Lucía')
            && ! str_contains($payload, (string) $this->student->id.'"');
    });

    $report->refresh();
    expect($report->send_confirmed_at)->not->toBeNull()
        ->and($report->send_confirmed_by_id)->toBe($this->psycho->id)
        ->and($report->proposals)->toHaveCount(2);

    $first = $report->proposals->firstWhere('type', 'Lectura en voz alta');
    $second = $report->proposals->firstWhere('type', 'Tiempo extra');
    expect($first->status)->toBe(ProposalStatus::Pending)
        ->and($first->source_page)->toBe(1)
        ->and($first->source_fragment)->toBe('lee con dificultad textos largos')
        ->and($second->source_fragment)->toBeNull();
    expect(Accommodation::count())->toBe(0);
});

it('does not call the provider when any of the three switches is off', function (string $off) {
    Http::fake();
    Queue::fake();
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)(['use_ai' => $off !== 'report']);
    if ($off === 'school') {
        $this->school->update(['ai_report_interpretation_enabled' => false]);
    }
    if ($off === 'global') {
        config(['services.ai_report.enabled' => false]);
    }

    $this->postJson("/api/v1/reports/{$report->id}/confirm-send")->assertUnprocessable();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
    expect($report->fresh()->send_confirmed_at)->toBeNull();
})->with(['report', 'school', 'global']);

it('records an error, without calling the provider, if a switch turns off before the job runs', function () {
    Http::fake();
    $report = ($this->readyReport)([
        'status' => ReportDocumentStatus::Interpreting, 'send_confirmed_at' => now(),
    ]);
    config(['services.ai_report.enabled' => false]);

    InterpretReportDocument::dispatchSync($report);

    Http::assertNothingSent();
    expect($report->fresh()->status)->toBe(ReportDocumentStatus::Error);
});

it('records an error when the provider fails', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)();

    $this->postJson("/api/v1/reports/{$report->id}/confirm-send")->assertOk()->assertJsonPath('data.status', 'error');

    expect($report->fresh()->proposals)->toHaveCount(0);
});

it('creates the accommodation only when psychopedagogy approves a proposal', function () {
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)(['status' => ReportDocumentStatus::Proposed]);
    $proposal = ($this->proposal)($report, [
        'type' => 'Lectura en voz alta',
        'category' => 'access', 'description' => 'Leerle las consignas.', 'focus_area' => 'lectura',
        'basis' => 'explicit', 'source_page' => 1, 'source_fragment' => 'lee con dificultad',
    ]);

    $this->postJson("/api/v1/accommodation-proposals/{$proposal->id}/review", ['decision' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $accommodation = Accommodation::firstOrFail();
    expect($accommodation->student_id)->toBe($this->student->id)
        ->and($accommodation->type)->toBe('Lectura en voz alta')
        ->and($accommodation->active)->toBeTrue()
        ->and($accommodation->requires_external_approval)->toBeFalse()
        ->and($proposal->fresh()->accommodation_id)->toBe($accommodation->id);

    $this->postJson("/api/v1/accommodation-proposals/{$proposal->id}/review", ['decision' => 'approve'])->assertUnprocessable();
    expect(Accommodation::count())->toBe(1);
});

it('records a modified approval with the edited fields', function () {
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)();
    $proposal = ($this->proposal)($report, [
        'type' => 'Lectura',
        'category' => 'access', 'description' => 'Leer.', 'focus_area' => 'lectura', 'basis' => 'inferred',
    ]);

    $this->postJson("/api/v1/accommodation-proposals/{$proposal->id}/review", [
        'decision' => 'approve', 'description' => 'Leerle las consignas en voz baja.', 'category' => 'content',
    ])->assertOk()->assertJsonPath('data.status', 'modified');

    $accommodation = Accommodation::firstOrFail();
    expect($accommodation->description)->toBe('Leerle las consignas en voz baja.')
        ->and($accommodation->category->value)->toBe('content');
});

it('rejects a proposal without creating anything', function () {
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)();
    $proposal = ($this->proposal)($report, [
        'type' => 'X',
        'category' => 'access', 'description' => 'x', 'focus_area' => 'x', 'basis' => 'inferred',
    ]);

    $this->postJson("/api/v1/accommodation-proposals/{$proposal->id}/review", ['decision' => 'reject'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');

    expect(Accommodation::count())->toBe(0);
});

it('lets psychopedagogy add an adjustment the AI missed', function () {
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)();

    $this->postJson("/api/v1/reports/{$report->id}/proposals", [
        'type' => 'Pausas', 'category' => 'access', 'description' => 'Pausas cada 20 minutos.', 'focus_area' => 'atención',
    ])->assertCreated()->assertJsonPath('data.basis', 'manual')->assertJsonPath('data.status', 'pending');

    expect(Accommodation::count())->toBe(0);
});

it('does not let direction or teachers review proposals, edit text or send', function () {
    $report = ($this->readyReport)();
    $proposal = ($this->proposal)($report, [
        'type' => 'X',
        'category' => 'access', 'description' => 'x', 'focus_area' => 'x', 'basis' => 'inferred',
    ]);

    foreach ([$this->director, $this->teacher] as $user) {
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/accommodation-proposals/{$proposal->id}/review", ['decision' => 'approve'])->assertForbidden();
        $this->postJson("/api/v1/reports/{$report->id}/proposals", ['type' => 'a', 'category' => 'access', 'description' => 'a', 'focus_area' => 'a'])->assertForbidden();
        $this->putJson("/api/v1/reports/{$report->id}/anonymized-text", ['pages' => ['a']])->assertForbidden();
        $this->postJson("/api/v1/reports/{$report->id}/confirm-send")->assertForbidden();
        $this->patchJson("/api/v1/reports/{$report->id}", ['visibility' => 'teachers'])->assertForbidden();
        $this->deleteJson("/api/v1/reports/{$report->id}")->assertForbidden();
    }
    expect(Accommodation::count())->toBe(0);
});

it('shows direction the clinical file but never the anonymized text, status or proposals', function () {
    $report = ($this->readyReport)();
    ($this->proposal)($report);

    Sanctum::actingAs($this->director);
    $this->getJson("/api/v1/reports/{$report->id}")->assertForbidden();
    $this->getJson("/api/v1/reports/{$report->id}/download")->assertOk();
    $list = $this->getJson("/api/v1/students/{$this->student->id}/reports")->assertOk()->assertJsonCount(1, 'data');
    expect($list->json('data.0'))->toHaveKeys(['id', 'name', 'format', 'size', 'visibility'])
        ->and($list->json('data.0'))->not->toHaveKeys(['status', 'use_ai', 'anonymized_pages', 'error_message']);

    Sanctum::actingAs($this->teacher);
    $this->getJson("/api/v1/reports/{$report->id}")->assertForbidden();
    $this->getJson("/api/v1/reports/{$report->id}/download")->assertForbidden();
    $this->getJson("/api/v1/students/{$this->student->id}/reports")->assertOk()->assertJsonCount(0, 'data');
});

it('stores the subjects the report points to, dropping ids the school does not have', function () {
    $lengua = Subject::factory()->create(['school_id' => $this->school->id, 'name' => 'Lengua']);
    $foreign = Subject::factory()->create(['name' => 'Ajena secreta']);
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicProposals([
        ['type' => 'Lectura', 'category' => 'access', 'description' => 'x', 'focus_area' => 'lectura', 'basis' => 'explicit', 'page' => 1, 'fragment' => 'lee con dificultad', 'subject_ids' => [$lengua->id, $foreign->id, 9999], 'subjects_basis' => 'explicit'],
        ['type' => 'Tiempo', 'category' => 'criteria', 'description' => 'y', 'focus_area' => 'escritura', 'basis' => 'inferred', 'page' => 1, 'fragment' => '', 'subject_ids' => [], 'subjects_basis' => null],
    ]), 200)]);
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)();

    $this->postJson("/api/v1/reports/{$report->id}/confirm-send")->assertOk();

    Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'Lengua') && ! str_contains(json_encode($request->data()), 'Ajena secreta'));
    $detail = $this->getJson("/api/v1/reports/{$report->id}")->assertOk();
    expect($detail->json('data.proposals.0.subject_ids'))->toBe([$lengua->id])
        ->and($detail->json('data.proposals.0.subjects_basis'))->toBe('explicit')
        ->and($detail->json('data.proposals.1.subject_ids'))->toBe([])
        ->and($detail->json('data.proposals.1.subjects_basis'))->toBeNull();
});

it('approves with the preloaded subjects, or with the reviewer\'s own list', function () {
    $lengua = Subject::factory()->create(['school_id' => $this->school->id]);
    $mate = Subject::factory()->create(['school_id' => $this->school->id]);
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)();
    $preloaded = ($this->proposal)($report, ['subject_ids' => [$lengua->id], 'subjects_basis' => 'inferred']);
    $changed = ($this->proposal)($report, ['subject_ids' => [$lengua->id], 'subjects_basis' => 'inferred']);

    $this->postJson("/api/v1/accommodation-proposals/{$preloaded->id}/review", ['decision' => 'approve'])
        ->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/accommodation-proposals/{$changed->id}/review", ['decision' => 'approve', 'subject_ids' => [$mate->id]])
        ->assertOk()->assertJsonPath('data.status', 'modified');

    expect(Accommodation::find($preloaded->fresh()->accommodation_id)->subjects->modelKeys())->toBe([$lengua->id])
        ->and(Accommodation::find($changed->fresh()->accommodation_id)->subjects->modelKeys())->toBe([$mate->id]);
});

it('hides the page number of a Word file without explicit page breaks', function () {
    Sanctum::actingAs($this->psycho);
    $word = ($this->readyReport)(['format' => 'docx']);
    ($this->proposal)($word, ['source_page' => 1, 'source_fragment' => 'lee']);
    $paged = ($this->readyReport)(['format' => 'docx', 'pages' => ['a', 'b'], 'anonymized_pages' => ['a', 'b']]);
    ($this->proposal)($paged, ['source_page' => 2, 'source_fragment' => 'b']);
    $pdf = ($this->readyReport)();
    ($this->proposal)($pdf, ['source_page' => 1, 'source_fragment' => 'lee']);

    $this->getJson("/api/v1/reports/{$word->id}")->assertJsonPath('data.proposals.0.source_page', null);
    $this->getJson("/api/v1/reports/{$paged->id}")->assertJsonPath('data.proposals.0.source_page', 2);
    $this->getJson("/api/v1/reports/{$pdf->id}")->assertJsonPath('data.proposals.0.source_page', 1);
});

it('resends the already reviewed anonymized text after a provider failure', function () {
    Http::fakeSequence('api.anthropic.com/*')
        ->push([], 500)
        ->push([], 500)
        ->push([], 500)
        ->push(anthropicProposals([
            ['type' => 'Lectura', 'category' => 'access', 'description' => 'x', 'focus_area' => 'lectura', 'basis' => 'explicit', 'page' => 1, 'fragment' => 'lee con dificultad'],
        ]), 200);
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)(['anonymized_pages' => ['[ALUMNO] lee con dificultad (corregido por Psico).']]);

    $this->postJson("/api/v1/reports/{$report->id}/confirm-send")->assertOk()->assertJsonPath('data.status', 'error');
    $this->postJson("/api/v1/reports/{$report->id}/confirm-send")->assertOk()->assertJsonPath('data.status', 'proposed');

    expect($report->fresh()->anonymized_pages)->toBe(['[ALUMNO] lee con dificultad (corregido por Psico).'])
        ->and($report->proposals()->count())->toBe(1);
});

it('lets the student teachers open only the files enabled for teachers, without clinical content', function () {
    $enabled = ($this->readyReport)(['visibility' => ReportVisibility::Teachers]);
    ($this->readyReport)(['visibility' => ReportVisibility::Clinical]);

    Sanctum::actingAs($this->teacher);
    $list = $this->getJson("/api/v1/students/{$this->student->id}/reports")->assertOk()->assertJsonCount(1, 'data');
    expect($list->json('data.0'))->toHaveKeys(['id', 'name', 'format', 'size', 'visibility'])
        ->and($list->json('data.0'))->not->toHaveKeys(['status', 'use_ai', 'anonymized_pages', 'error_message']);
    $this->getJson("/api/v1/reports/{$enabled->id}/download")->assertOk();
    $this->getJson("/api/v1/reports/{$enabled->id}")->assertForbidden();

    Sanctum::actingAs($this->otherTeacher);
    $this->getJson("/api/v1/reports/{$enabled->id}/download")->assertForbidden();
    $this->getJson("/api/v1/students/{$this->student->id}/reports")->assertForbidden();
});

it('lets psychopedagogy change a report visibility and delete it, keeping approved accommodations', function () {
    Sanctum::actingAs($this->psycho);
    $report = ($this->readyReport)();
    $accommodation = Accommodation::factory()->create(['school_id' => $this->school->id, 'student_id' => $this->student->id]);
    ($this->proposal)($report, [
        'type' => 'X', 'category' => 'access',
        'description' => 'x', 'focus_area' => 'x', 'basis' => 'explicit', 'status' => 'approved', 'accommodation_id' => $accommodation->id,
    ]);

    $this->patchJson("/api/v1/reports/{$report->id}", ['visibility' => 'teachers'])->assertOk()->assertJsonPath('data.visibility', 'teachers');
    $this->deleteJson("/api/v1/reports/{$report->id}")->assertNoContent();

    expect(ReportDocument::find($report->id))->toBeNull()
        ->and(Accommodation::find($accommodation->id))->not->toBeNull();
});

it('does not reach other schools reports', function () {
    $other = School::factory()->create();
    $otherPsycho = User::factory()->forSchool($other)->psychopedagogue()->create();
    $report = ($this->readyReport)();
    Sanctum::actingAs($otherPsycho);

    $this->getJson("/api/v1/reports/{$report->id}")->assertNotFound();
    $this->getJson("/api/v1/students/{$this->student->id}/reports")->assertNotFound();
});

it('requires authentication', function () {
    $this->getJson("/api/v1/students/{$this->student->id}/reports")->assertUnauthorized();
});

it('stays queued until the job runs when the extraction is dispatched', function () {
    Queue::fake();
    Sanctum::actingAs($this->psycho);

    $id = ($this->upload)()->json('data.id');

    Queue::assertPushed(ExtractReportDocumentText::class);
    expect(ReportDocument::findOrFail($id)->status)->toBe(ReportDocumentStatus::Processing);
});

it('lets direction read and switch the school AI setting; psychopedagogy and teachers only read', function () {
    $this->school->update(['ai_report_interpretation_enabled' => false]);

    Sanctum::actingAs($this->teacher);
    $this->getJson('/api/v1/ai-report-setting')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.available', true);
    $this->putJson('/api/v1/ai-report-setting', ['enabled' => true])->assertForbidden();

    Sanctum::actingAs($this->psycho);
    $this->putJson('/api/v1/ai-report-setting', ['enabled' => true])->assertForbidden();

    Sanctum::actingAs($this->director);
    $this->putJson('/api/v1/ai-report-setting', ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
    expect($this->school->fresh()->ai_report_interpretation_enabled)->toBeTrue();

    config(['services.ai_report.enabled' => false]);
    $this->getJson('/api/v1/ai-report-setting')->assertJsonPath('data.available', false);
});
