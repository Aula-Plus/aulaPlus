<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\AccommodationInstanceOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deactivates a single {@see Accommodation} for a single {@see Assessment}
 * instance (docs/prompts/18-ajustes-categoria-instancia.md §2). The record
 * IS the "constancia": `reason` is why the accommodation was switched off for
 * that one evaluation. The accommodation itself stays in force everywhere else
 * — the override is deliberately pinned to `assessment_id`, never to the
 * student nor the accommodation in general.
 *
 * Tenant-scoped and audited. `reason` may hold context about a minor's
 * learning profile, so it is excluded from the stored audit diff — same rule
 * as Accommodation.description (CLAUDE.md security rule 11 / 2).
 */
#[Fillable([
    'accommodation_id',
    'assessment_id',
    'deactivated_by_id',
    'reason',
])]
class AccommodationInstanceOverride extends Model
{
    /** @use HasFactory<AccommodationInstanceOverrideFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    public static array $auditableExcludeFromDiff = ['reason'];

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_id');
    }
}
