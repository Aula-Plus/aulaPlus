<?php

namespace App\Notifications;

use App\Models\ScreeningTestDesign;
use Illuminate\Notifications\Notification;

/**
 * "Dirección reverted your change to a screening's design" — sent to whoever
 * authored the reverted design. Ids plus the screening's name only.
 */
class ScreeningDesignRevertedNotification extends Notification
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
            'type' => 'screening_design_reverted',
            'screening_test_type_id' => $this->design->screening_test_type_id,
            'screening_test_type_name' => $this->design->type?->name,
            'design_id' => $this->design->id,
            'reverted_by_id' => $this->design->reverted_by_id,
        ];
    }
}
