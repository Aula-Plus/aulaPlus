<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\SupportMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the Aula+ team about a new support/improvement message. Only the
 * message id (plus the generic screen name) is queued; the content is loaded
 * when the mail is built.
 */
class SupportMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Spanish role names for this internal email (the SPA keeps its own map in
     * `web/src/types.ts`; this mail is rendered server-side).
     */
    private const ROLE_LABELS = [
        Role::Teacher->value => 'Docente',
        Role::Director->value => 'Director',
        Role::Psychopedagogue->value => 'Psicopedagogo',
    ];

    /**
     * @param  string|null  $screenName  Generic name of the originating screen
     *                                   ("Perfil del alumno"), sent by the SPA.
     */
    public function __construct(
        private readonly SupportMessage $message,
        private readonly ?string $screenName = null,
    ) {}

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
        $role = self::ROLE_LABELS[$message->role] ?? $message->role;

        return (new MailMessage)
            ->subject("[Aula+] {$kind}")
            ->line($message->message)
            ->line("Desde: {$this->origin($message->screen)} — {$role} — {$message->school->name}")
            ->line("Enviado por: {$message->user->name}");
    }

    /**
     * "Evaluaciones (/clases/12/evaluaciones)", or whichever part is known.
     */
    private function origin(?string $path): string
    {
        $name = $this->screenName ?: null;

        if ($name !== null && $path !== null) {
            return "{$name} ({$path})";
        }

        return $name ?? $path ?? 'pantalla no informada';
    }
}
