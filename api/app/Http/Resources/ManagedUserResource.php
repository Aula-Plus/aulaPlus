<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member as seen on the director-only management screen. `status` is
 * derived from the lifecycle columns (never stored), so it cannot drift.
 *
 * @mixin User
 */
class ManagedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoleNames()->values(),
            'status' => $this->status(),
        ];
    }

    private function status(): string
    {
        return match (true) {
            $this->isDisabled() => 'disabled',
            $this->isPending() => 'pending',
            default => 'active',
        };
    }
}
