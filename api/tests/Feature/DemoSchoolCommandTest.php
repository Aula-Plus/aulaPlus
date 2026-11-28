<?php

use App\Enums\AIProposalType;
use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\AIProposal;
use App\Models\Alert;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\ClassSession;
use App\Models\Comment;
use App\Models\CurricularFramework;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\CurricularFrameworkSeeder;
use Database\Seeders\OfficialScaleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * `php artisan app:demo-school` (ClickUp 1dfd3mc5g1p): wipes and rebuilds
 * only the test school, with fictitious students and one account per role
 * whose random password is shown once.
 */
beforeEach(function () {
    $this->seed(CurricularFrameworkSeeder::class);
    $this->seed(OfficialScaleSeeder::class);
});

function demoSchoolArgs(array $overrides = []): array
{
    return array_merge([
        '--director' => 'direccion@example.com',
        '--psychopedagogue' => 'psicopedagogia@example.com',
        '--teacher' => 'maestra@example.com',
        '--force' => true,
    ], $overrides);
}

/** Rows of every school_id table that belong to the given school. */
function rowsOfSchool(int $schoolId): array
{
    return collect(Schema::getTables())
        ->pluck('name')
        ->filter(fn (string $table) => Schema::hasColumn($table, 'school_id'))
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->where('school_id', $schoolId)->count()])
        ->filter()
        ->all();
}

/** Fills the demo school with what a walkthrough leaves behind. */
function useDemoSchool(School $school): void
{
    Tenancy::forSchool($school, function () use ($school): void {
        $teacher = User::query()->where('school_id', $school->id)->where('email', 'maestra@example.com')->firstOrFail();
        $psychopedagogue = User::query()->where('school_id', $school->id)->where('email', 'psicopedagogia@example.com')->firstOrFail();
        $group = Group::query()->where('name', '5.°A')->firstOrFail();
        $subject = Subject::query()->where('name', 'Matemática')->firstOrFail();
        $student = $group->students()->firstOrFail();

        $assessment = Assessment::factory()->create(['group_id' => $group->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id]);
        AssessmentResult::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'created_by_id' => $teacher->id]);
        Comment::factory()->create(['author_id' => $teacher->id, 'commentable_id' => $student->id]);
        Alert::factory()->create(['student_id' => $student->id]);
        $plan = AnnualPlan::factory()->create([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'curricular_framework_id' => CurricularFramework::query()->where('name', 'ANEP EBI')->value('id'),
        ]);
        $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);
        $proposal = AIProposal::factory()->create(['group_id' => $group->id, 'requested_by_id' => $teacher->id, 'type' => AIProposalType::ClassSession]);
        ClassSession::factory()->create(['group_id' => $group->id, 'unit_id' => $unit->id, 'subject_id' => $subject->id]);
        $proposal->update(['status' => 'pending']);

        $scoped = Accommodation::factory()->create(['student_id' => $student->id, 'created_by_id' => $psychopedagogue->id]);
        $scoped->subjects()->attach($subject);
    });
}

it('builds the test school with its structure, fictitious students and one account per role', function () {
    $this->artisan('app:demo-school', demoSchoolArgs())
        ->expectsOutputToContain('shown only once')
        ->assertSuccessful();

    $school = School::query()->where('slug', 'demo-integral')->firstOrFail();

    expect($school->name)->toBe('Escuela Integral (prueba)')
        ->and($school->curricularFrameworks()->pluck('name')->sort()->values()->all())->toBe(['ANEP Bachillerato', 'ANEP EBI', 'IB PYP']);

    Tenancy::forSchool($school, function () use ($school) {
        $groups = Group::query()->orderBy('name')->get();
        expect($groups->pluck('name')->all())->toBe(['1.°A', '2.°A', '3.°A', '4.°A', '5.°A', '6.°A']);

        $students = Student::query()->get();
        expect($students)->toHaveCount(120)
            ->and($students->pluck('full_name')->unique())->toHaveCount(120);

        $fifth = $groups->firstWhere('name', '5.°A');
        $teacher = User::query()->where('email', 'maestra@example.com')->firstOrFail();
        expect($teacher->school_id)->toBe($school->id)
            ->and($teacher->hasRole(Role::Teacher->value))->toBeTrue()
            ->and($teacher->teachesSubjectInGroup($fifth, Subject::query()->where('name', 'Matemática')->firstOrFail()))->toBeTrue()
            ->and(User::query()->where('email', 'direccion@example.com')->first()->hasRole(Role::Director->value))->toBeTrue()
            ->and(User::query()->where('email', 'psicopedagogia@example.com')->first()->hasRole(Role::Psychopedagogue->value))->toBeTrue();

        $accommodation = Accommodation::query()->firstOrFail();
        expect($fifth->students()->whereKey($accommodation->student_id)->exists())->toBeTrue()
            ->and($accommodation->barriers()->count())->toBe(1)
            // Free text the UI shows as is: it must read in Spanish.
            ->and($accommodation->type)->toBe('Tiempo extra en evaluaciones')
            ->and($accommodation->focus_area)->toBe('Atención');
    });
});

it('prints each random password once and it is the one that signs in', function () {
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('app:demo-school', demoSchoolArgs()))->toBe(0);

    $output = Artisan::output();
    $teacher = User::query()->where('email', 'maestra@example.com')->firstOrFail();

    preg_match('/maestra@example\\.com\\s*\\|\\s*(\\S+)\\s*\\|/', $output, $match);

    expect($match)->toHaveKey(1)
        ->and(strlen($match[1]))->toBe(20)
        ->and(Hash::check($match[1], $teacher->password))->toBeTrue()
        ->and(substr_count($output, $match[1]))->toBe(1);
});

