<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who accompanies a student, with weekly hours and days (screen 10 ›
 * Acompañantes). A record loaded by psychopedagogy, NOT a user account
 * (decision in ClickUp 1dfd3mc4bge): the companion never logs in.
 *
 * `reason` («motivo») belongs to psychopedagogy: direction reads the list
 * without it (P10-DEC-09) and the student's teachers see only name, hours and
 * days in the file. It is sensitive (CLAUDE.md rule 11), so it is excluded
 * from the audit diff and only serialized for psychopedagogy
 * (TherapeuticCompanionPolicy::viewReason).
 */
#[Fillable(['student_id', 'name', 'weekly_hours', 'days', 'reason', 'created_by_id'])]
class TherapeuticCompanion extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['reason'];

    protected function casts(): array
    {
        return [
            'weekly_hours' => 'float',
            'days' => 'array',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Keeps the student's `has_therapeutic_companion` flag (shown in the file
     * header and summarized for the AI as a count) in line with the records.
     */
    public static function syncStudentFlag(Student $student): void
    {
        $has = static::query()->where('student_id', $student->id)->exists();

        if ($student->has_therapeutic_companion !== $has) {
            $student->forceFill(['has_therapeutic_companion' => $has])->save();
        }
    }
}
