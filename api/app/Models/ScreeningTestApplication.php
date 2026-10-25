<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\ScreeningTestApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A screening test applied to a group on a date (docs/prompts/
 * 11-pruebas-de-sondeo.md §2). Its results are created up front, one per
 * active student, each with an anonymous per-application code.
 *
 * `education_level_id`, `screening_population` and `population_levels` are a
 * snapshot taken when it is applied (ClickUp 86e3t4qjh): the group's level, the
 * screening population that level corresponded to, and the screening's
 * population. Later changes to any of them apply forward only — this
 * application's place in the impact view never moves by itself; only
 * «Recalcular con el nivel actual» (RecalculateScreeningTestApplication), an
 * explicit step of a person, takes the values in force again.
 */
#[Fillable([
    'screening_test_type_id',
    'group_id',
    'education_level_id',
    'screening_population',
    'population_levels',
    'applied_by_id',
    'application_date',
    'recalculated_by_id',
    'recalculated_at',
])]
class ScreeningTestApplication extends Model
{
    /** @use HasFactory<ScreeningTestApplicationFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'application_date' => 'date',
            'population_levels' => 'array',
            'recalculated_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ScreeningTestType::class, 'screening_test_type_id');
    }

    /**
     * Whether this application counts in its screening's impact view, read
     * from the snapshot: the group had a level, that level corresponded to a
     * population, and the screening's population covered it. A screening
     * without a population applies to nobody until one is chosen.
     */
    public function countsInImpact(): bool
    {
        return self::snapshotCountsInImpact($this->screening_population, $this->population_levels, $this->education_level_id);
    }

    /**
     * @param  list<string>|null  $populationLevels
     */
    public static function snapshotCountsInImpact(?string $screeningPopulation, ?array $populationLevels, ?int $educationLevelId): bool
    {
        if ($educationLevelId === null) {
            return false;
        }

        if ($populationLevels === null || $populationLevels === []) {
            return false;
        }

        return $screeningPopulation !== null && in_array($screeningPopulation, $populationLevels, true);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }

    public function recalculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recalculated_by_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ScreeningTestResult::class);
    }
}