it('creates passwords that are random on every rebuild', function () {
    $this->artisan('app:demo-school', demoSchoolArgs())->assertSuccessful();
    $first = User::query()->where('email', 'maestra@example.com')->value('password');

    $this->artisan('app:demo-school', demoSchoolArgs())->assertSuccessful();
    $second = User::query()->where('email', 'maestra@example.com')->value('password');

    expect($first)->not->toBe($second);
});

it('wipes everything of the test school on rebuild and leaves other schools untouched', function () {
    $other = School::factory()->create();
    Tenancy::forSchool($other, function () use ($other) {
        $teacher = User::factory()->forSchool($other)->teacher()->create();
        $group = Group::factory()->create();
        $student = Student::factory()->create();
        $student->groups()->attach($group, ['school_year' => now()->year]);
        Comment::factory()->create(['author_id' => $teacher->id, 'commentable_id' => $student->id]);
    });
    $otherBefore = rowsOfSchool($other->id);
    $otherPivots = DB::table('group_student')->count();

    $this->artisan('app:demo-school', demoSchoolArgs())->assertSuccessful();
    $old = School::query()->where('slug', 'demo-integral')->firstOrFail();
    useDemoSchool($old);
    $oldUserIds = User::query()->where('school_id', $old->id)->pluck('id');
    $oldAccommodationIds = DB::table('accommodations')->where('school_id', $old->id)->pluck('id');
    $oldBarrierIds = DB::table('barriers')->where('school_id', $old->id)->pluck('id');
    // Pivots without school_id, one hop from a scoped table: they must go too.
    expect(DB::table('accommodation_subject')->whereIn('accommodation_id', $oldAccommodationIds)->count())->toBeGreaterThan(0)
        ->and(DB::table('barrier_accommodation')->whereIn('barrier_id', $oldBarrierIds)->count())->toBeGreaterThan(0);

    $this->artisan('app:demo-school', demoSchoolArgs())->assertSuccessful();

    $new = School::query()->where('slug', 'demo-integral')->firstOrFail();

    expect($new->id)->not->toBe($old->id)
        ->and(School::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(rowsOfSchool($old->id))->toBe([])
        ->and(DB::table('model_has_roles')->whereIn('model_id', $oldUserIds)->where('model_type', User::class)->count())->toBe(0)
        ->and(DB::table('accommodation_subject')->whereIn('accommodation_id', $oldAccommodationIds)->count())->toBe(0)
        ->and(DB::table('barrier_accommodation')->whereIn('barrier_id', $oldBarrierIds)->count())->toBe(0)
        ->and(rowsOfSchool($other->id))->toBe($otherBefore)
        ->and(DB::table('group_student')->count())->toBe($otherPivots + 120);

    Tenancy::forSchool($new, fn () => expect(Student::query()->count())->toBe(120));
});

it('refuses to delete a school that holds the slug but is not the test school', function () {
    $real = School::factory()->create(['slug' => 'demo-integral', 'name' => 'Un colegio real']);

    $this->artisan('app:demo-school', demoSchoolArgs())
        ->expectsOutputToContain('is not the test school')
        ->assertFailed();

    expect(School::query()->whereKey($real->id)->exists())->toBeTrue();
});

it('refuses emails that belong to users of another school', function () {
    $other = School::factory()->create();
    User::factory()->forSchool($other)->teacher()->create(['email' => 'maestra@example.com']);

    $this->artisan('app:demo-school', demoSchoolArgs())
        ->expectsOutputToContain('already belong to users of another school')
        ->assertFailed();

    expect(School::query()->where('slug', 'demo-integral')->exists())->toBeFalse();
});

it('requires three distinct valid emails', function (array $overrides) {
    $this->artisan('app:demo-school', demoSchoolArgs($overrides))->assertFailed();

    expect(School::query()->where('slug', 'demo-integral')->exists())->toBeFalse();
})->with([
    'missing' => [['--teacher' => null]],
    'invalid' => [['--teacher' => 'no-es-un-mail']],
    'repeated' => [['--teacher' => 'direccion@example.com']],
]);

it('rejects an unknown profile', function () {
    $this->artisan('app:demo-school', demoSchoolArgs(['--profile' => 'otro']))
        ->expectsOutputToContain('Unknown profile')
        ->assertFailed();
});

it('does not run in production unless told to', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('app:demo-school', demoSchoolArgs())->assertFailed();
    expect(School::query()->where('slug', 'demo-integral')->exists())->toBeFalse();

    $this->artisan('app:demo-school', demoSchoolArgs(['--allow-production' => true]))->assertSuccessful();
    expect(School::query()->where('slug', 'demo-integral')->exists())->toBeTrue();
});

it('asks before wiping an existing test school', function () {
    $this->artisan('app:demo-school', demoSchoolArgs())->assertSuccessful();
    $id = School::query()->where('slug', 'demo-integral')->value('id');

    $this->artisan('app:demo-school', demoSchoolArgs(['--force' => false]))
        ->expectsConfirmation('Delete "Escuela Integral (prueba)" and everything in it, then rebuild it?', 'no')
        ->assertSuccessful();

    expect(School::query()->where('slug', 'demo-integral')->value('id'))->toBe($id);
});
