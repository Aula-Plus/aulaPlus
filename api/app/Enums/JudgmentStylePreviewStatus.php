<?php

namespace App\Enums;

/**
 * «Ver ejemplo» of screen 24 (P24-JUI-02): `pending` while the job runs,
 * then `completed` with the example draft or `error`.
 */
enum JudgmentStylePreviewStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Error = 'error';
}
