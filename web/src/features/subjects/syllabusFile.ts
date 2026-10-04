/** Mirror of `UploadSubjectSyllabusRequest::MAX_KILOBYTES` (UX check only). */
export const MAX_SYLLABUS_BYTES = 10 * 1024 * 1024

/** Client-side check before uploading a program PDF; the backend validates again. */
export function syllabusFileError(file: File): string | null {
  const isPdf = file.type === "application/pdf" || file.name.toLowerCase().endsWith(".pdf")
  if (!isPdf) return "El programa tiene que ser un PDF."
  if (file.size > MAX_SYLLABUS_BYTES) return "El PDF no puede pesar más de 10 MB."
  return null
}
