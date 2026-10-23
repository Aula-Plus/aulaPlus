<?php

namespace App\Enums;

/**
 * How often a school takes a screening (screen 08 "Cada cuánto se toma").
 * `EveryFewYears` is paired with `frequency_years` on the type.
 */
enum ScreeningFrequency: string
{
    case Yearly = 'yearly';
    case TwiceYearly = 'twice_yearly';
    case EveryFewYears = 'every_few_years';
    case OnDemand = 'on_demand';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $frequency): string => $frequency->value, self::cases());
    }
}
