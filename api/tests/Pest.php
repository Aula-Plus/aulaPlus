<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => test()->seed(RoleSeeder::class))
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Attach a teacher to a group so they "lead" it, satisfying the group_teacher
 * pivot's now-required subject_id (Session 2, Option A: every teacher-in-group
 * row carries a subject). Setups that only care that the teacher leads the
 * group — authorization, tracking, comments — don't care which subject, so a
 * throwaway subject in the group's school is created when none is given.
 */
function leadGroup(\App\Models\Group $group, \App\Models\User $teacher, ?\App\Models\Subject $subject = null): \App\Models\Subject
{
    $subject ??= \App\Models\Subject::factory()->create(['school_id' => $group->school_id]);
    $group->teachers()->attach($teacher->getKey(), ['subject_id' => $subject->id]);

    return $subject;
}
