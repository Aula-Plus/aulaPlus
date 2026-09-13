<?php

namespace App\Models;

use App\Enums\ScreeningColor;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\ScreeningTestDesignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A version of a screening-test type's design (docs/prompts/
 * 11-pruebas-de-sondeo.md §1): the numeric cutoffs plus the institutional
 * meaning of each traffic-light band. `approved` follows the same tri-state
 * pattern as Accommodation.approved — null pending, true/false resolved —
 * with the director-only approve/reject workflow of
 * AccommodationApprovalController.
 */
#[Fillable([
    'screening_test_type_id',
    'cutoff_low',
    'cutoff_high',
    'meaning_red',
    'meaning_yellow',
    'meaning_green',
    'created_by_id',
    'approved',
    'approved_by_id',
])]
class ScreeningTestDesign extends Model
{
    /** @use HasFactory<ScreeningTestDesignFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'cutoff_low' => 'decimal:2',
            'cutoff_high' => 'decimal:2',
            'approved' => 'boolean',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ScreeningTestType::class, 'screening_test_type_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /**
     * The traffic-light band this design assigns to a raw score. Delegates to
     * {@see ScreeningColor::fromScore()} — the single source of the cutoff
     * rules, so controllers never re-implement the comparison.
     */
    public function colorFor(float $score): ScreeningColor
    {
        return ScreeningColor::fromScore($score, (float) $this->cutoff_low, (float) $this->cutoff_high);
    }
}
