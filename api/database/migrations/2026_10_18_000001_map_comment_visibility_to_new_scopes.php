<?php

use App\Models\Comment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "Visible para" selector now offers exactly four scopes (ClickUp
 * 86e3qkwnv): everyone / Dirección y Psicopedagogía / Solo Psicopedagogía /
 * Solo quien escribe. Rewrites stored `visible_to` values to their canonical
 * form via {@see Comment::normalizeVisibleTo()}:
 *
 * - `["director"]` ("solo dirección") → `["director", "psychopedagogue"]`.
 *   Product explicitly asked psychopedagogy to read these; the read rule in
 *   Comment already grants it, this only makes the stored value say so.
 * - a list holding every role → `null` (same audience, canonical form).
 * - `[]` → `null` (already treated as "everyone" by both visibility paths).
 * - anything else (e.g. `["psychopedagogue"]`, or a legacy free combination
 *   such as `["teacher"]`) is left untouched: no row becomes visible to more
 *   people than before, apart from psychopedagogy reading it.
 *
 * `author_only` rows are not touched (their `visible_to` is always null).
 * Raw query builder on purpose: no model events, so no audit rows and no
 * content ever loaded (CLAUDE.md security rule 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('comments')
            ->whereNotNull('visible_to')
            ->select(['id', 'visible_to'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $current = json_decode((string) $row->visible_to, true);
                    if (! is_array($current)) {
                        continue;
                    }

                    $normalized = Comment::normalizeVisibleTo($current);
                    if ($normalized === $current) {
                        continue;
                    }

                    DB::table('comments')->where('id', $row->id)->update([
                        'visible_to' => $normalized === null ? null : json_encode($normalized),
                    ]);
                }
            });
    }

    /**
     * Not reversible: after `up()` a former "solo dirección" row is
     * indistinguishable from one created as "Dirección y Psicopedagogía", and
     * both read the same under the current rule anyway.
     */
    public function down(): void {}
};
