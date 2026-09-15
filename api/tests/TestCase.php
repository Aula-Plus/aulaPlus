<?php

namespace Tests;

use App\Support\Tenancy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tenancy::useSchool() sets a process-lifetime static override. Without
        // this reset it would leak from one test into the next (the static
        // survives the per-test database rollback), making the suite sensitive
        // to test ordering. Start every test with the tenant unresolved so it
        // falls back to the authenticated user, exactly like a real request.
        Tenancy::forget();
    }
}
