<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Database\Factories\UsageEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per tracked usage/adoption signal (login, planning-entity created,
 * …) — feeds the director-only adoption dashboard
 * (docs/prompts/04-seguimiento-institucional.md §5). High volume, so
 * deliberately NOT `Auditable` (no diff/audit overhead needed for a log) and
 * has no `updated_at` (rows are write-once).
 *
 * `metadata` must never carry a student's name or clinical content — only
 * IDs (CLAUDE.md security rules 2 and 11).
 */
#[Fillable(['user_id', 'event_type', 'metadata'])]
class UsageEvent extends Model
{
    /** @use HasFactory<UsageEventFactory> */
    use BelongsToSchool, HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
