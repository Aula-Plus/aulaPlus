<?php

namespace App\Support\Reports;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The private disk that holds students' clinical reports (ClickUp 86e3jujgx),
 * configured as `filesystems.disks.reports` (env REPORT_DISK). Every reader
 * and writer goes through here, never through the app's default disk.
 */
final class ReportStorage
{
    public const DISK = 'reports';

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Whether stored files survive a redeploy: in production the container's
     * local disk is ephemeral, so uploads are refused there instead of losing
     * a clinical document.
     */
    public static function isDurable(): bool
    {
        return ! (app()->isProduction() && config('filesystems.disks.'.self::DISK.'.driver') === 'local');
    }
}
