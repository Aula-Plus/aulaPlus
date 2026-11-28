<?php

namespace App\Models;

use App\Casts\TimeOfDay;
use App\Enums\PsychopedagogyMeetingKind;
use App\Enums\PsychopedagogyMeetingStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An entry of psychopedagogy's agenda (screen 10 › Agenda): day, time, kind
 * (individual session or a meeting of one of the school's sheet types), with
 * whom (a student and/or a group), the team members and status.
 *
 * Only psychopedagogy reads the rows; direction gets counts per type
 * (P10-AGE-03), never with whom. Marking an individual session done may
 * leave a note in the student's file as an observation (`note_comment_id`,
 * P10-DEC-10); a meeting leaves a sheet (MeetingSheet).
 *
 * Each one is also a shared event (`calendar_event_id`, type `meeting`) in
 * the calendar of its creator and of each person cited, who is notified
 * (ClickUp 1dfd3mc56kp, P21-DEC-01). App\Services\Calendar\MeetingEvents
 * keeps it in step; set only there.
 */
#[Fillable([
    'kind', 'meeting_sheet_type_id', 'scheduled_on', 'start_time', 'student_id', 'group_id',
    'external_attendees', 'status', 'completed_at', 'note_comment_id', 'created_by_id',
])]
class PsychopedagogyMeeting extends Model
{
    use Auditable, BelongsToSchool;

    protected $attributes = ['status' => 'scheduled'];

    protected function casts(): array
    {
        return [
            'kind' => PsychopedagogyMeetingKind::class,
            'status' => PsychopedagogyMeetingStatus::class,
            'scheduled_on' => 'date',
            'start_time' => TimeOfDay::class,
            'completed_at' => 'datetime',
        ];
    }

    public function sheetType(): BelongsTo
    {
        return $this->belongsTo(MeetingSheetType::class, 'meeting_sheet_type_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'psychopedagogy_meeting_user');
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }

    public function sheet(): HasOne
    {
        return $this->hasOne(MeetingSheet::class);
    }

    public function isIndividualSession(): bool
    {
        return $this->kind === PsychopedagogyMeetingKind::IndividualSession;
    }
}
