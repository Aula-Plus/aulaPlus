<?php

namespace App\Enums;

/** Where a material came from: a file someone uploaded, or a confirmed assessment. */
enum MaterialSource: string
{
    case Upload = 'upload';
    case Assessment = 'assessment';
}
