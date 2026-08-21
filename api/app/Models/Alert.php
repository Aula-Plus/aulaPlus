<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An early-warning alert for a student (docs/prompts/04-seguimiento-
 * institucional.md §4). Generated automatically by `alerts:generate` today;
 * `description` holds a short, non-PII summary and is excluded from the
 * audit diff (CLAUDE.md security rule 11), same pattern as Accommodation/
 * Barrier.
 */
#[Fillable([
    'student_id',
    'type',
    'severity',
    'description',
    'resolved',
    'resolved_by_id',
    'resolved_at',
])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    public static array $auditableExcludeFromDiff = ['description'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}
