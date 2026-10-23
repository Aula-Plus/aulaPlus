<?php

namespace App\Models\Concerns;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Support\StudentFileHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * Adopt this trait on any model that needs a durable "who did what, when"
 * trail. Writes one row to `audit_logs` on created/updated/deleted (via
 * `booted()`) — application code never has to remember to log anything by
 * hand. Rows for models that belong to a student's file are written by
 * {@see StudentFileHistory}, which adds the change-history annotations.
 *
 * A model may exclude specific fields from the stored diff — e.g. long free
 * text holding sensitive content about a minor (CLAUDE.md security rule 11)
 * — by declaring the static property itself:
 *
 *     public static array $auditableExcludeFromDiff = ['summary', 'document_url'];
 *
 * Excluded fields are still recorded as having changed, just without their
 * before/after values. This is deliberately NOT declared on the trait itself
 * (only read via property_exists()): PHP does not allow a using class to
 * override a *static* trait property's default value — it's a fatal
 * "definition differs and is considered incompatible" composition error —
 * so each model that needs a non-empty list declares the property on its own.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => static::recordAuditLog($model, AuditAction::Created));
        static::updated(fn (Model $model) => static::recordAuditLog($model, AuditAction::Updated));
        static::deleted(fn (Model $model) => static::recordAuditLog($model, AuditAction::Deleted));
    }

    protected static function recordAuditLog(Model $model, AuditAction $action): void
    {
        $base = StudentFileHistory::baseAttributes($model, $action);
        $changes = static::auditableChanges($model, $action);

        // Part of a student's file: annotated (student/section/readable
        // details) and possibly split per section by the history registry.
        if (StudentFileHistory::recordModelChange($model, $action, $base, $changes)) {
            return;
        }

        AuditLog::create([...$base, 'changes' => $changes]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function auditableChanges(Model $model, AuditAction $action): array
    {
        $excluded = property_exists($model, 'auditableExcludeFromDiff') ? $model::$auditableExcludeFromDiff : [];

        if ($action === AuditAction::Updated) {
            $skip = array_filter([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()]);
            $diff = [];

            foreach ($model->getChanges() as $field => $after) {
                if (in_array($field, $skip, true)) {
                    continue;
                }

                $diff[$field] = in_array($field, $excluded, true)
                    ? ['changed' => true]
                    : ['before' => $model->getOriginal($field), 'after' => $after];
            }

            return $diff;
        }

        // created/deleted: full snapshot, with excluded fields redacted rather
        // than omitted (so it's still visible in the timeline that the field
        // existed/was set, without leaking its content).
        $snapshot = $model->attributesToArray();

        foreach ($excluded as $field) {
            if (array_key_exists($field, $snapshot)) {
                $snapshot[$field] = '[excluded]';
            }
        }

        return $snapshot;
    }
}
