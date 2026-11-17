<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Screen 30 (ClickUp 1dfd3mc4bgp, PO answer of 17/11): a subject datum of an
 * indicator or a crossing now says which grades it reads. The ones saved
 * before keep their behaviour — every grade of the year (`all`) — and now
 * say so. Data only: `inputs` and `conditions` are JSON columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(static fn (array $source): array => ($source['type'] ?? null) === 'subject' && ! isset($source['grades'])
            ? [...$source, 'grades' => ['rule' => 'all', 'category' => null]]
            : $source);
    }

    public function down(): void
    {
        // Back to reading every grade, the only behaviour before the rule.
        $this->rewrite(static function (array $source): array {
            unset($source['grades']);

            return $source;
        });
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    protected function rewrite(callable $change): void
    {
        DB::table('indicators')->orderBy('id')->select(['id', 'inputs'])->each(function (object $row) use ($change): void {
            $inputs = json_decode((string) $row->inputs, true);

            if (is_array($inputs)) {
                DB::table('indicators')->where('id', $row->id)->update([
                    'inputs' => json_encode(array_map(static fn ($input) => is_array($input) ? $change($input) : $input, $inputs)),
                ]);
            }
        });

        DB::table('crossings')->orderBy('id')->select(['id', 'conditions'])->each(function (object $row) use ($change): void {
            $conditions = json_decode((string) $row->conditions, true);

            if (is_array($conditions)) {
                DB::table('crossings')->where('id', $row->id)->update([
                    'conditions' => json_encode(array_map(static fn ($condition) => is_array($condition) && is_array($condition['source'] ?? null)
                        ? [...$condition, 'source' => $change($condition['source'])]
                        : $condition, $conditions)),
                ]);
            }
        });
    }
};
