<?php

namespace App\Models;

use Database\Factories\CurricularCatalogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned edition of a CurricularFramework (e.g. "ANEP EBI 2023"). Global
 * catalog — NOT tenant-scoped.
 */
#[Fillable(['curricular_framework_id', 'name', 'valid_from', 'valid_until'])]
class CurricularCatalog extends Model
{
    /** @use HasFactory<CurricularCatalogFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function curricularFramework(): BelongsTo
    {
        return $this->belongsTo(CurricularFramework::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CurricularItem::class);
    }
}
