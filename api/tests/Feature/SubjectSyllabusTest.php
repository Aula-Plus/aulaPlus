<?php

use App\Actions\AI\BuildProposalContext;
use App\Enums\AIProposalType;
use App\Jobs\ExtractSubjectSyllabusText;
use App\Models\AIProposal;
use App\Models\CurricularItem;
use App\Models\Group;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\SubjectSyllabusUploadedNotification;
use App\Support\Pdf\BoundedPdfParser;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Smalot\PdfParser\Config;

// ClickUp 86e3dt6ag — "Agregar campo de programa curricular a la sección
// Materias": an optional program PDF per subject, private, temporary URL only,
// readable by the subject's teachers and psychopedagogy, and used as
// provisional AI context until the subject is in the curricular catalog.

/**
 * A tiny but valid one-page PDF whose text layer reads $text — its content
 * stream Flate-compressed when $compressed.
 */
function syllabusPdf(string $text, bool $compressed = false): string
{
    $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
    $filter = '';
    if ($compressed) {
        $stream = gzcompress($stream);
        $filter = ' /Filter /FlateDecode';
    }
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($stream)."{$filter} >>\nstream\n{$stream}\nendstream",
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
    Storage::fake('syllabi');
    Storage::disk('syllabi')->buildTemporaryUrlsUsing(
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
    Sanctum::actingAs($this->director);

    ($this->upload)()
        ->assertOk()
        ->assertJsonPath('data.syllabus.name', 'Programa Ayuda Social 2027.pdf')
        ->assertJsonPath('data.syllabus.text_status', 'ready')
        ->assertJsonPath('data.in_catalog', false)
        ->assertJsonMissingPath('data.syllabus.path');

    $subject = $this->subject->fresh();
    Storage::disk('syllabi')->assertExists($subject->syllabus_path);
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

    Storage::disk('syllabi')->assertMissing($first);
    Storage::disk('syllabi')->assertExists($second);

    $this->deleteJson("/api/v1/subjects/{$this->subject->id}/syllabus")
        ->assertOk()
        ->assertJsonPath('data.syllabus', null);
    Storage::disk('syllabi')->assertMissing($second);
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
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.syllabus.text_status', 'failed');

    expect($this->subject->fresh()->syllabus_path)->not->toBeNull();
});

it('strips control characters such as NUL from the extracted text (PostgreSQL rejects them)', function () {
    Sanctum::actingAs($this->director);

    ($this->upload)(syllabusPdf('Unidad\\000 uno'))->assertOk()->assertJsonPath('data.syllabus.text_status', 'ready');

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
    $canView = fn () => collect($this->getJson('/api/v1/subjects')->assertOk()->json('data'))
        ->firstWhere('id', $this->subject->id)['syllabus']['can_view'];
    expect($canView())->toBeTrue();

    Sanctum::actingAs($this->otherTeacher);
    $this->getJson("/api/v1/subjects/{$this->subject->id}/syllabus")->assertForbidden();
    expect($canView())->toBeFalse();
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

it('stores programs on the dedicated syllabi disk, not the default one', function () {
    Storage::fake('local');
    Sanctum::actingAs($this->director);

    ($this->upload)()->assertOk();

    Storage::disk('syllabi')->assertExists($this->subject->fresh()->syllabus_path);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses uploads in production while the syllabi disk is the ephemeral local one', function () {
    config(['filesystems.disks.syllabi.driver' => 'local']);
    $this->app['env'] = 'production';
    Log::shouldReceive('error')->once()->with('subject.syllabus.storage_not_durable', ['disk_driver' => 'local']);
    Sanctum::actingAs($this->director);

    ($this->upload)()
        ->assertStatus(503)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'almacenamiento'));

    expect(Storage::disk('syllabi')->allFiles())->toBe([])
        ->and($this->subject->fresh()->syllabus_path)->toBeNull();
});

it('accepts uploads in production on a durable (s3) syllabi disk', function () {
    config(['filesystems.disks.syllabi.driver' => 's3']);
    $this->app['env'] = 'production';
    Sanctum::actingAs($this->director);

    ($this->upload)()->assertOk();
});

it('answers text_status pending until the queued extraction runs, then ready', function () {
    Queue::fake();
    Sanctum::actingAs($this->director);

    ($this->upload)()->assertOk()->assertJsonPath('data.syllabus.text_status', 'pending');

    $subject = $this->subject->fresh();
    Queue::assertPushed(ExtractSubjectSyllabusText::class, fn ($job) => $job->path === $subject->syllabus_path);

    (new ExtractSubjectSyllabusText($subject, $subject->syllabus_path))->handle();

    $this->getJson("/api/v1/subjects/{$this->subject->id}")->assertJsonPath('data.syllabus.text_status', 'ready');
});

it('settles as failed, not pending forever, when the extraction job dies', function () {
    Queue::fake();
    Sanctum::actingAs($this->director);
    ($this->upload)()->assertOk();
    $subject = $this->subject->fresh();

    (new ExtractSubjectSyllabusText($subject, $subject->syllabus_path))->failed(new RuntimeException('Timed out'));

    $this->getJson("/api/v1/subjects/{$this->subject->id}")->assertJsonPath('data.syllabus.text_status', 'failed');
});

