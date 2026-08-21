<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\TracksAuthorship;
use Database\Factories\AccommodationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pedagogical accommodation for a student. Holds sensitive data about a
 * minor's learning profile — see CLAUDE.md security rule 11. `description`
 * is excluded from the audit diff for the same reason (never log full
 * sensitive content); `llm_rule` will feed the session-5 AI assistant and is
 * excluded too.
 */
#[Fillable([
    'student_id',
    'type',
    'active',
    'description',
    'focus_area',
    'llm_rule',
    'requires_external_approval',
    'approved',
    'created_by_id',
    'deleted_by_id',
])]
class Accommodation extends Model
{
    /** @use HasFactory<AccommodationFactory> */
    use Auditable, BelongsToSchool, HasFactory, SoftDeletes, TracksAuthorship;

    public static array $auditableExcludeFromDiff = ['description', 'llm_rule'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'llm_rule' => 'array',
            'requires_external_approval' => 'boolean',
            'approved' => 'boolean',
        ];
    }

    /**
     * Whether this accommodation actually applies in practice: it must be
     * active, and if it requires external approval, that approval must have
     * been granted (approved === true — pending `null` or rejected `false`
     * are not effective). Use this everywhere "does this accommodation apply
     * right now" matters, rather than reading `active` alone.
     */
    public function isEffective(): bool
    {
        return $this->active && (! $this->requires_external_approval || $this->approved === true);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }

    public function barriers(): BelongsToMany
    {
        return $this->belongsToMany(Barrier::class, 'barrier_accommodation')
            ->withPivot(['proposed_by_id', 'validated', 'validated_by_id'])
            ->withTimestamps();
    }
}
