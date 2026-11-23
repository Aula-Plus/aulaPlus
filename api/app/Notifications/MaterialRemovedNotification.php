<?php

namespace App\Notifications;

use App\Models\Material;
use Illuminate\Notifications\Notification;

/**
 * «Sacaron del catálogo tu material X» (screen 19, ClickUp 1dfd3mc4bgx): to the
 * owner when Psicopedagogía or Dirección take their material out of the
 * catalog. Ids plus the optional reason the remover wrote for the owner; the
 * title is resolved when listed (NotificationController), only while the
 * owner can still see the material.
 */
class MaterialRemovedNotification extends Notification
{
    public function __construct(protected Material $material, protected ?string $reason) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'material_removed',
            'material_id' => $this->material->id,
            'subject_id' => $this->material->subject_id,
            'school_grade_id' => $this->material->school_grade_id,
            'reason' => $this->reason,
        ];
    }
}
