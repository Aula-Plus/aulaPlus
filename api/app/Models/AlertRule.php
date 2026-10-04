<?php

namespace App\Models;

use App\Enums\AlertCondition;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Services\Alerts\PerformanceAlertGenerator;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A school-configured "sustained low performance" condition (ClickUp
 * 86e3jpzcv; documento vivo screen 11). Configured by direction or
 * psychopedagogy and evaluated daily by `alerts:performance`
 * ({@see PerformanceAlertGenerator}).
 */
#[Fillable(['condition', 'threshold', 'consecutive_count', 'period_days', 'subject_id', 'active'])]
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
            'period_days' => 'integer',
            'active' => 'boolean',
        ];
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
        $threshold = $this->formatNumber((float) $this->threshold);

        return match ($this->condition) {
            AlertCondition::ConsecutiveBelow => sprintf(
                '%d notas seguidas por debajo de %s en la misma materia',
                $this->consecutive_count,
                $threshold,
            ),
            AlertCondition::AverageBelow => sprintf(
                'promedio de los últimos %d días por debajo de %s en una materia',
                $this->period_days,
                $threshold,
            ),
        };
    }

    protected function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}
