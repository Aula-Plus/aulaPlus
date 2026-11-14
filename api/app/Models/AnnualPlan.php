<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\LogsUsageEvents;
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
    'subject_id',
    'curricular_framework_id',
    'teacher_id',
    'student_id',
    'description',
    'year',
    'language',
])]
class AnnualPlan extends Model
{
    /** @use HasFactory<AnnualPlanFactory> */
    use Auditable, BelongsToSchool, HasFactory, LogsUsageEvents;

    /** @see LogsUsageEvents */
    public static string $usageEventType = 'annual_plan.created';

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
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

    /**
     * Route binding only resolves the group's general plan (screen 12): an
     * individualized plan (`student_id` set, legacy PEI shape) is not part of
     * this screen and resolves to 404, like `index()` never lists it. The
     * SchoolScope still applies, so another school's plan is a 404 too. When
     * individualized plans get their own endpoints they need their own binding.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->whereNull('student_id')
            ->first();
    }
}
