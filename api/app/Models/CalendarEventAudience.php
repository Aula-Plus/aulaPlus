<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One target of an institutional event's audience (ClickUp 1dfd3mc55zp): a
 * level, a generation (grade), the groups of a program within a generation,
 * or one group.
 * An event without targets concerns the whole school. See
 * App\Services\Calendar\EventAudience for how a group matches.
 */
#[Fillable(['calendar_event_id', 'education_level_id', 'school_grade_id', 'curricular_framework_id', 'group_id'])]
class CalendarEventAudience extends Model
{
    use BelongsToSchool;

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function schoolGrade(): BelongsTo
    {
        return $this->belongsTo(SchoolGrade::class);
    }

    public function curricularFramework(): BelongsTo
    {
        return $this->belongsTo(CurricularFramework::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
