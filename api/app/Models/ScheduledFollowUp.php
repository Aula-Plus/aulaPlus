<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\ScheduledFollowUpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A follow-up someone scheduled on a student for a given date
 * (docs/prompts/17-seguimiento-programado.md). It is scheduled by a person and
 * nothing expires on its own — this is the only entity that models a person
 * asking to revisit a case on a date. Deliberately generic (student + free
 * description + date), with no structural link to Accommodation/Barrier yet.
 *
 * `description` and `resolution_note` may hold sensitive observations about a
 * minor, so they are excluded from the audit diff (CLAUDE.md security rule
 * 11), same pattern as Comment/Accommodation/Alert.
 */
#[Fillable([
    'student_id',
    'description',
    'due_date',
    'created_by_id',
    'resolved',
    'resolved_by_id',
    'resolved_at',
    'resolution_note',
])]
class ScheduledFollowUp extends Model
{
    /** @use HasFactory<ScheduledFollowUpFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    public static array $auditableExcludeFromDiff = ['description', 'resolution_note'];

    /**
     * Populate the DB default in memory too, so a freshly created model (and
     * its audit diff / `is_overdue`) reflects `resolved = false` without a
     * round-trip to reload it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'resolved' => false,
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * Whether this follow-up has reached its due date without being resolved.
     * Computed server-side in the app timezone (config `app.timezone`) so
     * every consumer gets one consistent answer instead of each client
     * re-implementing the date/timezone arithmetic — see the spec §3.
     */
    public function isOverdue(): bool
    {
        return ! $this->resolved && $this->due_date->lessThanOrEqualTo(Carbon::today());
    }
}
