<?php

namespace App\Models;

use App\Enums\MaterialCatalogStatus;
use App\Enums\MaterialSource;
use App\Enums\MaterialType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A pedagogical material (screen 19 «Materiales», ClickUp 1dfd3mc4bgx): a file
 * a teacher or psychopedagogy uploaded, or the catalog copy of a confirmed
 * assessment. «Lo tuyo es tuyo hasta que decidís compartirlo» (P19-DEC-01):
 * only the owner and the school-wide roles see it until it is published to
 * the institutional catalog.
 *
 * The publish/remove columns are deliberately not mass-assignable: only
 * MaterialCatalog writes them, recording who and when.
 */
#[Fillable([
    'owner_id',
    'group_id',
    'subject_id',
    'school_grade_id',
    'source',
    'assessment_id',
    'title',
    'type',
    'tags',
    'path',
    'original_name',
    'format',
    'size',
    'content',
])]
class Material extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['path', 'original_name', 'content'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'source' => MaterialSource::class,
            'type' => MaterialType::class,
            'catalog_status' => MaterialCatalogStatus::class,
            'tags' => 'array',
            'content' => 'array',
            'size' => 'integer',
            'published_at' => 'datetime',
            'no_student_data_confirmed_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->catalog_status === MaterialCatalogStatus::Published;
    }

    public function isUpload(): bool
    {
        return $this->source === MaterialSource::Upload;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class)->withTrashed();
    }

    public function schoolGrade(): BelongsTo
    {
        return $this->belongsTo(SchoolGrade::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    public function curricularItems(): BelongsToMany
    {
        return $this->belongsToMany(CurricularItem::class, 'material_curricular_item')
            ->withTimestamps();
    }
}
