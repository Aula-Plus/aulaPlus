<?php

namespace App\Policies;

use App\Models\CurricularFramework;
use App\Models\User;

/**
 * Global catalog (see App\Models\CurricularFramework — not tenant-scoped).
 * doc02 §2: readable by any authenticated user; write access is not granted
 * to anyone yet (seeded data, no write endpoint in this session) — no
 * create/update/delete methods are defined, so they deny by default.
 */
class CurricularFrameworkPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CurricularFramework $curricularFramework): bool
    {
        return true;
    }
}
