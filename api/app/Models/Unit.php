<?php

namespace App\Models;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A segment of an AnnualPlan's sequence. Tenant-scoped, but inherits its
 * tenant via annual_plan rather than its own school_id column — per the
 * domain doc. Do NOT add BelongsToSchool here; reach Unit only through its
 * (already tenant-scoped) AnnualPlan relation to keep isolation intact.
 */
#[Fillable(['annual_plan_id', 'name', 'position', 'start_date', 'end_date', 'materials'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'materials' => 'array',
        ];
    }

    public function annualPlan(): BelongsTo
    {
        return $this->belongsTo(AnnualPlan::class);
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function curricularItems(): BelongsToMany
    {
        return $this->belongsToMany(CurricularItem::class, 'unit_curricular_item')
            ->withTimestamps();
    }
}
