<?php

namespace App\Enums;

/**
 * How a mark on «Lo que recibe la IA» was closed (ClickUp 1dfd3mc2wx2,
 * P03-AJU-03). A mark with no resolution is pending: its line is withheld
 * from the AI.
 *
 * - AdjustmentChanged: the origin accommodation changed (or stopped being in
 *   force), so the line the AI would receive is no longer the marked one.
 * - AdjustmentOk: psychopedagogy chose «El ajuste está bien»; the line goes
 *   back to the AI as it was, the accommodation untouched.
 */
enum AIInputMarkResolution: string
{
    case AdjustmentChanged = 'adjustment_changed';
    case AdjustmentOk = 'adjustment_ok';
}
