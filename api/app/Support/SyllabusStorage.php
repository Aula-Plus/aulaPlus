<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The private disk that holds subjects' curricular program PDFs (ClickUp
 * 86e3dt6ag), configured as `filesystems.disks.syllabi` (env SYLLABUS_DISK).
 * Every reader and writer of those files goes through here, never through the
 * app's default disk.
 */
final class SyllabusStorage
{
    public const DISK = 'syllabi';

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Whether stored files survive a redeploy. In production the container's
     * local disk is ephemeral: a program uploaded there would vanish on the
     * next deploy and «Ver programa» would 404, so uploads are refused instead.
     */
    public static function isDurable(): bool
    {
        return ! (app()->isProduction() && config('filesystems.disks.'.self::DISK.'.driver') === 'local');
    }
}
