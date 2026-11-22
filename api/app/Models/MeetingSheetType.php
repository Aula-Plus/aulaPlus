<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A type of meeting sheet («tipo de planilla», screen 10 P10-DEC-01): defined
 * once by the school, used the same by every sheet so meetings can be
 * compared years later. Direction renames and adds them from «Colegio y
 * estructura › Institución» (P23-DEC-61); there is no delete, so the history
 * of past sheets keeps its types.
 */
#[Fillable(['name', 'position'])]
class MeetingSheetType extends Model
{
    use BelongsToSchool;

    /** Every school starts with these three (data, not UI copy). */
    public const DEFAULT_NAMES = ['Reunión de profesores', 'Entrevista con familia', 'Reunión con equipo tratante'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(MeetingSheet::class);
    }

    /**
     * Idempotently create the default types for a school (explicit school_id:
     * callable from migrations/console with no tenant set). Only when the
     * school has none yet, so a renamed default is not created again.
     */
    public static function seedDefaults(int $schoolId): void
    {
        if (static::withoutGlobalScopes()->where('school_id', $schoolId)->exists()) {
            return;
        }

        foreach (self::DEFAULT_NAMES as $position => $name) {
            (new static)->forceFill([
                'school_id' => $schoolId,
                'name' => $name,
                'position' => $position,
            ])->save();
        }
    }
}
