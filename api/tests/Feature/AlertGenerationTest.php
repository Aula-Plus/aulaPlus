<?php

use App\Enums\AlertType;
use App\Enums\CommentTone;
use App\Models\Alert;
use App\Models\Comment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;

// docs/prompts/04-seguimiento-institucional.md §6
it('generates a behavior/medium alert when 3+ concerning comments land within 15 days', function () {
    $school = School::factory()->create();
    $author = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    Tenancy::useSchool($school);
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create([
        'author_id' => $author->id,
        'created_at' => now()->subDays(10),
    ]);
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create([
        'author_id' => $author->id,
        'created_at' => now()->subDays(5),
    ]);
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create([
        'author_id' => $author->id,
        'created_at' => now()->subDays(1),
    ]);
    Tenancy::forget();

    Log::shouldReceive('info')->once()->with('status-change.alert.generated', Mockery::on(
        fn (array $context) => $context['student_id'] === $student->id && $context['type'] === 'behavior'
    ));

    $this->artisan('alerts:generate')->assertSuccessful();

    $alert = Alert::query()->where('student_id', $student->id)->first();
    expect($alert)->not->toBeNull()
        ->and($alert->type)->toBe(AlertType::Behavior)
        ->and($alert->severity->value)->toBe('medium')
        ->and($alert->resolved)->toBeFalse();
});

it('does not generate a second open alert of the same type for the same student', function () {
    $school = School::factory()->create();
    $author = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    Tenancy::useSchool($school);
    Alert::factory()->create([
        'student_id' => $student->id,
        'type' => AlertType::Behavior,
        'resolved' => false,
    ]);

    for ($i = 0; $i < 3; $i++) {
        Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create([
            'author_id' => $author->id,
            'created_at' => now()->subDays($i + 1),
        ]);
    }
    Tenancy::forget();

    $this->artisan('alerts:generate')->assertSuccessful();

    expect(Alert::query()->where('student_id', $student->id)->count())->toBe(1);
});

it('does not generate an alert with fewer than 3 concerning comments, or comments older than 15 days', function () {
    $school = School::factory()->create();
    $author = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    Tenancy::useSchool($school);
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create([
        'author_id' => $author->id,
        'created_at' => now()->subDays(5),
    ]);
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create([
        'author_id' => $author->id,
        'created_at' => now()->subDays(20),
    ]);
    Comment::factory()->forSubject($student)->tone(CommentTone::Concerning)->create([
        'author_id' => $author->id,
        'created_at' => now()->subDays(25),
    ]);
    Tenancy::forget();

    $this->artisan('alerts:generate')->assertSuccessful();

    expect(Alert::query()->where('student_id', $student->id)->count())->toBe(0);
});

it('scopes alert generation per school and never leaks across tenants', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $authorA = User::factory()->forSchool($schoolA)->psychopedagogue()->create();
    $studentA = Student::factory()->create(['school_id' => $schoolA->id]);
    Student::factory()->create(['school_id' => $schoolB->id]);

    Tenancy::useSchool($schoolA);
    for ($i = 0; $i < 3; $i++) {
        Comment::factory()->forSubject($studentA)->tone(CommentTone::Concerning)->create([
            'author_id' => $authorA->id,
            'created_at' => now()->subDays($i + 1),
        ]);
    }
    Tenancy::forget();

    $this->artisan('alerts:generate')->assertSuccessful();

    $alert = Alert::query()->where('student_id', $studentA->id)->first();
    expect($alert->school_id)->toBe($schoolA->id);
    expect(Alert::query()->count())->toBe(1);
});
