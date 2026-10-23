<?php

namespace App\Enums;

/**
 * The shapes of "sustained low performance" condition a school can configure
 * (documento vivo, screen 11: "el umbral es una perilla, no un cálculo
 * nuestro"). Aula+ offers the shapes; the school picks one and sets the
 * numbers — Aula+ never proposes the threshold on its own.
 *
 * - ConsecutiveBelow: the student's last `consecutive_count` scores in a
 *   subject are all below `threshold` (e.g. "three scores in a row below 5").
 * - AverageBelow: the student's average in a subject over the last
 *   `period_days` days is below `threshold` (e.g. "period average below 6").
 * - DifficultySignals («Señal de dificultad», ClickUp 86e3qkwpn): at least
 *   `signal_count` difficulty signals in one same comment category on the
 *   student's observations within the last `period_days` days. No threshold,
 *   no subject. It produces `AlertType::DifficultySignal` alerts, never
 *   performance ones.
 */
enum AlertCondition: string
{
    case ConsecutiveBelow = 'consecutive_below';
    case AverageBelow = 'average_below';
    case DifficultySignals = 'difficulty_signals';

    /**
     * Whether the condition is a sustained-low-performance one (scores of a
     * subject), evaluated by `alerts:performance`.
     */
    public function isPerformance(): bool
    {
        return $this !== self::DifficultySignals;
    }

    /**
     * @return list<string>
     */
    public static function performanceValues(): array
    {
        return array_values(array_map(
            static fn (self $condition): string => $condition->value,
            array_filter(self::cases(), static fn (self $condition): bool => $condition->isPerformance()),
        ));
    }
}
