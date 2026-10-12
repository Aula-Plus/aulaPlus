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
 * failing that, the date of the row's last write (`updated_at`). A row whose
 * audit trail shows it was created inactive and never switched on gets
 * starts_on == deactivated_on, an empty interval — correctly "never in force",
 * even if it was edited later (its `updated_at` would otherwise open a false
 * in-force period).
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
                // Ordered by id (insertion order; created_at only has second
                // precision), so the last flip per accommodation wins below.
                $logs = DB::table('audit_logs')
                    ->where('auditable_type', 'App\\Models\\Accommodation')
                    ->whereIn('auditable_id', $rows->pluck('id'))
                    ->whereIn('action', ['created', 'updated'])
                    ->orderBy('id')
                    ->get(['auditable_id', 'action', 'changes', 'created_at'])
                    ->map(function ($log) {
                        $log->changes = json_decode((string) $log->changes, true) ?: [];

                        return $log;
                    });

                // `active` flips, tolerant of 1/0 storage like
                // PerformanceTimelineBuilder: before truthy → after falsy (off),
                // or the other way round (on).
                $flipped = fn ($log, bool $on): bool => $log->action === 'updated'
                    && is_array($log->changes['active'] ?? null)
                    && (bool) ($log->changes['active']['before'] ?? null) !== $on
                    && (bool) ($log->changes['active']['after'] ?? null) === $on;

                $switchedOff = $logs
                    ->filter(fn ($log): bool => $flipped($log, false))
                    ->mapWithKeys(fn ($log) => [$log->auditable_id => $log->created_at]);

                // Created inactive (per the creation snapshot) and never
                // switched on or off since: it was never in force.
                $neverOn = $logs
                    ->groupBy('auditable_id')
                    ->filter(fn ($trail): bool => $trail->contains(fn ($log): bool => $log->action === 'created'
                            && array_key_exists('active', $log->changes)
                            && ! $log->changes['active'])
                        && ! $trail->contains(fn ($log): bool => $flipped($log, true) || $flipped($log, false)))
                    ->keys()
                    ->flip();

                foreach ($rows as $row) {
                    $startsOn = substr((string) $row->starts_on, 0, 10);

                    if ($neverOn->has($row->id)) {
                        DB::table('accommodations')->where('id', $row->id)->update(['deactivated_on' => $startsOn]);

                        continue;
                    }

                    $day = $localDate($switchedOff[$row->id] ?? $row->updated_at ?? $row->created_at)
                        ?? CarbonImmutable::now($timezone)->toDateString();

                    // Never before the start: an interval that ends before it
                    // begins would be meaningless.
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
