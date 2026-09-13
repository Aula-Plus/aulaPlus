<?php

namespace App\Enums;

/**
 * The traffic-light band a screening-test score falls into
 * (docs/prompts/11-pruebas-de-sondeo.md §§1, 3). The identifiers are English
 * by repo convention; the Spanish meaning of each band is institutional text
 * stored per design (meaning_red/yellow/green), not on this enum.
 */
enum ScreeningColor: string
{
    case Red = 'red';
    case Yellow = 'yellow';
    case Green = 'green';

    /**
     * Classify a score against a design's cutoffs: `score <= cutoff_low` → red;
     * `score >= cutoff_high` → green; strictly in between → yellow. Red is
     * checked first, so it wins the degenerate case where the two cutoffs meet.
     */
    public static function fromScore(float $score, float $cutoffLow, float $cutoffHigh): self
    {
        return match (true) {
            $score <= $cutoffLow => self::Red,
            $score >= $cutoffHigh => self::Green,
            default => self::Yellow,
        };
    }
}
