<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Results loaded before free bands only stored a red/yellow/green `color`;
 * their `band` (the name of the band read at load time) was left null. The
 * legacy designs named those bands Rojo/Amarillo/Verde (see
 * ScreeningTestDesign::bandList()), so the original reading is fully known:
 * backfill it. Without it every legacy result would compare a null band with
 * the current reading and be flagged as "con el diseño anterior era …" even
 * when nothing changed. Idempotent: only rows with a colour and no band.
 *
 * The design version those results were read with is not recoverable exactly
 * (the approval time was never stored), so `screening_test_design_id` stays
 * null for them.
 */
return new class extends Migration
{
    private const LEGACY_BAND_NAMES = [
        'red' => 'Rojo',
        'yellow' => 'Amarillo',
        'green' => 'Verde',
    ];

    public function up(): void
    {
        foreach (self::LEGACY_BAND_NAMES as $color => $name) {
            DB::table('screening_test_results')
                ->whereNull('band')
                ->where('color', $color)
                ->update(['band' => $name]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the previous migration's down() drops `band`.
    }
};
