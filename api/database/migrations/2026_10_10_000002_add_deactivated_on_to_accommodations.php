<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The day an accommodation stopped being in force (PO, ClickUp 86e3d1bwa,
 * option A): accommodations have no expiry date a user types in — they rule
 * until someone switches them off, and the system records that day on its own
 * (Accommodation::booted). Together with `starts_on` it answers "which
 * accommodations were in force on the day of this grade".
 *
 * Never part of a Form Request: only server code writes it.
 *
 * Backfill for accommodations that are already switched off (and were ever
 * effective, i.e. no pending/rejected external approval): the school-local
 * date of the latest audit-log entry that flipped `active` from true to false;
 * failing that, the date of the row's last write (`updated_at`). A row created
 * inactive and never activated then gets starts_on == deactivated_on, an empty
 * interval — correctly "never in force".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->date('deactivated_on')->nullable()->after('starts_on');
        });

        $timezone = (string) config('app.school_timezone', 'America/Montevideo');
        $localDate = fn (?string $timestamp): ?string => $timestamp === null
            ? null
            : CarbonImmutable::parse($timestamp, 'UTC')->setTimezone($timezone)->toDateString();

        DB::table('accommodations')
            ->where('active', false)
            ->where(fn ($approval) => $approval
                ->where('requires_external_approval', false)
                ->orWhere('approved', true))
            ->select(['id', 'starts_on', 'updated_at', 'created_at'])
            ->chunkById(200, function ($rows) use ($localDate, $timezone): void {
                $flips = DB::table('audit_logs')
                    ->where('auditable_type', 'App\\Models\\Accommodation')
                    ->whereIn('auditable_id', $rows->pluck('id'))
                    ->where('action', 'updated')
                    ->orderBy('created_at')
                    ->get(['auditable_id', 'changes', 'created_at'])
                    ->filter(function ($log): bool {
                        $active = json_decode((string) $log->changes, true)['active'] ?? null;

                        return is_array($active)
                            && ($active['before'] ?? null) === true
                            && ($active['after'] ?? null) === false;
                    })
                    // Ordered ascending, so the last one per accommodation wins.
                    ->mapWithKeys(fn ($log) => [$log->auditable_id => $log->created_at]);

                foreach ($rows as $row) {
                    $day = $localDate($flips[$row->id] ?? $row->updated_at ?? $row->created_at)
                        ?? CarbonImmutable::now($timezone)->toDateString();

                    // Never before the start: an interval that ends before it
                    // begins would be meaningless.
                    $startsOn = substr((string) $row->starts_on, 0, 10);
                    if ($day < $startsOn) {
                        $day = $startsOn;
                    }

                    DB::table('accommodations')->where('id', $row->id)->update(['deactivated_on' => $day]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropColumn('deactivated_on');
        });
    }
};
