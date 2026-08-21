<?php

namespace App\Models;

use App\Enums\AssessmentType;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\LogsUsageEvents;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['group_id', 'teacher_id', 'type', 'purpose', 'duration_minutes', 'content', 'variant_number'])]
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use BelongsToSchool, HasFactory, LogsUsageEvents;

    /** @see LogsUsageEvents */
    public static string $usageEventType = 'assessment.created';

    protected function casts(): array
    {
        return [
            'type' => AssessmentType::class,
            'content' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function curricularItems(): BelongsToMany
    {
        return $this->belongsToMany(CurricularItem::class, 'assessment_curricular_item')
            ->withTimestamps();
    }
}
