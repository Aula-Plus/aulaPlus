<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\AuditOrigin;
use App\Enums\StudentFileSection;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Support\StudentFileHistory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One immutable entry in the audit trail: who did what, to which record, and
 * when. Written automatically by {@see Auditable} on
 * created/updated/deleted — application code never inserts into this table
 * directly (the one exception is StudentFileHistory::recordManual(), for
 * pivot-only changes to a student's file that fire no model event).
 *
 * Rows that belong to a student's file also carry `student_id`, `section`,
 * `actor_role` and `details` — see {@see StudentFileHistory}. `details` can
 * hold content about a minor (CLAUDE.md rule 11), hence encrypted at rest and
 * hidden from serialization: only StudentHistoryEntryResource exposes it,
 * after the per-record visibility check.
 */
#[Fillable([
    'school_id',
    'auditable_type',
    'auditable_id',
    'student_id',
    'section',
    'action',
    'user_id',
    'actor_role',
    'origin',
    'changes',
    'details',
    'created_at',
])]
class AuditLog extends Model
{
    use BelongsToSchool;

    public $timestamps = false;

    protected $hidden = ['details'];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'origin' => AuditOrigin::class,
            'changes' => 'array',
            'section' => StudentFileSection::class,
            'details' => 'encrypted:array',
            'created_at' => 'datetime',
        ];
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
