<?php

namespace App\Policies;

use App\Enums\ResourceVisibility;
use App\Enums\Role;
use App\Models\GroupAddedResource;
use App\Models\User;

/**
 * A resource added to a group (ClickUp 1dfd3mc4bh4, answer of 2026-11-22).
 * Whole-group ones: everyone who sees the group, and anyone who manages the
 * group's resources (its teachers, Psicopedagogía, Dirección) can remove
 * them. A teacher's own one: among teachers only its author sees it;
 * Dirección and Psicopedagogía see that it exists and whose it is; only its
 * author removes it.
 */
class GroupAddedResourcePolicy
{
    public function view(User $user, GroupAddedResource $resource): bool
    {
        if ($user->school_id !== $resource->school_id || ! $user->can('view', $resource->group)) {
            return false;
        }

        return $resource->visibility === ResourceVisibility::Group
            || $resource->created_by_id === $user->id
            || $user->hasAnyRole(Role::schoolWideValues());
    }

    public function delete(User $user, GroupAddedResource $resource): bool
    {
        if (! $this->view($user, $resource) || ! $user->can('manageResources', $resource->group)) {
            return false;
        }

        return $resource->visibility === ResourceVisibility::Group
            || $resource->created_by_id === $user->id;
    }
}
