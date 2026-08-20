<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Database\Factories\AnnualPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A teacher's annual plan for a group and subject. When student_id is set,
 * this is an individualized plan (PEI/PTP) for that student within the group
 * rather than the group's general plan.
 */
#[Fillable([
    'group_id',
    'curricular_framework_id',
    'teacher_id',
    'student_id',
    'description',
    'year',
    'subject',
    'language',
])]
class AnnualPlan extends Model
{
    /** @use HasFactory<AnnualPlanFactory> */
    use BelongsToSchool, HasFactory;

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function curricularFramework(): BelongsTo
    {
        return $this->belongsTo(CurricularFramework::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
