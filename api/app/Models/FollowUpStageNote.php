<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A progress note or clarification ("avances y aclaraciones") on a follow-up
 * stage, with its author and time. `body` may hold sensitive observations
 * about a minor: excluded from the audit diff (CLAUDE.md security rule 11).
 */
// `school_id` is always copied from the stage server-side, never from input.
#[Fillable(['school_id', 'follow_up_stage_id', 'author_id', 'body'])]
class FollowUpStageNote extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['body'];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(FollowUpStage::class, 'follow_up_stage_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
