<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\TracksAuthorship;
use Database\Factories\AssessmentResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's result on one assessment (docs/prompts/13-evaluaciones-
 * resultados.md §2). Tenant-scoped (its own school_id via BelongsToSchool),
 * audited (Auditable) and authored (created_by_id via TracksAuthorship).
 *
 * A result is not clinical data (it's an academic score), so — unlike Comment
 * or Student's learning profile — nothing is excluded from the audit diff and
 * the read endpoints are school-wide, same as AssessmentPolicy::view.
 *
 * The unique (assessment_id, student_id) pair (see the migration) enforces one
 * result per student per assessment; the controller upserts on that pair.
 */
#[Fillable(['assessment_id', 'student_id', 'score', 'feedback', 'created_by_id'])]
class AssessmentResult extends Model
{
    /** @use HasFactory<AssessmentResultFactory> */
    use Auditable, BelongsToSchool, HasFactory, TracksAuthorship;

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
