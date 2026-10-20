<?php

namespace App\Enums;

/**
 * The signal an author picks for EACH category marked on an observation
 * (ClickUp 86e3qkwpn): «Señal de mejora», «Señal de dificultad» or «Sin cambio
 * observado». Stored per (comment, category) on the `comment_comment_category`
 * pivot, so a signal counts only in its own category. Mandatory for every
 * category of a new observation; rows written before signals existed keep
 * `null` and, like `NoChange`, count nowhere.
 */
enum CommentSignal: string
{
    case Improvement = 'improvement';
    case Difficulty = 'difficulty';
    case NoChange = 'no_change';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $signal): string => $signal->value, self::cases());
    }

    /**
     * The signals that count toward the trend mark and the author's private
     * mark. Improvement and difficulty are deliberately counted TOGETHER for
     * now: whether they should be split into separate counts is still pending
     * a product decision (ClickUp 86e3qkwpn). «Sin cambio observado» never
     * counts.
     *
     * @return list<string>
     */
    public static function trendValues(): array
    {
        return [self::Improvement->value, self::Difficulty->value];
    }

    /**
     * Whether a stored pivot value counts toward the trend marks. `null`
     * (legacy rows, written before signals existed) never counts.
     */
    public static function countsTowardTrend(?string $value): bool
    {
        return $value !== null && in_array($value, self::trendValues(), true);
    }
}
