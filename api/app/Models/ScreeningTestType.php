<?php

namespace App\Models;

use App\Enums\ScreeningFrequency;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\ScreeningTestTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A kind of screening test offered by a school (docs/prompts/
 * 11-pruebas-de-sondeo.md §1), e.g. "Comprensión lectora 2do ciclo". Several
 * types can be active at once; each carries its own history of designs.
 */
#[Fillable([
    'name',
    'area',
    'levels',
    'frequency',
    'frequency_years',
    'teacher_visible',
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
            'levels' => 'array',
            'frequency' => ScreeningFrequency::class,
            'frequency_years' => 'integer',
            'teacher_visible' => 'boolean',
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
     * The designs that rule or ruled, newest first: saved (`approved = true`)
     * and not reverted. The first one is the design in force.
     *
     * When the `designs` relation is already loaded (e.g. eager-loaded by the
     * listing endpoint) they are resolved from that in-memory collection, so
     * listing N screenings stays a single extra query instead of an N+1.
     *
     * @return Collection<int, ScreeningTestDesign>
     */
    public function ruledDesigns(int $limit = 2): Collection
    {
        if ($this->relationLoaded('designs')) {
            return $this->designs
                ->filter(fn (ScreeningTestDesign $design): bool => $design->approved === true && $design->reverted_at === null)
                ->sortByDesc('id')
                ->take($limit)
                ->values();
        }

        return $this->designs()
            ->where('approved', true)
            ->whereNull('reverted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The design in force for reading results: the most recent saved design
     * that was not reverted. A pending or rejected legacy design never rules.
     * Null when the screening has no design yet.
     */
    public function currentApprovedDesign(): ?ScreeningTestDesign
    {
        return $this->ruledDesigns(1)->first();
    }

    /**
     * The design that ruled right before the one in force — what "Volver
     * atrás" goes back to. Null when there is none.
     */
    public function previousDesign(): ?ScreeningTestDesign
    {
        return $this->ruledDesigns(2)->get(1);
    }
}
