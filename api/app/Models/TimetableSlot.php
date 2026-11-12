<?php

namespace App\Models;

use App\Casts\TimeOfDay;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\TimetableSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a teacher's «Mis horarios» (ClickUp 1dfd3mc4bgh): for a
 * group-subject they teach, a weekday, a time and a room.
 *
 * Private to its teacher (TimetableSlotPolicy: nobody else reads it, not even
 * Direction or Psychopedagogy). It is NOT the school timetable and never
 * creates a date: it only pre-fills the time and room of a class being
 * created or edited (TimetableSlotSuggester). Changing it never touches the
 * classes already created.
 *
 * `weekday` is ISO-8601 (1 = Monday … 7 = Sunday).
 */
#[Fillable(['teacher_id', 'group_id', 'subject_id', 'weekday', 'start_time', 'room'])]
class TimetableSlot extends Model
{
    /** @use HasFactory<TimetableSlotFactory> */
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'start_time' => TimeOfDay::class,
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
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
