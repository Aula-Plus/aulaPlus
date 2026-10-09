<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\CommentCategory;
use App\Models\User;

/**
 * Comment categories are a school catalog: any staff member reads them (the
 * comment form needs the list), only a director edits them.
 */
class CommentCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        // Explicit staff check rather than `true` (CLAUDE.md security rule 4);
        // the list itself is tenant-scoped by SchoolScope.
        return $user->hasAnyRole(Role::values());
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, CommentCategory $category): bool
    {
        return $user->school_id === $category->school_id
            && $user->hasRole(Role::Director->value);
    }

    public function delete(User $user, CommentCategory $category): bool
    {
        return $this->update($user, $category);
    }
}
