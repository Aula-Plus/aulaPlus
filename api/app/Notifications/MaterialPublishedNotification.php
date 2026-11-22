<?php

namespace App\Notifications;

use App\Models\Material;
use Illuminate\Notifications\Notification;

/**
 * «Se agregó un material al catálogo del colegio» (screen 19, P19-DEC-04):
 * publishing takes effect on save and Psicopedagogía and Dirección are told,
 * so they can take it out. Ids only; the SPA links to the catalog folder.
 */
class MaterialPublishedNotification extends Notification
{
    public function __construct(protected Material $material) {}

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
            'type' => 'material_published',
            'material_id' => $this->material->id,
            'subject_id' => $this->material->subject_id,
            'school_grade_id' => $this->material->school_grade_id,
        ];
    }
}
