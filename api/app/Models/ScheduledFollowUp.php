<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\ScheduledFollowUpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    'assigned_to_id',
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

    /**
     * The person responsible for it (the creator unless they picked someone
     * else). Decides whose pending list it appears in, not who can see it.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * People, besides the responsible one, who also follow it.
     *
     * @return BelongsToMany<User, $this>
     */
    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'scheduled_follow_up_user')->withTimestamps();
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * Whether this follow-up's due date has passed without being resolved.
     * The due date is the last valid day, so a follow-up becomes overdue the
     * day *after* it (`due_date < today`), not on the due date itself.
     * Computed server-side in the app timezone (config `app.timezone`) so
     * every consumer gets one consistent answer instead of each client
     * re-implementing the date/timezone arithmetic — see the spec §3.
     */
    public function isOverdue(): bool
    {
        return ! $this->resolved && $this->due_date->lessThan(Carbon::today());
    }
}
