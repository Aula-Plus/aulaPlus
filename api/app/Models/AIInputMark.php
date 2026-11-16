<?php

namespace App\Models;

use App\Enums\AccommodationCategory;
use App\Enums\AIInputMarkResolution;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A mark on one line of a group's «Lo que recibe la IA» (ClickUp
 * 1dfd3mc2wx2; documento vivo screen 03, P03-AJU-03 / P03-ACT-53).
 *
 * Psychopedagogy marks a line «La IA lo entendió mal»: while the mark is
 * pending (no `resolution`) that line is withheld from the AI. It closes when
 * the origin accommodation changes (the line the AI would receive is no
 * longer this one) or with «El ajuste está bien» (the line goes back as it
 * was). Every mark and every «El ajuste está bien» is sent to the Aula+ team
 * by e-mail (AIInputMarkNotification) with minimal, non-identifying content;
 * after a week still pending, the person who marked it gets an alert
 * (`alerts:ai-input-marks`).
 *
 * `line` is the line exactly as the AI received it — a pedagogical action
 * already free of student names — and `comment` is free text the form warns
 * not to fill with names or health data; neither is ever logged.
 */
#[Fillable([
    'group_id',
    'subject_id',
    'line_key',
    'line',
    'category',
    'comment',
    'marked_by_id',
    'resolution',
    'resolution_comment',
    'resolved_by_id',
    'resolved_at',
])]
class AIInputMark extends Model
{
    use BelongsToSchool;

    protected $table = 'ai_input_marks';

    protected function casts(): array
    {
        return [
            'line' => 'array',
            'category' => AccommodationCategory::class,
            'resolution' => AIInputMarkResolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class)->withTrashed();
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /** The weekly alert of this mark, once it was raised. */
    public function alert(): HasOne
    {
        return $this->hasOne(Alert::class, 'ai_input_mark_id');
    }

    public function isPending(): bool
    {
        return $this->resolution === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('ai_input_marks.resolution');
    }
}
