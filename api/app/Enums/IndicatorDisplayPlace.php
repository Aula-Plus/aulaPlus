<?php

namespace App\Enums;

/**
 * Where an indicator is shown (P30-IND-03 «Dónde se ve»). Only stored in this
 * delivery: the student file and group profile render it in the second one
 * (ClickUp 1dfd3mc4bgp). `cross_chart` decides whether it is offered as an
 * axis of «Gráfico de cruces».
 */
enum IndicatorDisplayPlace: string
{
    case ProfileBar = 'profile_bar';
    case GroupBandBars = 'group_band_bars';
    case CrossChart = 'cross_chart';
    case ProfileBell = 'profile_bell';
    case ProfileTimeline = 'profile_timeline';
    case ProfileBand = 'profile_band';
    case GroupPoints = 'group_points';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
