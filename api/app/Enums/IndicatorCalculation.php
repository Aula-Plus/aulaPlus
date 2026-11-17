<?php

namespace App\Enums;

/**
 * What an indicator calculates (screen 30, P30-IND-03 «Qué se calcula»):
 * the datum as it is, the average of several, the Z score within a
 * population, or the difference between two (A − B). The Spanish labels live
 * in the frontend.
 */
enum IndicatorCalculation: string
{
    case Raw = 'raw';
    case Average = 'average';
    case ZScore = 'z_score';
    case Difference = 'difference';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * How many data the calculation takes: [min, max].
     *
     * @return array{0: int, 1: int}
     */
    public function inputRange(): array
    {
        return match ($this) {
            self::Raw, self::ZScore => [1, 1],
            self::Average => [2, 6],
            self::Difference => [2, 2],
        };
    }
}
