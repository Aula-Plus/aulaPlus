import { Badge } from "@/components/ui/badge"
import { formatShortDate } from "@/lib/utils"
import { roleLabels, type CommentShare } from "@/types"

/**
 * Extracts shared by psychopedagogy («Compartir con», ClickUp 86e3qkwnv) shown
 * in the student's observations. A recipient sees only the extract text — the
 * original observation never reaches them; the author also sees who got it.
 */
export function SharedExtractsList({ shares }: { shares: CommentShare[] }) {
  if (shares.length === 0) return null

  return (
    <ul className="grid gap-3" aria-label="Extractos compartidos">
      {shares.map((share) => (
        <li key={share.id} className="rounded-md border border-info-background bg-info-background/30 p-3">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone="neutral">Extracto compartido por Psicopedagogía</Badge>
            <span className="text-xs text-muted-foreground">{formatShortDate(share.created_at)}</span>
          </div>
          <p className="mt-1 text-sm whitespace-pre-wrap">{share.content}</p>
          {share.recipients && share.recipients.length > 0 && (
            <p className="mt-1 text-xs text-muted-foreground">
              Compartido con:{" "}
              {share.recipients
                .map((recipient) =>
                  recipient.role ? `${recipient.name} (${roleLabels[recipient.role]})` : recipient.name,
                )
                .join(", ")}
            </p>
          )}
        </li>
      ))}
    </ul>
  )
}
