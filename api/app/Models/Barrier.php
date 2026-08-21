<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\TracksAuthorship;
use Database\Factories\BarrierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A learning barrier for a student. Holds sensitive data about a minor's
 * learning profile — see CLAUDE.md security rule 11. `description` and
 * `coping_strategy` are excluded from the audit diff for the same reason.
 */
#[Fillable(['student_id', 'description', 'coping_strategy', 'active', 'created_by_id', 'deleted_by_id'])]
class Barrier extends Model
{
    /** @use HasFactory<BarrierFactory> */
    use Auditable, BelongsToSchool, HasFactory, SoftDeletes, TracksAuthorship;

    public static array $auditableExcludeFromDiff = ['description', 'coping_strategy'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
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

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }

    public function accommodations(): BelongsToMany
    {
        return $this->belongsToMany(Accommodation::class, 'barrier_accommodation')
            ->withPivot(['proposed_by_id', 'validated', 'validated_by_id'])
            ->withTimestamps();
    }
}
