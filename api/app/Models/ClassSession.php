<?php

namespace App\Models;

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
    'unit_id',
    'assessment_id',
    'date',
    'duration_minutes',
    'title',
    'objective',
    'description',
    'outcome',
    'teacher_notes',
    'status',
])]
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
            'status' => ClassSessionStatus::class,
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
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
