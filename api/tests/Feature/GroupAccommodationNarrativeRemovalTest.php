<?php

use App\Models\Group;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// ClickUp 1dfd3mc2wwy (PO decision of 21/10): the AI summary of a group's
// accommodations is gone — no way to generate, publish or read one, and the
// stored texts are deleted with their table.

it('no longer stores group accommodation summaries', function () {
    expect(Schema::hasTable('group_accommodation_narratives'))->toBeFalse();
});

it('no longer serves, generates or publishes a group accommodation summary', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    Sanctum::actingAs($psychopedagogue);

    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertNotFound();
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertNotFound();
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/1/publish")->assertNotFound();
});
