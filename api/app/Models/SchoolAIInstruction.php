<?php

namespace App\Models;

use App\Enums\AIRequestType;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rule of the school's layer of AI instructions (screen 24, ClickUp
 * 1dfd3mc4bh6). See the migration for the scope semantics; the resolution
 * («gana la más específica») lives in App\Services\AIInstructions\
 * SchoolAIInstructions. Written by Direction only.
 */
#[Fillable(['request_type', 'education_level_id', 'school_grade_ids', 'settings', 'updated_by_id'])]
class SchoolAIInstruction extends Model
{
    use BelongsToSchool;

    protected $table = 'school_ai_instructions';

    protected function casts(): array
    {
        return [
            'request_type' => AIRequestType::class,
            'school_grade_ids' => 'array',
            'settings' => 'array',
        ];
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function isInstitutionWide(): bool
    {
        return $this->education_level_id === null;
    }

    /**
     * @return list<int>
     */
    public function gradeIds(): array
    {
        return array_values(array_map('intval', $this->school_grade_ids ?? []));
    }
}
