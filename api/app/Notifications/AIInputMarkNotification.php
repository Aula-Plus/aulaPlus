<?php

namespace App\Notifications;

use App\Enums\AccommodationCategory;
use App\Models\AIInputMark;
use App\Notifications\Concerns\EscapesMailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the Aula+ team (the «Ayuda y sugerencias» recipients,
 * SUPPORT_NOTIFY_EMAILS) about a mark on «Lo que recibe la IA» — a new «La
 * IA lo entendió mal» or an «El ajuste está bien» (ClickUp 1dfd3mc2wx2,
 * P03-AJU-03 / P03-ACT-53).
 *
 * Minimal content, by decision: the line exactly as the AI received it, the
 * origin accommodation type, the subject, psychopedagogy's comment and the
 * group's level. From the school, only its id. Never the group's name, a
 * student's name, who marked it or any count — nothing that lets the team
 * recognise the group or a student (CLAUDE.md security rule 11). Only the
 * mark is queued; the content is loaded when the mail is built.
 */
class AIInputMarkNotification extends Notification implements ShouldQueue
{
    use EscapesMailText, Queueable;

    public const KIND_MARKED = 'marked';

    public const KIND_ADJUSTMENT_OK = 'adjustment_ok';

    /**
     * Spanish labels for this internal e-mail (rendered server-side; the SPA
     * keeps its own map in `web/src/types.ts`).
     */
    private const CATEGORY_LABELS = [
        'access' => 'Acceso',
        'content' => 'Contenido',
        'criteria' => 'Criterio',
    ];

    public function __construct(
        private readonly AIInputMark $mark,
        private readonly string $kind,
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
        $mark = $this->mark->loadMissing('group.educationLevel', 'subject');
        $marked = $this->kind === self::KIND_MARKED;
        $comment = $marked ? $mark->comment : $mark->resolution_comment;
        $category = $mark->category instanceof AccommodationCategory
            ? self::CATEGORY_LABELS[$mark->category->value]
            : 'Sin tipo';

        return (new MailMessage)
            ->subject($marked ? '[Aula+] La IA lo entendió mal' : '[Aula+] El ajuste está bien')
            ->line(self::literal($marked
                ? 'Psicopedagogía marcó una línea de «Lo que recibe la IA» como mal interpretada. Mientras la marca esté pendiente, la línea no se envía.'
                : 'Psicopedagogía revisó una línea marcada de «Lo que recibe la IA» y dijo que el ajuste está bien: la línea vuelve a enviarse sin cambios.'))
            ->line(self::literal('Línea tal como la recibió la IA: '.json_encode($mark->line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            ->line(self::literal("Tipo de ajuste de origen: {$category}"))
            ->line(self::literal('Materia: '.($mark->subject?->name ?? 'sin materia elegida')))
            ->line(self::literal('Comentario de Psicopedagogía: '.(filled($comment) ? $comment : 'sin comentario')))
            // The level chosen from the list only: the old free-text level
            // may carry the group's own name.
            ->line(self::literal('Nivel del grupo: '.($mark->group?->educationLevel?->label ?? 'sin nivel')))
            ->line(self::literal("Colegio: #{$mark->school_id} · Marca: #{$mark->id}"));
    }
}
