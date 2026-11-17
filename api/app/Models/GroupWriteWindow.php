<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Support\SchoolTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Direction re-enables loading in one group of a closed year (e.g. «Exámenes
 * de febrero» in 2°EMS-A until 28/02/2027). It records who enabled it, when
 * (`created_at`) and why; once `until_on` has passed — or it was ended early —
 * the group is read-only again with nobody having to do anything.
 */
#[Fillable(['group_id', 'reason', 'until_on', 'enabled_by_id', 'ended_at', 'ended_by_id'])]
class GroupWriteWindow extends Model
{
    use Auditable, BelongsToSchool;

    protected function casts(): array
    {
        return [
            'until_on' => 'date',
            'ended_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }

    /** Still in force today (school-local): not ended and the until-date not passed. */
    public function isActive(): bool
    {
        return $this->ended_at === null
            && $this->until_on->toDateString() >= SchoolTime::today()->toDateString();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('group_write_windows.ended_at')
            ->whereDate('group_write_windows.until_on', '>=', SchoolTime::today()->toDateString());
    }
}