it('leaves a replaced program alone when a stale extraction job fails', function () {
    Queue::fake();
    Sanctum::actingAs($this->director);
    ($this->upload)()->assertOk();
    $stale = $this->subject->fresh()->syllabus_path;
    ($this->upload)()->assertOk();

    (new ExtractSubjectSyllabusText($this->subject->fresh(), $stale))->failed(null);

    $this->getJson("/api/v1/subjects/{$this->subject->id}")->assertJsonPath('data.syllabus.text_status', 'pending');
});

it('caps the total bytes decoded for one PDF, across all its streams', function () {
    $pdf = syllabusPdf('Unidad 1 Proyecto comunitario', compressed: true);
    $config = new Config;
    $config->setDecodeMemoryLimit(ExtractSubjectSyllabusText::MAX_DECODED_STREAM_BYTES);

    $roomy = new BoundedPdfParser($config, 1024);
    expect($roomy->parseContent($pdf)->getText())->toContain('Proyecto comunitario')
        ->and($roomy->budgetExceeded())->toBeFalse();

    $tight = new BoundedPdfParser($config, 10);
    $text = $tight->parseContent($pdf)->getText();
    expect($tight->budgetExceeded())->toBeTrue()
        ->and($text)->not->toContain('Proyecto comunitario');
});

