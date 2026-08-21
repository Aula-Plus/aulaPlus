<?php

namespace App\Enums;

/**
 * Optional tone a comment's author may attach to a Comment. Feeds the
 * `alerts:generate` early-alert rule (docs/prompts/04-seguimiento-
 * institucional.md §4), which counts recent `Concerning` comments.
 */
enum CommentTone: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Concerning = 'concerning';
}
