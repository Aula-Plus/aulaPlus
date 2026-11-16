<?php

namespace App\Models;

use App\Enums\IndicatorCalculation;
use App\Enums\ZScorePopulation;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\IndicatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What the school decides to calculate from its data (screen 30
 * «Indicadores», P30-IND-03): a calculation over one or more data, the bands
 * with the meaning the school writes for each, where it shows and whether
 * teachers see it. Nothing here comes from the AI (P30-DEC-04).
 *
 * `inputs` is a list of `{type, id}` references (IndicatorSourceType);
 * `bands` uses the same shape as a screening design: name, color, max
 * (inclusive, null for the last) and meaning.
 */
#[Fillable([
    'name',
    'calculation',
    'inputs',
    'base_100',
    'z_population',
    'bands',
    'display_places',
    'teacher_visible',
    'created_by_id',
    'updated_by_id',
])]
class Indicator extends Model
{
    /** @use HasFactory<IndicatorFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'calculation' => IndicatorCalculation::class,
            'z_population' => ZScorePopulation::class,
            'inputs' => 'array',
            'bands' => 'array',
            'display_places' => 'array',
            'base_100' => 'boolean',
            'teacher_visible' => 'boolean',
        ];
    }

    /**
     * The band a value falls into: the first whose `max` reaches it; above
     * every `max`, the last one. Same rule as ScreeningTestDesign::bandFor.
     *
     * @return array{index: int, name: string, color: string}|null
     */
    public function bandFor(float $value): ?array
    {
        return self::bandIn($this->bands ?? [], $value);
    }

    /**
     * @param  list<array<string, mixed>>  $bands
     * @return array{index: int, name: string, color: string}|null
     */
    public static function bandIn(array $bands, float $value): ?array
    {
        $bands = array_values($bands);
        $last = count($bands) - 1;

        if ($last < 0) {
            return null;
        }

        foreach ($bands as $index => $band) {
            if ($index === $last || (isset($band['max']) && $value <= (float) $band['max'])) {
                return ['index' => $index, 'name' => (string) $band['name'], 'color' => (string) $band['color']];
            }
        }

        return null;
    }
}
