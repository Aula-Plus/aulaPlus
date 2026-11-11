<?php

namespace App\Models;

use App\Casts\TimeOfDay;
use App\Enums\ClassSessionStatus;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\LogsUsageEvents;
use Database\Factories\ClassSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'group_id',
    'subject_id',
    'unit_id',
    'assessment_id',
    'date',
    'start_time',
    'duration_minutes',
    'room',
    'title',
    'objective',
    'description',
    'outcome',
    'teacher_notes',
    'status',
])]
/**
 * A class of a group. Its `start_time` and `room` (both optional) are data of
 * the class itself (ClickUp 1dfd3mc4bgh): the Calendar and the Inicio read
 * them from here, never from a school timetable (P21-DEC-03). A teacher's
 * «Mis horarios» only pre-fills them; changing it never touches a class.
 */
class ClassSession extends Model
{
    /** @use HasFactory<ClassSessionFactory> */
    use BelongsToSchool, HasFactory, LogsUsageEvents;

    /** @see LogsUsageEvents */
    public static string $usageEventType = 'class_session.created';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'start_time' => TimeOfDay::class,
            'status' => ClassSessionStatus::class,
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
