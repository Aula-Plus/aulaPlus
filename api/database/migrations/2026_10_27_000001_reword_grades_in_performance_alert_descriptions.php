<?php

use App\Enums\AlertType;
use App\Models\AlertRule;
use App\Services\Alerts\PerformanceAlertGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Notas» → «Calificaciones» everywhere a grade is meant (ClickUp
 * 1dfd3mc2wx3), including alerts already stored: performance alerts keep the
 * description they were generated with, so the ones created before the
 * rename still read «3 notas seguidas…». Rewrites exactly the phrasings the
 * generator used to produce ({@see AlertRule::describe()},
 * {@see PerformanceAlertGenerator}):
 *
 * - «Condición configurada por el colegio: N notas seguidas por debajo de …»
 *   → «N calificaciones seguidas por debajo de …» (start of the text);
 * - «… — notas: 4, 3 y 4.» → «… — calificaciones: 4, 3 y 4.» (end of the text);
 * - «… — promedio 4,5 en 1 nota.» / «… en 3 notas.» → «… en 1 calificación.» /
 *   «… en 3 calificaciones.» (end of the text).
 *
 * Each replacement is anchored to the start or end of the generated template,
 * so the subject name in the middle (school-written) is never touched, and
 * only `performance` alerts are read — user-written notes live in
 * `alert_actions`, not here. Idempotent: rewritten rows no longer match.
 * Raw query builder across every school on purpose (no tenant scope, no model
 * events, so no audit rows); chunked by id.
 */
return new class extends Migration
{
    private const NUMBER = '\d+(?:,\d+)?';

    public function up(): void
    {
        $number = self::NUMBER;
        $replacements = [
            '/^(Condición configurada por el colegio: \d+) notas (seguidas por debajo de )/u' => '$1 calificaciones $2',
            "/ — notas: ({$number}(?:(?:, | y ){$number})*)\.$/u" => ' — calificaciones: $1.',
            "/ — promedio ({$number}) en 1 nota\.$/u" => ' — promedio $1 en 1 calificación.',
            "/ — promedio ({$number}) en (\d+) notas\.$/u" => ' — promedio $1 en $2 calificaciones.',
        ];

        DB::table('alerts')
            ->where('type', AlertType::Performance->value)
            ->where('description', 'like', 'Condición configurada por el colegio:%')
            ->where('description', 'like', '%nota%')
            ->select(['id', 'description'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($replacements): void {
                foreach ($rows as $row) {
                    $reworded = preg_replace(array_keys($replacements), array_values($replacements), (string) $row->description);

                    if ($reworded === null || $reworded === $row->description) {
                        continue;
                    }

                    DB::table('alerts')->where('id', $row->id)->update(['description' => $reworded]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: it is a wording fix, and turning «calificaciones»
        // back into «notas» would also rewrite alerts generated after the
        // rename, which never said «notas».
    }
};
