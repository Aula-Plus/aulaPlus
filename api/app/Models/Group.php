<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class group (e.g. "3° A"). Owned by the school and, optionally, led by a
 * single teacher. This is only the tenancy skeleton — pedagogical fields
 * (lesson plans, assessments, …) are added in later milestones.
 */
#[Fillable(['name', 'level', 'year', 'teacher_id'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use BelongsToSchool, HasFactory;

    protected $table = 'groups';

    /**
     * The teacher responsible for this group. Used by GroupPolicy to grant a
     * teacher access to their own groups.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
