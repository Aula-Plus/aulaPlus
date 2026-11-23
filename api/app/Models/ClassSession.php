<?php

namespace App\Models;

use App\Casts\TimeOfDay;
use App\Enums\ClassSessionStatus;
use App\Enums\ObjectiveCoverage;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\LogsUsageEvents;
use Database\Factories\ClassSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
    'sections',
    'outcome',
    'teacher_notes',
    'status',
    'closing_result',
    'closed_at',
])]
/**
 * A class of a group. Its `start_time` and `room` (both optional) are data of
 * the class itself (ClickUp 1dfd3mc4bgh): the Calendar and the Inicio read
 * them from here, never from a school timetable (P21-DEC-03). A teacher's
 * «Mis horarios» only pre-fills them; changing it never touches a class.
 *
 * A class confirmed from an AI proposal (screens 14–15, ClickUp 86e3jujh0)
 * keeps the sections the teacher approved in `sections`, and its one-tap
 * closing («Todo / Una parte / Nada») in `closing_result` (an
 * ObjectiveCoverage value) and `closed_at`.
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
            'sections' => 'array',
            'closing_result' => ObjectiveCoverage::class,
            'closed_at' => 'datetime',
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

    /** The unit objectives this class works, with what was covered on closing it. */
    public function unitObjectives(): BelongsToMany
    {
        return $this->belongsToMany(UnitObjective::class, 'class_session_unit_objective')
            ->withPivot('coverage')
            ->withTimestamps();
    }
}
