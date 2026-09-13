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
     *
     * When the `designs` relation is already loaded (e.g. eager-loaded by the
     * type listing endpoint) the in-force design is resolved from that
     * in-memory collection, so listing N types with their current design stays
     * a single extra query instead of an N+1.
     */
    public function currentApprovedDesign(): ?ScreeningTestDesign
    {
        if ($this->relationLoaded('designs')) {
            return $this->designs
                ->where('approved', true)
                ->sortByDesc('id')
                ->first();
        }

        return $this->designs()
            ->where('approved', true)
            ->orderByDesc('id')
            ->first();
    }
}
