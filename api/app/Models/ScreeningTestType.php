<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\ScreeningTestTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of screening test offered by a school (docs/prompts/
 * 11-pruebas-de-sondeo.md §1), e.g. "Comprensión lectora 2do ciclo". Several
 * types can be active at once; each carries its own history of designs.
 */
#[Fillable([
    'name',
    'active',
    'created_by_id',
])]
class ScreeningTestType extends Model
{
    /** @use HasFactory<ScreeningTestTypeFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function designs(): HasMany
    {
        return $this->hasMany(ScreeningTestDesign::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ScreeningTestApplication::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The design in force for computing NEW colors: the most recent design of
     * this type with `approved === true`. A pending or rejected design is never
     * used to compute results (spec §1). Null when the type has no approved
     * design yet.
     */
    public function currentApprovedDesign(): ?ScreeningTestDesign
    {
        return $this->designs()
            ->where('approved', true)
            ->orderByDesc('id')
            ->first();
    }
}