it('detects the PDF type from the file bytes, not from its name', function () {
    Sanctum::actingAs($this->director);
    $realFile = function (string $bytes): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'syllabus');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'programa.pdf', 'application/pdf', null, true);
    };

    $this->post("/api/v1/subjects/{$this->subject->id}/syllabus", [
        'file' => $realFile(syllabusPdf('Unidad 1')),
    ], ['Accept' => 'application/json'])->assertOk();

    $this->post("/api/v1/subjects/{$this->subject->id}/syllabus", [
        'file' => $realFile("Nombre,Apellido\nAna,Pérez\n"),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('deletes the uploaded file when saving the subject fails', function () {
    Subject::saving(fn () => throw new RuntimeException('DB down'));
    Sanctum::actingAs($this->director);

    ($this->upload)()->assertServerError();

    expect(Storage::disk('syllabi')->allFiles())->toBe([]);
});

it('lists subjects without one teachers query per subject (can_view)', function () {
    $listQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/subjects')->assertOk();

        return count(DB::getQueryLog());
    };
    Sanctum::actingAs($this->teacher);
    $this->subject->forceFill(['syllabus_path' => 'a.pdf'])->save();
    $listQueries(); // warm up: auth + roles load once per user

    $withOne = $listQueries();
    Subject::factory()->count(4)->create(['school_id' => $this->school->id, 'syllabus_path' => 'b.pdf']);
    $withFive = $listQueries();

    expect($withFive)->toBe($withOne);
    $canView = collect($this->getJson('/api/v1/subjects')->json('data'))->whereNotNull('syllabus')->pluck('syllabus.can_view', 'id');
    expect($canView->pull($this->subject->id))->toBeTrue()
        ->and($canView->unique()->values()->all())->toBe([false]);
});

it('keeps the PDF text out of the AI context when the requester cannot open the program', function () {
    $this->subject->forceFill(['syllabus_path' => 'x.pdf', 'syllabus_text' => 'Unidad 1: Proyecto comunitario'])->save();
    $proposalBy = fn (User $user) => AIProposal::factory()->create([
        'group_id' => $this->group->id,
        'requested_by_id' => $user->id,
        'type' => AIProposalType::Unit,
        'input_parameters' => ['subject_id' => $this->subject->id],
    ]);
    $build = fn (AIProposal $proposal) => Tenancy::forSchool($this->school, fn () => (new BuildProposalContext)($proposal));

    expect($build($proposalBy($this->otherTeacher))['subject_program'])->toBeNull()
        ->and($build($proposalBy($this->teacher))['subject_program']['source'])->toBe('school_program_pdf')
        ->and($build($proposalBy($this->psychopedagogue))['subject_program']['source'])->toBe('school_program_pdf');
});

/**
 * The support mail's lines as the reader sees them (they are HTML-encoded so
 * that CommonMark treats user input as literal text).
 *
 * @return array<int, string>
 */
function syllabusMailLines(MailMessage $mail): array
{
    return array_map(
        fn ($line) => html_entity_decode((string) $line, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        $mail->introLines,
    );
}

it('emails the Aula+ support team when a program is uploaded or replaced', function () {
    Notification::fake();
    config(['services.support.notify_to' => ['soporte@aulaplus.test', 'pipe@aulaplus.test']]);
    $this->school->update(['name' => 'Ivy Thomas']);
    $this->subject->update(['short_code' => 'AS']);
    $this->director->update(['name' => 'Ana Pérez']);
    Sanctum::actingAs($this->director);

    ($this->upload)()->assertOk();

    Notification::assertSentOnDemand(
        SubjectSyllabusUploadedNotification::class,
        function (SubjectSyllabusUploadedNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
            // Round-trip through queue serialization, as a worker would.
            $mail = unserialize(serialize($notification))->toMail($notifiable);

            return $notifiable->routes['mail'] === ['soporte@aulaplus.test', 'pipe@aulaplus.test']
                && $channels === ['mail']
                && $mail->subject === '[Aula+] Ivy Thomas subió el programa de Ayuda Social'
                && syllabusMailLines($mail) === [
                    'Ivy Thomas subió el programa de Ayuda Social.',
                    'Materia: Ayuda Social (AS)',
                    'Archivo: Programa Ayuda Social 2027.pdf',
                    'Catálogo: la materia todavía no está en el catálogo curricular.',
                    'Subido por: Ana Pérez — Dirección',
                    "Referencia: colegio #{$this->school->id}, materia #{$this->subject->id}",
                ];
        },
    );

    // A replacement is a new program for the catalog team too.
    $item = CurricularItem::factory()->create();
    $this->subject->forceFill(['curricular_item_id' => $item->id])->save();
    ($this->upload)('', 'Programa Ayuda Social 2028.pdf')->assertOk();

    Notification::assertSentOnDemandTimes(SubjectSyllabusUploadedNotification::class, 2);
    Notification::assertSentOnDemand(
        SubjectSyllabusUploadedNotification::class,
        function (SubjectSyllabusUploadedNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
            $lines = syllabusMailLines($notification->toMail($notifiable));

            return in_array('Archivo: Programa Ayuda Social 2028.pdf', $lines, true)
                && in_array('Catálogo: la materia ya está vinculada al catálogo curricular.', $lines, true);
        },
    );
});

it('sends no support mail, and still saves the program, when no support address is configured', function () {
    Notification::fake();
    config(['services.support.notify_to' => []]);
    Sanctum::actingAs($this->director);

    ($this->upload)()->assertOk()->assertJsonPath('data.syllabus.name', 'Programa Ayuda Social 2027.pdf');

    Notification::assertNothingSent();
    expect($this->subject->fresh()->syllabus_path)->not->toBeNull();
});

it('sends no support mail when the upload fails', function () {
    Notification::fake();
    config(['services.support.notify_to' => ['soporte@aulaplus.test']]);

    // Not direction.
    Sanctum::actingAs($this->teacher);
    ($this->upload)()->assertForbidden();

    // Not a PDF.
    Sanctum::actingAs($this->director);
    $this->post("/api/v1/subjects/{$this->subject->id}/syllabus", [
        'file' => UploadedFile::fake()->create('programa.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    // Production without durable storage.
    config(['filesystems.disks.syllabi.driver' => 'local']);
    $this->app['env'] = 'production';
    ($this->upload)()->assertStatus(503);

    Notification::assertNothingSent();
});

it('keeps the upload successful when the support mail cannot be queued', function () {
    config(['services.support.notify_to' => ['soporte@aulaplus.test']]);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('queue down'));
    Log::spy();
    Sanctum::actingAs($this->director);

    ($this->upload)()->assertOk();

    expect($this->subject->fresh()->syllabus_path)->not->toBeNull();
    Log::shouldHaveReceived('warning')->once()->with('subject.syllabus.support_mail_failed', [
        'subject_id' => $this->subject->id,
        'error' => RuntimeException::class,
    ]);
});

it('renders school, subject, uploader and file names in the support mail as literal text', function (string $field, string $payload) {
    $values = ['school' => 'Ivy Thomas', 'subject' => 'Ayuda Social', 'uploader' => 'Ana Pérez', 'file' => 'programa.pdf'];
    $values[$field] = $payload;
    $this->school->update(['name' => $values['school']]);
    $this->subject->update(['name' => $values['subject']]);
    $this->director->update(['name' => $values['uploader']]);

    $mail = (new SubjectSyllabusUploadedNotification($this->subject->fresh(), $this->director->fresh(), $values['file']))
        ->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();
    $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
    $squash = fn (string $value) => trim(preg_replace('/\s+/', ' ', $value));
    $visible = $squash(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    // (The mail layout's own header logo is an <img>, so look for ours.)
    expect($html)->not->toContain('href="https://evil.example')
        ->not->toContain('src="https://evil.example')
        ->not->toContain('<img src=x')
        ->not->toContain('<b>')
        // Shown as typed (Laravel's Markdown::render() itself drops a "\" before "[").
        ->and($visible)->toContain($squash(str_replace('\[', '[', $payload)))
        // The plain-text part shows the value as typed, without escapes.
        ->and($text)->toContain($payload);
})->with([
    'subject: link' => ['subject', 'Ayuda [Restablecer acceso](https://evil.example)'],
    'subject: html' => ['subject', 'Ayuda <b>Social</b>'],
    'file: link' => ['file', '[Abrir programa](https://evil.example).pdf'],
    'file: backslash bypass' => ['file', '\[Abrir programa](https://evil.example).pdf'],
    'file: image' => ['file', '![logo](https://evil.example/x.png).pdf'],
    'file: autolink' => ['file', '<https://evil.example>.pdf'],
    'school: link' => ['school', '[Colegio](https://evil.example)'],
    'uploader: html' => ['uploader', 'Ana <img src=x onerror=alert(1)>'],
]);
