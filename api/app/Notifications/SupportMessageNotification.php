<?php

namespace App\Notifications;

use App\Models\SupportMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the Aula+ team about a new support/improvement message. Only the
 * message id is queued; the content is loaded when the mail is built.
 */
class SupportMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SupportMessage $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->message->loadMissing('user', 'school');
        $kind = $message->kind === SupportMessage::KIND_ISSUE ? 'Algo no funciona' : 'Propuesta de mejora';

        return (new MailMessage)
            ->subject("[Aula+] {$kind}")
            ->line($message->message)
            ->line("De: {$message->user->name} ({$message->role}) — {$message->school->name}")
            ->line('Pantalla: '.($message->screen ?? 'no informada'));
    }
}
