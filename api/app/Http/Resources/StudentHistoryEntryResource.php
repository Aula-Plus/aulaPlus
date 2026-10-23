<?php

namespace App\Http\Resources;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\StudentChangeHistory;
use App\Support\StudentFileHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 *
 * One entry of a student's change history: who (and with which role), when,
 * which section and element, which action, and what changed. Only ever built
 * for entries {@see StudentChangeHistory} already cleared for the viewer —
 * that is the field-level gate; this class only shapes the output.
 *
 * `changes` is a list of `{field, before, after}` (an addition has
 * `before: null`, a removal `after: null`) or `{field, redacted: true}` when
 * the value is never shown. Rows written before the history existed have no
 * `details`; for those the redacted `changes` column is mapped to the same
 * shape (sensitive fields come out as redacted).
 */
class StudentHistoryEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $details = $this->details;

        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'action' => $this->action->value,
            'section' => $this->section?->value,
            'origin' => $this->origin->value,
            'actor' => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->actor_role,
            ],
            'element' => [
                'type' => $details['entity'] ?? StudentFileHistory::entityFor($this->auditable_type),
                'id' => $this->auditable_id,
                'label' => $details['label'] ?? null,
            ],
            'changes' => is_array($details) ? array_values($details['changes'] ?? []) : $this->legacyChanges(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legacyChanges(): array
    {
        $raw = is_array($this->changes) ? $this->changes : [];
        $entries = [];

        foreach (array_keys(StudentFileHistory::fieldsFor($this->auditable_type)) as $field) {
            if (! array_key_exists($field, $raw)) {
                continue;
            }

            $value = $raw[$field];
            $redacted = in_array($field, StudentFileHistory::REDACTED, true)
                || $value === '[excluded]'
                || (is_array($value) && array_key_exists('changed', $value));

            if ($redacted) {
                $entries[] = ['field' => $field, 'redacted' => true];
            } elseif ($this->action === AuditAction::Updated) {
                $entries[] = ['field' => $field, 'before' => $value['before'] ?? null, 'after' => $value['after'] ?? null];
            } elseif ($this->action === AuditAction::Created) {
                $entries[] = ['field' => $field, 'before' => null, 'after' => $value];
            } else {
                $entries[] = ['field' => $field, 'before' => $value, 'after' => null];
            }
        }

        return $entries;
    }
}
