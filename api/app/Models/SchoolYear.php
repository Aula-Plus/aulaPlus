<?php

namespace App\Models;

use App\Enums\SchoolYearStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school's calendar year (screen 26 «Año y calendario», ClickUp
 * 1dfd3mc4bh7). `year` matches `groups.school_year`. Direction closes it from
 * `closing_enabled_from` on and then opens the next one; closing never deletes
 * or moves anything (P26-ANO-03, línea roja).
 */
#[Fillable([
    'year',
    'status',
    'closing_enabled_from',
    'opened_at',
    'opened_by_id',
    'closed_at',
    'closed_by_id',
])]
class SchoolYear extends Model
{
    use Auditable, BelongsToSchool;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'status' => SchoolYearStatus::class,
            'closing_enabled_from' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === SchoolYearStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === SchoolYearStatus::Closed;
    }

    public function periods(): HasMany
    {
        return $this->hasMany(SchoolPeriod::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }
}
