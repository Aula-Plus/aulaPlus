<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'auditable_type' => class_basename($this->auditable_type),
            'auditable_id' => $this->auditable_id,
            'action' => $this->action->value,
            'user_id' => $this->user_id,
            'origin' => $this->origin->value,
            'changes' => $this->changes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
