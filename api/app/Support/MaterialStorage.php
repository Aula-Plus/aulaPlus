<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The private disk that holds uploaded materials (screen 19, ClickUp
 * 1dfd3mc4bgx), configured as `filesystems.disks.materials` (env
 * MATERIAL_DISK). Files are only reached through temporary URLs (rule 7).
 */
final class MaterialStorage
{
    public const DISK = 'materials';

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /** In production the container's local disk does not survive a redeploy. */
    public static function isDurable(): bool
    {
        return ! (app()->isProduction() && config('filesystems.disks.'.self::DISK.'.driver') === 'local');
    }
}
