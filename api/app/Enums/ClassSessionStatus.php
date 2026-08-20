<?php

namespace App\Enums;

enum ClassSessionStatus: string
{
    case Planned = 'planned';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
