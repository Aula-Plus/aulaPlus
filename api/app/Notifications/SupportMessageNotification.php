<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\SupportMessage;
use App\Notifications\Concerns\EscapesMailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the Aula+ team about a new support/improvement message. Only the
 * message id (plus the generic screen name) is queued; the content is loaded
 * when the mail is built.
 *
 * Every line carries user-supplied text (message, screen, names), and Laravel
 * runs mail lines through CommonMark: each line is passed through literal() so
 * nobody can plant a link, image or heading in the team's inbox.
 */
class SupportMessageNotification extends Notification implements ShouldQueue
{
    use EscapesMailText, Queueable;

    /**
     * Spanish role names for this internal email (the SPA keeps its own map in
     * `web/src/types.ts`; this mail is rendered server-side).
     */
    private const ROLE_LABELS = [
        Role::Teacher->value => 'Docente',
        Role::Director->value => 'Dirección',
        Role::Psychopedagogue->value => 'Psicopedagogía',
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
        $kind = match ($message->kind) {
            SupportMessage::KIND_ISSUE => 'Algo no funciona',
            SupportMessage::KIND_REGIME_CORRECTION => 'URGENTE · Corrección de régimen',
            default => 'Propuesta de mejora',
        };
        $role = self::ROLE_LABELS[$message->role] ?? $message->role;

        $mail = (new MailMessage)
            ->subject("[Aula+] {$kind}")
            ->line(self::literal($message->message))
            ->line(self::literal("Desde: {$this->origin($message->screen)} — {$role} — {$message->school->name}"))
            ->line(self::literal("Enviado por: {$message->user->name}"));

        // Regime corrections are top urgency (screen 29): flagged in the mail too.
        return $message->isUrgent() ? $mail->priority(1) : $mail;
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
