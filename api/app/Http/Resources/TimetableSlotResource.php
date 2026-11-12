<?php

namespace App\Http\Resources;

use App\Models\TimetableSlot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TimetableSlot
 */
class TimetableSlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group' => $this->whenLoaded('group', fn () => ['id' => $this->group->id, 'name' => $this->group->name]),
            'subject' => $this->whenLoaded('subject', fn () => ['id' => $this->subject->id, 'name' => $this->subject->name]),
            'weekday' => $this->weekday,
            'start_time' => $this->start_time,
            'room' => $this->room,
        ];
    }
}
