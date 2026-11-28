<?php

namespace App\Models;

use App\Enums\JudgmentStylePreviewStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Ver ejemplo» of screen 24 (P24-JUI-02, P24-DEC-110; ClickUp 1dfd3mc4bh6):
 * a judgment in the school's style for an invented student, with the subject
 * and unit names of the group Direction picked. Never holds data of a real
 * student; requested and read by Direction only.
 */
#[Fillable(['requested_by_id', 'group_id', 'subject_id', 'status', 'draft', 'error_message'])]
class JudgmentStylePreview extends Model
{
    use BelongsToSchool;

    protected function casts(): array
    {
        return ['status' => JudgmentStylePreviewStatus::class];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
