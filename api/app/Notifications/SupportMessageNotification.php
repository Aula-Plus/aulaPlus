<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\SupportMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

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
    use Queueable;

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
        $kind = $message->kind === SupportMessage::KIND_ISSUE ? 'Algo no funciona' : 'Propuesta de mejora';
        $role = self::ROLE_LABELS[$message->role] ?? $message->role;

        return (new MailMessage)
            ->subject("[Aula+] {$kind}")
            ->line(self::literal($message->message))
            ->line(self::literal("Desde: {$this->origin($message->screen)} — {$role} — {$message->school->name}"))
            ->line(self::literal("Enviado por: {$message->user->name}"));
    }

    /**
     * Encodes every ASCII punctuation character as a numeric HTML reference.
     * CommonMark never reads a character reference as syntax, so the text
     * renders literally (no links, images, HTML, emphasis…), and the plain-text
     * part decodes the references back to what the user typed. Returned as
     * HtmlString so Blade does not encode the references a second time.
     *
     * Laravel's Markdown::withSecuredEncoding() is not used: it only escapes
     * "[", so "\[text](url)" still renders as a link, and it drops attribute
     * escaping for every mail (e.g. action-button URLs).
     */
    private static function literal(string $text): HtmlString
    {
        return new HtmlString(preg_replace_callback(
            '/[!-\/:-@\[-`{-~]/',
            fn (array $match) => '&#'.ord($match[0]).';',
            $text,
        ));
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
