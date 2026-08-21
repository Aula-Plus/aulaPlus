<?php

namespace App\Enums;

enum AlertSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
