<?php

namespace App\Enums;

use App\Models\Concerns\Auditable;

/**
 * The lifecycle events recorded by {@see Auditable} into
 * `audit_logs`.
 */
enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
