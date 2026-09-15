<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A school subject ("materia", e.g. "Matemática"). School-owned (tenant-scoped
 * via BelongsToSchool) and managed by directors. `name` holds the school's own
 * Spanish label; `short_code`/`color` are optional presentation hints.
 */
#[Fillable(['name', 'short_code', 'color'])]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use Auditable, BelongsToSchool, HasFactory, SoftDeletes;

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /**
     * Teachers assigned to this subject in any group (through group_teacher).
     * Distinct: a teacher who teaches it to several groups counts once.
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_teacher', 'subject_id', 'teacher_id')
            ->distinct();
    }

    /**
     * Groups where this subject is taught (through group_teacher). Distinct:
     * a group with several teachers of this subject counts once.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_teacher', 'subject_id', 'group_id')
            ->distinct();
    }
}
