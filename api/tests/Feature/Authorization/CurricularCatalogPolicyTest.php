<?php

use App\Enums\Role;
use App\Models\CurricularCatalog;
use App\Models\CurricularFramework;
use App\Models\CurricularItem;
use App\Models\School;
use App\Models\User;

/**
 * CurricularFramework / CurricularCatalog / CurricularItem are a global,
 * non-tenant-scoped catalog (doc02 §2): any authenticated user, of any role
 * or school, can read them; nobody can write (no create/update/delete
 * endpoint in this session — a Policy method that doesn't exist denies by
 * default).
 */
it('lets any authenticated user, of any role, view the curricular catalog', function () {
    $school = School::factory()->create();
    $framework = CurricularFramework::factory()->create();
    $catalog = CurricularCatalog::factory()->create(['curricular_framework_id' => $framework->id]);
    $item = CurricularItem::factory()->create(['curricular_catalog_id' => $catalog->id]);

    foreach (Role::cases() as $role) {
        $user = User::factory()->forSchool($school)->role($role)->create();

        expect($user->can('view', $framework))->toBeTrue()
            ->and($user->can('view', $catalog))->toBeTrue()
            ->and($user->can('view', $item))->toBeTrue();
    }
});

it('denies writing the curricular catalog to everyone — no write endpoint in this session', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();

    expect($director->can('create', CurricularFramework::class))->toBeFalse()
        ->and($director->can('create', CurricularCatalog::class))->toBeFalse()
        ->and($director->can('create', CurricularItem::class))->toBeFalse();
});
