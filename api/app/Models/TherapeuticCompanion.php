<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who accompanies a student, with weekly hours and days (screen 10 ›
 * Acompañantes). A record loaded by psychopedagogy, NOT a user account
 * (decision in ClickUp 1dfd3mc4bge): the companion never logs in.
 *
 * `role` («Rol del acompañante», ClickUp 1dfd3mc4bge) is free text written
 * by psychopedagogy and visible to everyone who can see that the student has
 * a companion (direction's list, the teachers' file row). It still describes
 * a minor's support, so it is kept out of the audit diff (CLAUDE.md rule 11).
 *
 * A companion who stops accompanying is not deleted: they get `ended_on` and
 * stay in the student's history. The current ones are those without an end
 * date (scopeCurrent) — only they count for the student's flag, the active
 * count, direction's list and the file.
 */
#[Fillable(['student_id', 'name', 'weekly_hours', 'days', 'role', 'created_by_id'])]
class TherapeuticCompanion extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['role'];

    protected function casts(): array
    {
        return [
            'weekly_hours' => 'float',
            'days' => 'array',
            'ended_on' => 'date',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('ended_on');
    }

    public function isCurrent(): bool
    {
        return $this->ended_on === null;
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
        $has = static::query()->current()->where('student_id', $student->id)->exists();

        if ($student->has_therapeutic_companion !== $has) {
            $student->forceFill(['has_therapeutic_companion' => $has])->save();
        }
    }
}
