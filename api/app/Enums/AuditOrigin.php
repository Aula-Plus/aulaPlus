<?php

namespace App\Enums;

use App\Models\Concerns\Auditable;

/**
 * Who caused an `audit_logs` entry: an authenticated staff member, or the
 * system itself (e.g. a queued Job with no authenticated user, such as the
 * AI assistant applying an auto-generated proposal — see
 * {@see Auditable}). Never a fake/placeholder user.
 */
enum AuditOrigin: string
{
    case User = 'user';
    case System = 'system';
}
