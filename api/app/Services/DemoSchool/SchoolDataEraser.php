<?php

namespace App\Services\DemoSchool;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Hard-deletes one school and every row that belongs to it (ClickUp
 * 1dfd3mc5g1p: the test school is wiped and rebuilt from scratch).
 *
 * Deleting the `schools` row alone is not enough: business tables cascade from
 * it, but many of them also point at users, subjects or frameworks with
 * `restrictOnDelete`, and both SQLite and PostgreSQL check RESTRICT row by row
 * during the cascade. So the rows are deleted table by table, children first,
 * in an order computed from the live foreign keys — new tables are covered
 * without touching this class:
 *
 * - a table with `school_id` loses its rows of that school;
 * - a table without it (pivots such as `accommodation_subject`) loses the rows
 *   that reference, through a cascading or restricting key, a row of that
 *   school in a table with `school_id`. Keys that `SET NULL` are left to the
 *   database, so shared rows elsewhere are never removed.
 *
 * Roles (Spatie), sessions and tokens point at users without a foreign key;
 * they are removed for the school's users explicitly.
 *
 * Only ever called for the demo school (App\Console\Commands\DemoSchool
 * refuses any other); it uses the query builder, so soft deletes and the
 * tenant scope do not apply.
 */
class SchoolDataEraser
{
    /** Tables never touched: the tenant root goes last, migrations are global. */
    protected const SKIP = ['schools', 'migrations'];

    public function erase(School $school): void
    {
        DB::transaction(function () use ($school): void {
            $userIds = User::query()->where('school_id', $school->id)->pluck('id')->all();

            $this->eraseUserSideRows($userIds);

            [$schoolTables, $plan] = $this->plan();

            foreach ($plan as $table => $keys) {
                if (in_array($table, $schoolTables, true)) {
                    DB::table($table)->where('school_id', $school->id)->delete();

                    continue;
                }

                DB::table($table)->where(function ($query) use ($keys, $school): void {
                    foreach ($keys as [$column, $parent, $parentColumn]) {
                        $query->orWhereIn($column, DB::table($parent)
                            ->select($parentColumn)
                            ->where('school_id', $school->id));
                    }
                })->delete();
            }

            DB::table('schools')->where('id', $school->id)->delete();
        });
    }

    /**
     * @param  list<int>  $userIds
     */
    protected function eraseUserSideRows(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $morphs = [
            'model_has_roles' => ['model_type', 'model_id'],
            'model_has_permissions' => ['model_type', 'model_id'],
            'personal_access_tokens' => ['tokenable_type', 'tokenable_id'],
            'notifications' => ['notifiable_type', 'notifiable_id'],
        ];

        foreach ($morphs as $table => [$typeColumn, $idColumn]) {
            if (Schema::hasTable($table)) {
                DB::table($table)
                    ->whereIn($typeColumn, [User::class, (new User)->getMorphClass()])
                    ->whereIn($idColumn, $userIds)
                    ->delete();
            }
        }

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
        }
    }

    /**
     * The tables to clear, children before parents, each with the keys that
     * tie a table without `school_id` to the school's rows.
     *
     * @return array{0: list<string>, 1: array<string, list<array{0: string, 1: string, 2: string}>>}
     */
    protected function plan(): array
    {
        $tables = collect(Schema::getTables())
            ->filter(fn (array $table) => ($table['schema'] ?? null) === null
                || in_array($table['schema'], [null, 'main', $this->currentSchema()], true))
            ->pluck('name')
            ->reject(fn (string $name) => in_array($name, self::SKIP, true))
            ->values()
            ->all();

        $schoolTables = array_values(array_filter(
            $tables,
            fn (string $table) => Schema::hasColumn($table, 'school_id'),
        ));

        // child => [parents it must be deleted before], and the keys used to
        // find the rows of a table without school_id.
        $parents = [];
        $keys = [];

        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $parent = $foreignKey['foreign_table'];
                $onDelete = strtolower((string) ($foreignKey['on_delete'] ?? ''));

                if ($parent === $table || $onDelete === 'set null' || ! in_array($parent, $tables, true)) {
                    continue;
                }

                $parents[$table][$parent] = true;

                if (! in_array($table, $schoolTables, true) && in_array($parent, $schoolTables, true)) {
                    $keys[$table][] = [$foreignKey['columns'][0], $parent, $foreignKey['foreign_columns'][0]];
                }
            }
        }

        $relevant = array_values(array_filter(
            $tables,
            fn (string $table) => in_array($table, $schoolTables, true) || isset($keys[$table]),
        ));

        $plan = [];
        foreach ($this->childrenFirst($relevant, $parents) as $table) {
            $plan[$table] = $keys[$table] ?? [];
        }

        return [$schoolTables, $plan];
    }

    /**
     * Topological order where every table comes before the tables it
     * references.
     *
     * @param  list<string>  $tables
     * @param  array<string, array<string, true>>  $parents
     * @return list<string>
     */
    protected function childrenFirst(array $tables, array $parents): array
    {
        $children = [];
        foreach ($tables as $table) {
            foreach (array_keys($parents[$table] ?? []) as $parent) {
                if (in_array($parent, $tables, true)) {
                    $children[$parent][$table] = true;
                }
            }
        }

        $order = [];
        $pending = array_fill_keys($tables, true);

        while ($pending !== []) {
            $ready = array_values(array_filter(
                array_keys($pending),
                fn (string $table) => array_diff_key($children[$table] ?? [], array_fill_keys($order, true)) === [],
            ));

            if ($ready === []) {
                throw new RuntimeException('Circular foreign keys between: '.implode(', ', array_keys($pending)));
            }

            sort($ready);
            foreach ($ready as $table) {
                $order[] = $table;
                unset($pending[$table]);
            }
        }

        return $order;
    }

    protected function currentSchema(): ?string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select current_schema()')
            : null;
    }
}
