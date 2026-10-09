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
 */
enum AlertCondition: string
{
    case ConsecutiveBelow = 'consecutive_below';
    case AverageBelow = 'average_below';
}
