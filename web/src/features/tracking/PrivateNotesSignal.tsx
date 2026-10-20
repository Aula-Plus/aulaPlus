/**
 * Text confirmed by product (ClickUp 86e3qkwnv): neutral on purpose — no
 * count, and it never names the category that reached the threshold.
 */
export const PRIVATE_NOTES_SIGNAL_TEXT =
  "Tenés varias notas privadas sobre este alumno en una categoría en este período."

/**
 * The author's private signal on a student file: their own "solo quien
 * escribe" notes reached the school's trend threshold in one same category. The server only ever
 * sends `true` to that author, so rendering it is not a disclosure to anyone
 * else. A mark, never a notification.
 */
export function PrivateNotesSignal({ active }: { active?: boolean }) {
  if (!active) return null

  return (
    <div
      role="note"
      aria-label="Señal privada"
      className="rounded-md border border-dashed bg-muted/40 p-3 text-sm text-muted-foreground"
    >
      {PRIVATE_NOTES_SIGNAL_TEXT}
    </div>
  )
}
