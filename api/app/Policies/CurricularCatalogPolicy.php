<?php

namespace App\Policies;

use App\Models\CurricularCatalog;
use App\Models\User;

/**
 * Global catalog (see App\Models\CurricularCatalog — not tenant-scoped).
 * Same rule as CurricularFrameworkPolicy: read-only for any authenticated
 * user, no write endpoint in this session.
 */
class CurricularCatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CurricularCatalog $curricularCatalog): bool
    {
        return true;
    }
}
