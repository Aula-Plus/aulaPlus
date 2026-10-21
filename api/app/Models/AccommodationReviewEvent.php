<?php

namespace App\Models;

use App\Enums\AccommodationReviewAction;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a direction proposal's review (ClickUp 86e3qkwkk). Append-only:
 * it is itself the history, so it is not Auditable. `note` (a return comment,
 * a withdrawal or rejection reason) may hold sensitive content about a minor
 * — only school-wide roles read it, and it is never logged (CLAUDE.md rule 11).
 */
#[Fillable(['accommodation_id', 'user_id', 'action', 'note'])]
class AccommodationReviewEvent extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => AccommodationReviewAction::class,
        ];
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
