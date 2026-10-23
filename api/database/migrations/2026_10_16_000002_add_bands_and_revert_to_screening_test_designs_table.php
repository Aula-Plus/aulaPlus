<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Free bands (screen 08): a design now holds 2 to 5 bands, each with a name,
 * a colour, the score it goes up to and its mandatory meaning, in `bands`.
 * The fixed red/yellow/green columns stay (nullable) only so older rows keep
 * their original text; existing rows are backfilled into `bands` with the
 * exact same classification (red <= low < yellow < high <= green).
 *
 * A design now rules as soon as it is saved (no approval step). Dirección can
 * revert the one in force: `reverted_at`/`reverted_by_id` mark it, and the
 * previous design rules again. `reason` is the optional "Motivo" of a change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_test_designs', function (Blueprint $table) {
            $table->json('bands')->nullable()->after('screening_test_type_id');
            $table->text('reason')->nullable()->after('bands');
            $table->timestamp('reverted_at')->nullable()->after('approved_by_id');
            $table->foreignId('reverted_by_id')->nullable()->after('reverted_at')
                ->constrained('users')->nullOnDelete();
            $table->decimal('cutoff_low', 8, 2)->nullable()->change();
            $table->decimal('cutoff_high', 8, 2)->nullable()->change();
            $table->text('meaning_red')->nullable()->change();
            $table->text('meaning_yellow')->nullable()->change();
            $table->text('meaning_green')->nullable()->change();
        });

        DB::table('screening_test_designs')->whereNull('bands')->orderBy('id')->each(function (object $design): void {
            DB::table('screening_test_designs')->where('id', $design->id)->update([
                'bands' => json_encode([
                    ['name' => 'Rojo', 'color' => 'red', 'max' => (float) $design->cutoff_low, 'meaning' => $design->meaning_red],
                    ['name' => 'Amarillo', 'color' => 'yellow', 'max' => round((float) $design->cutoff_high - 0.01, 2), 'meaning' => $design->meaning_yellow],
                    ['name' => 'Verde', 'color' => 'green', 'max' => null, 'meaning' => $design->meaning_green],
                ]),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('screening_test_designs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverted_by_id');
            $table->dropColumn(['bands', 'reason', 'reverted_at']);
        });
    }
};
