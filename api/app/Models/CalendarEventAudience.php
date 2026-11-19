<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One target of an institutional event's audience (ClickUp 1dfd3mc55zp): a
 * level, a generation (grade) or the groups of a program within a generation.
 * An event without targets concerns the whole school. See
 * App\Services\Calendar\EventAudience for how a group matches.
 */
#[Fillable(['calendar_event_id', 'education_level_id', 'school_grade_id', 'curricular_framework_id'])]
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

    /**
     * @return array{level_id: int|null, grade_id: int|null, framework_id: int|null}
     */
    public function target(): array
    {
        return [
            'level_id' => $this->education_level_id,
            'grade_id' => $this->school_grade_id,
            'framework_id' => $this->curricular_framework_id,
        ];
    }
}
