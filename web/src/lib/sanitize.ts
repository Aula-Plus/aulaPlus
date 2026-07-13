import DOMPurify from "dompurify"

/**
 * Sanitize untrusted HTML before rendering it (security rule #6).
 *
 * AI-generated content (evaluaciones, planificaciones, boletines) will be
 * rendered as HTML later. It MUST pass through a real sanitizer — never a
 * homemade regex — before going anywhere near `dangerouslySetInnerHTML`.
 */
export function sanitizeHtml(dirty: string): string {
  return DOMPurify.sanitize(dirty, { USE_PROFILES: { html: true } })
}
