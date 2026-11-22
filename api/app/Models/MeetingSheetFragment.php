<?php

namespace App\Models;

use App\Enums\ObservationScope;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The part of a sheet that goes to one discussed student's file, with the
 * scope it will have there (P10-PLA-05). Written by psychopedagogy for now
 * (`source` = manual): an AI split proposal waits until family data exists
 * (product answer C in ClickUp 1dfd3mc4bge).
 * On confirmation it becomes an observation (`comment_id`).
 */
#[Fillable(['meeting_sheet_id', 'student_id', 'content', 'scope', 'source', 'comment_id'])]
class MeetingSheetFragment extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['content'];

    protected $attributes = ['source' => 'manual'];

    protected function casts(): array
    {
        return ['scope' => ObservationScope::class];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(MeetingSheet::class, 'meeting_sheet_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
