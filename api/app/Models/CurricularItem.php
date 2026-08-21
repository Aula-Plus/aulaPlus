<?php

namespace App\Models;

use App\Enums\CurricularItemType;
use Database\Factories\CurricularItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in a curricular catalog's hierarchy (e.g. general competency ->
 * curricular space -> subject -> strand -> substrand). Global catalog — NOT
 * tenant-scoped. Simple adjacency list via parent_id, no nested sets.
 */
#[Fillable(['curricular_catalog_id', 'parent_id', 'type', 'code', 'name', 'description'])]
class CurricularItem extends Model
{
    /** @use HasFactory<CurricularItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CurricularItemType::class,
        ];
    }

    public function curricularCatalog(): BelongsTo
    {
        return $this->belongsTo(CurricularCatalog::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'unit_curricular_item')
            ->withTimestamps();
    }

    public function assessments(): BelongsToMany
    {
        return $this->belongsToMany(Assessment::class, 'assessment_curricular_item')
            ->withTimestamps();
    }

    /**
     * All descendants of this item, flattened, recursing through the whole
     * subtree (not just direct children).
     *
     * @return Collection<int, self>
     */
    public function descendants(): Collection
    {
        $children = $this->children;

        return $children
            ->concat($children->flatMap(fn (self $child): Collection => $child->descendants()))
            ->values();
    }
}
