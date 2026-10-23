<?php

namespace App\Notifications;

use App\Models\ScreeningTestDesign;
use Illuminate\Notifications\Notification;

/**
 * "A screening's design changed and already rules" — sent to Dirección on
 * every save (screen 08), stored server-side so it reaches a director who
 * opens Aula+ later from another session. Ids plus the screening's name: no
 * student data at all.
 */
class ScreeningDesignChangedNotification extends Notification
{
    public function __construct(protected ScreeningTestDesign $design) {}

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
            'type' => 'screening_design_changed',
            'screening_test_type_id' => $this->design->screening_test_type_id,
            'screening_test_type_name' => $this->design->type?->name,
            'design_id' => $this->design->id,
            'changed_by_id' => $this->design->created_by_id,
        ];
    }
}
