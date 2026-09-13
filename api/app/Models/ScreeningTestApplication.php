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
 */
#[Fillable([
    'screening_test_type_id',
    'group_id',
    'applied_by_id',
    'application_date',
])]
class ScreeningTestApplication extends Model
{
    /** @use HasFactory<ScreeningTestApplicationFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'application_date' => 'date',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ScreeningTestType::class, 'screening_test_type_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ScreeningTestResult::class);
    }
}
