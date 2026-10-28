<?php

namespace App\Models;

use App\Enums\AlertCondition;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Services\Alerts\DifficultySignalAlertGenerator;
use App\Services\Alerts\PerformanceAlertGenerator;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A school-configured alert condition, configured by direction or
 * psychopedagogy: either a "sustained low performance" one (ClickUp
 * 86e3jpzcv; documento vivo screen 11), evaluated daily by
 * `alerts:performance` ({@see PerformanceAlertGenerator}), or the «Señal de
 * dificultad» one (ClickUp 86e3qkwpn: `signal_count` signals in one category
 * within `period_days` days), evaluated daily by `alerts:difficulty-signals`
 * ({@see DifficultySignalAlertGenerator}).
 *
 * `education_level_ids` are the group levels the condition applies to
 * (ClickUp 1dfd3mc2wx0); null = every level. Either way a student only counts
 * through a group of theirs that has a level: groups "Sin nivel" are in no
 * alert until one is chosen.
 */
#[Fillable([
    'condition',
    'threshold',
    'consecutive_count',
    'signal_count',
    'period_days',
    'subject_id',
    'education_level_ids',
    'active',
])]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'condition' => AlertCondition::class,
            'threshold' => 'decimal:2',
            'consecutive_count' => 'integer',
            'signal_count' => 'integer',
            'period_days' => 'integer',
            'education_level_ids' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * Whether the condition applies to a student whose groups this school
     * year have these levels. No level at all (no group, or only groups "Sin
     * nivel") never applies.
     *
     * @param  list<int>  $levelIds
     */
    public function appliesToLevels(array $levelIds): bool
    {
        if ($levelIds === []) {
            return false;
        }

        if ($this->education_level_ids === null) {
            return true;
        }

        return array_intersect($levelIds, $this->education_level_ids) !== [];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Short Spanish sentence describing the condition, stored on each alert it
     * produces so the alert reads on its own ("Condición configurada por el
     * colegio: …"). Holds no student data.
     */
    public function describe(): string
    {
        $threshold = $this->threshold === null ? '' : $this->formatNumber((float) $this->threshold);

        return match ($this->condition) {
            AlertCondition::ConsecutiveBelow => sprintf(
                '%d calificaciones seguidas por debajo de %s en la misma materia',
                $this->consecutive_count,
                $threshold,
            ),
            AlertCondition::AverageBelow => sprintf(
                'promedio de los últimos %d días por debajo de %s en una materia',
                $this->period_days,
                $threshold,
            ),
            AlertCondition::DifficultySignals => sprintf(
                '%d señales de dificultad en una misma categoría en %d días',
                $this->signal_count,
                $this->period_days,
            ),
        };
    }

    protected function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}
