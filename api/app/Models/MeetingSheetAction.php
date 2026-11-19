<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An action agreed in a meeting, with its owner and deadline (P10-DEC-08).
 * Once the sheet is confirmed it is a task in its owner's Inicio until they
 * mark it done. Its owner reads its description and deadline, never the rest
 * of the sheet. `description` may name a student, so it is excluded from the
 * audit diff (CLAUDE.md rule 11).
 */
#[Fillable(['meeting_sheet_id', 'description', 'owner_id', 'due_on', 'done_at'])]
class MeetingSheetAction extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['description'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'done_at' => 'datetime',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(MeetingSheet::class, 'meeting_sheet_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
