<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The yearly review of a student's accommodations by psychopedagogy (screen
 * 06 «Revisión anual», ClickUp 1dfd3mc4bgc). It records only when and who —
 * never what was reviewed, so it holds no sensitive content.
 */
#[Fillable(['student_id', 'school_year', 'reviewed_on', 'reviewed_by_id'])]
class StudentAnnualReview extends Model
{
    use BelongsToSchool;

    protected function casts(): array
    {
        return [
            'school_year' => 'integer',
            'reviewed_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
