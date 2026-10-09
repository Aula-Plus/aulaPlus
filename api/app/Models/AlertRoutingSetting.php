<?php

namespace App\Models;

use App\Enums\AlertType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Support\AlertRouting;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A school's override of who an alert type reaches first (ClickUp
 * 86e3jpzcv). Read through {@see AlertRouting}, which falls back
 * to the product defaults when a type has no row.
 */
#[Fillable(['type', 'recipients'])]
class AlertRoutingSetting extends Model
{
    use Auditable, BelongsToSchool;

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'recipients' => 'array',
        ];
    }
}
