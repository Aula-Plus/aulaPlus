<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal public face of a colleague (id, name, role) — used where staff pick
 * or see each other. Never exposes email or anything else.
 *
 * @mixin User
 */
class StaffMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->roles->first()?->name,
        ];
    }
}
