<?php

namespace App\Policies;

use App\Models\CurricularItem;
use App\Models\User;

/**
 * Global catalog (see App\Models\CurricularItem — not tenant-scoped). Same
 * rule as CurricularFrameworkPolicy: read-only for any authenticated user,
 * no write endpoint in this session.
 */
class CurricularItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CurricularItem $curricularItem): bool
    {
        return true;
    }
}
