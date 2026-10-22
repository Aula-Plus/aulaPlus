import { isAxiosError } from "axios"

/** Mirror of `UploadSubjectSyllabusRequest::MAX_KILOBYTES` (UX check only). */
export const MAX_SYLLABUS_BYTES = 10 * 1024 * 1024

/** Client-side check before uploading a program PDF; the backend validates again. */
export function syllabusFileError(file: File): string | null {
  const isPdf = file.type === "application/pdf" || file.name.toLowerCase().endsWith(".pdf")
  if (!isPdf) return "El programa tiene que ser un PDF."
  if (file.size > MAX_SYLLABUS_BYTES) return "El PDF no puede pesar más de 10 MB."
  return null
}

/**
 * Message for a failed upload: the backend's own when it refuses uploads
 * because file storage isn't configured (503), a generic one otherwise.
 */
export function syllabusUploadErrorMessage(error: unknown, fallback: string): string {
  if (isAxiosError(error) && error.response?.status === 503) {
    const message = (error.response.data as { message?: unknown } | undefined)?.message
    if (typeof message === "string" && message) return message
  }
  return fallback
}

/** Shown wherever direction uploads a program (CLAUDE.md rule 11). */
export const SYLLABUS_PII_NOTICE = "El PDF no debe incluir datos de alumnos (nombres, documentos ni informes)."
