<?php

namespace App\Models;

use App\Enums\MeetingSheetStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A meeting sheet («planilla», screen 10 › Planillas): filled once per meeting
 * (P10-DEC-02) — type, date, group or individual, who was there, which
 * students were discussed, what was discussed and the actions that came out
 * of it (P10-DEC-08).
 *
 * While a draft, its fragments are the proposed split per student; nothing
 * reaches a file until psychopedagogy confirms (P10-DEC-06). The whole sheet
 * is psychopedagogy's: direction only learns that it existed, its date, type
 * and group (P10-DEC-04); a teacher only ever gets the fragment that landed
 * in their student's file and the actions they own.
 *
 * `content` is as sensitive as a diagnosis (the screen's red line), so it is
 * excluded from the audit diff (CLAUDE.md rule 11).
 */
#[Fillable([
    'meeting_sheet_type_id', 'psychopedagogy_meeting_id', 'held_on', 'group_id', 'external_attendees',
    'content', 'status', 'created_by_id', 'confirmed_by_id', 'confirmed_at',
])]
class MeetingSheet extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['content'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'status' => MeetingSheetStatus::class,
            'held_on' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function sheetType(): BelongsTo
    {
        return $this->belongsTo(MeetingSheetType::class, 'meeting_sheet_type_id');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(PsychopedagogyMeeting::class, 'psychopedagogy_meeting_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_sheet_user');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'meeting_sheet_student');
    }

    public function fragments(): HasMany
    {
        return $this->hasMany(MeetingSheetFragment::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(MeetingSheetAction::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function isConfirmed(): bool
    {
        return $this->status === MeetingSheetStatus::Confirmed;
    }
}
