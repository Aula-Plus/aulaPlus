<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails an invited staff member a single-use link to set their password.
 * The plaintext token is carried only in this message and is never logged.
 */
class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/aceptar-invitacion?token='.$this->token;

        return (new MailMessage)
            ->subject('Te invitaron a Aula+')
            ->greeting("Hola {$notifiable->name},")
            ->line('Fuiste invitado a Aula+. Creá tu contraseña para activar tu cuenta.')
            ->action('Activar mi cuenta', $url)
            ->line('El enlace vence en 7 días. Si no esperabas esta invitación, ignorá este correo.');
    }
}
