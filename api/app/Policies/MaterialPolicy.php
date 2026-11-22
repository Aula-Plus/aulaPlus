<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Material;
use App\Models\User;

/**
 * Screen 19 «Materiales» (ClickUp 1dfd3mc4bgx). Tenant isolation is checked
 * here too (defence in depth over the school scope).
 *
 * - Docente and Psicopedagogía upload; Dirección reads (it teaches no
 *   subject, so it has no material of its own).
 * - A material is seen by its owner, by Psicopedagogía and Dirección («lo
 *   generado en el colegio»), and by everyone in the school once it is in the
 *   institutional catalog.
 * - Only the owner proposes it to the catalog or deletes it; Psicopedagogía
 *   and Dirección take it out of the catalog (P19-INS-01).
 */
class MaterialPolicy
{
    public function create(User $user): bool
    {
        return $user->hasAnyRole([Role::Teacher->value, Role::Psychopedagogue->value]);
    }

    public function view(User $user, Material $material): bool
    {
        if (! $this->sharesSchool($user, $material)) {
            return false;
        }

        return $this->isOwner($user, $material)
            || $user->hasAnyRole(Role::schoolWideValues())
            || $material->isPublished();
    }

    public function publish(User $user, Material $material): bool
    {
        return $this->sharesSchool($user, $material) && $this->isOwner($user, $material);
    }

    public function removeFromCatalog(User $user, Material $material): bool
    {
        return $this->sharesSchool($user, $material)
            && $user->hasAnyRole(Role::schoolWideValues())
            && $material->isPublished();
    }

    public function delete(User $user, Material $material): bool
    {
        return $this->sharesSchool($user, $material) && $this->isOwner($user, $material);
    }

    protected function isOwner(User $user, Material $material): bool
    {
        return $material->owner_id === $user->id;
    }

    protected function sharesSchool(User $user, Material $material): bool
    {
        return $user->school_id === $material->school_id;
    }
}
