/**
 * PROPOSED TEXT, pending product confirmation (ClickUp 86e3qkwnv): neutral on
 * purpose — no count, no categories.
 */
export const PRIVATE_NOTES_SIGNAL_TEXT =
  "Tenés varias notas privadas sobre este alumno en este período."

/**
 * The author's private signal on a student file: their own "solo quien
 * escribe" notes reached the school's trend threshold. The server only ever
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
      <span className="ml-1 text-xs">(Solo vos ves esta marca.)</span>
    </div>
  )
}
