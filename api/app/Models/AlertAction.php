<?php

namespace App\Models;

use App\Enums\AlertOutcome;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in an alert's thread (ClickUp 86e3jpzdp): someone chose a way out
 * — "me ocupo yo", "se la paso a otro rol", "la dejo en observación" — with a
 * responsible person, a deadline and an optional note for whoever receives
 * it. The note is operational (what was done with the case), never a
 * diagnosis, and is kept out of the audit diff (CLAUDE.md rule 11).
 */
#[Fillable(['alert_id', 'actor_id', 'outcome', 'assignee_id', 'due_on', 'note'])]
class AlertAction extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['note'];

    protected function casts(): array
    {
        return [
            'outcome' => AlertOutcome::class,
            'due_on' => 'date',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
