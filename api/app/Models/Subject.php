<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * A subject may carry its curricular program as a PDF (`syllabus_*`, ClickUp
 * 86e3dt6ag) — private file, temporary URLs only — and may be linked to the
 * global curricular catalog (`curricular_item_id`). The syllabus columns are
 * deliberately NOT mass-assignable: only SubjectSyllabusController and
 * ExtractSubjectSyllabusText write them.
 */
#[Fillable(['name', 'short_code', 'color'])]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use Auditable, BelongsToSchool, HasFactory, SoftDeletes;

    /** The extracted program text can be long; keep it out of the audit diff. */
    public static array $auditableExcludeFromDiff = ['syllabus_text'];

    protected $hidden = ['syllabus_path', 'syllabus_text'];

    protected function casts(): array
    {
        return [
            'syllabus_size' => 'integer',
            'syllabus_uploaded_at' => 'datetime',
            'syllabus_text_extracted_at' => 'datetime',
        ];
    }

    public function hasSyllabus(): bool
    {
        return $this->syllabus_path !== null;
    }

    /**
     * Where the program PDF's text extraction (ExtractSubjectSyllabusText)
     * stands: `pending` until the job has run, then `ready` when it yielded
     * text the AI can use, or `failed` when it didn't (scanned or encrypted
     * PDF, parse error, timeout, crash). Null without a PDF.
     *
     * @return 'pending'|'ready'|'failed'|null
     */
    public function syllabusTextStatus(): ?string
    {
        return match (true) {
            ! $this->hasSyllabus() => null,
            $this->syllabus_text_extracted_at === null => 'pending',
            $this->syllabus_text !== null => 'ready',
            default => 'failed',
        };
    }

    /**
     * Precompute, in the same query, whether $user teaches each subject, so
     * listing subjects doesn't run one `teachers` query per row to decide
     * SubjectPolicy::viewSyllabus (see isTaughtBy).
     */
    public function scopeWithTaughtBy(Builder $query, User $user): Builder
    {
        return $query->withExists([
            'teachers as '.self::taughtByAttribute($user) => fn (Builder $teachers) => $teachers->whereKey($user->id),
        ]);
    }

    /** Whether $user teaches this subject in some group (group_teacher). */
    public function isTaughtBy(User $user): bool
    {
        $attribute = self::taughtByAttribute($user);

        if (array_key_exists($attribute, $this->attributes)) {
            return (bool) $this->attributes[$attribute];
        }

        return $this->teachers()->whereKey($user->id)->exists();
    }

    /** Keyed by user id, so a precomputed flag can never answer for someone else. */
    protected static function taughtByAttribute(User $user): string
    {
        return 'taught_by_user_'.$user->id;
    }

    /** The catalog entry this subject is linked to, once it's in the catalog. */
    public function curricularItem(): BelongsTo
    {
        return $this->belongsTo(CurricularItem::class);
    }

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
