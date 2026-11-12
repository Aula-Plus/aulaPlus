<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A school-local time of day without a date (e.g. a class at 08:30). Always
 * stored as `HH:MM:00`, so PostgreSQL `time` and SQLite text compare alike, and
 * always read back as `HH:MM`.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class TimeOfDay implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 5);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::normalize((string) $value);
    }

    /**
     * `8:30`, `08:30` or `08:30:00` → `08:30:00`.
     */
    public static function normalize(string $value): string
    {
        [$hours, $minutes] = array_pad(explode(':', $value), 2, '0');

        return sprintf('%02d:%02d:00', (int) $hours, (int) $minutes);
    }
}
