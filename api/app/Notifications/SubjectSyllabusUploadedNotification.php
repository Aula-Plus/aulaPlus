<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Tells the Aula+ support team that a school uploaded (or replaced) a
 * subject's curricular program PDF, so they can load it into the curricular
 * catalog (ClickUp 86e3dt6ag: «Ivy Thomas subió el programa de Ayuda Social»).
 *
 * Sent to the same recipients as {@see SupportMessageNotification}
 * (`services.support.notify_to` ← SUPPORT_NOTIFY_EMAILS). Only the subject and
 * uploader ids plus the original file name are queued; names are loaded when
 * the mail is built. The mail carries staff/curricular metadata only — never
 * the file, its text, or a link to it (CLAUDE.md rule 7).
 *
 * School, subject, uploader and file names are user-supplied, and Laravel runs
 * mail lines through CommonMark: every line goes through literal() so none of
 * them can plant a link, image or HTML in the team's inbox.
 */
class SubjectSyllabusUploadedNotification extends Notification implements ShouldQueue
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
     * @param  string  $fileName  Original name of the uploaded file, captured at
     *                            upload time (a later replacement must not change
     *                            what this mail reports).
     */
    public function __construct(
        private readonly Subject $subject,
        private readonly User $uploader,
        private readonly string $fileName,
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
        $subject = $this->subject->loadMissing('school');
        $school = $subject->school->name;
        $headline = "{$school} subió el programa de {$subject->name}";
        $code = $subject->short_code ? " ({$subject->short_code})" : '';
        $role = $this->uploader->getRoleNames()->first();
        $roleLabel = self::ROLE_LABELS[$role] ?? $role ?? 'sin rol';
        $catalog = $subject->curricular_item_id !== null
            ? 'Catálogo: la materia ya está vinculada al catálogo curricular.'
            : 'Catálogo: la materia todavía no está en el catálogo curricular.';

        return (new MailMessage)
            // The subject is a plain-text header, not Markdown: no escaping needed.
            ->subject("[Aula+] {$headline}")
            ->line(self::literal("{$headline}."))
            ->line(self::literal("Materia: {$subject->name}{$code}"))
            ->line(self::literal("Archivo: {$this->fileName}"))
            ->line(self::literal($catalog))
            ->line(self::literal("Subido por: {$this->uploader->name} — {$roleLabel}"))
            ->line(self::literal("Referencia: colegio #{$subject->school_id}, materia #{$subject->id}"));
    }

    /**
     * Encodes every ASCII punctuation character as a numeric HTML reference.
     * CommonMark never reads a character reference as syntax, so the text
     * renders literally (no links, images, HTML, emphasis…), and the plain-text
     * part decodes the references back to what the user typed. Returned as
     * HtmlString so Blade does not encode the references a second time.
     *
     * Same approach as the support-message mail; Laravel's
     * Markdown::withSecuredEncoding() is not used: it only escapes "[", so
     * "\[text](url)" still renders as a link.
     */
    private static function literal(string $text): HtmlString
    {
        return new HtmlString(preg_replace_callback(
            '/[!-\/:-@\[-`{-~]/',
            fn (array $match) => '&#'.ord($match[0]).';',
            $text,
        ));
    }
}
