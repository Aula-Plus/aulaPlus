<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\ScreeningTestDesignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A version of a screening's design (screen 08): 2 to 5 bands, each with a
 * name, a colour, the score it goes up to (`max`, inclusive; the last band may
 * have none) and the institutional meaning of the band, written by the school
 * and never by the AI.
 *
 * A design rules as soon as it is saved (`approved = true`); there is no
 * approval step any more. Dirección can revert the one in force
 * (`reverted_at`), and the previous design rules again. Older rows may still
 * carry the legacy red/yellow/green cutoffs; {@see self::bandList()} reads
 * them the same way they were classified before.
 */
#[Fillable([
    'screening_test_type_id',
    'bands',
    'reason',
    'cutoff_low',
    'cutoff_high',
    'meaning_red',
    'meaning_yellow',
    'meaning_green',
    'created_by_id',
    'approved',
    'approved_by_id',
    'reverted_at',
    'reverted_by_id',
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
            'bands' => 'array',
            'reverted_at' => 'datetime',
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

    public function revertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by_id');
    }

    /**
     * The design's bands, lowest first: `name`, `color`, `max` (inclusive upper
     * score, null for an open-ended last band) and `meaning`. Legacy rows
     * without `bands` are read from their red/yellow/green cutoffs with the
     * same rule they used (red <= low < yellow < high <= green).
     *
     * @return list<array{name: string, color: string, max: float|null, meaning: string|null}>
     */
    public function bandList(): array
    {
        if (is_array($this->bands) && $this->bands !== []) {
            return array_values(array_map(static fn (array $band): array => [
                'name' => (string) $band['name'],
                'color' => (string) $band['color'],
                'max' => isset($band['max']) ? (float) $band['max'] : null,
                'meaning' => $band['meaning'] ?? null,
            ], $this->bands));
        }

        return [
            ['name' => 'Rojo', 'color' => 'red', 'max' => (float) $this->cutoff_low, 'meaning' => $this->meaning_red],
            ['name' => 'Amarillo', 'color' => 'yellow', 'max' => round((float) $this->cutoff_high - 0.01, 2), 'meaning' => $this->meaning_yellow],
            ['name' => 'Verde', 'color' => 'green', 'max' => null, 'meaning' => $this->meaning_green],
        ];
    }

    /**
     * The band a raw score falls into: the first band whose `max` reaches the
     * score; a score above every `max` falls into the last band. The single
     * source of the classification rule, so callers never re-implement it.
     *
     * @return array{index: int, name: string, color: string}
     */
    public function bandFor(float $score): array
    {
        $bands = $this->bandList();
        $last = count($bands) - 1;

        foreach ($bands as $index => $band) {
            if ($index === $last || ($band['max'] !== null && $score <= $band['max'])) {
                return ['index' => $index, 'name' => $band['name'], 'color' => $band['color']];
            }
        }

        return ['index' => $last, 'name' => $bands[$last]['name'], 'color' => $bands[$last]['color']];
    }
}
