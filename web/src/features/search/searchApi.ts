import { api } from "@/lib/api"
import { assessmentTypeLabels, type SearchAssessmentResult, type SearchResults } from "@/types"

/** Mirrors `GlobalSearchRequest::MIN_LENGTH` / `MAX_LENGTH`. */
export const SEARCH_MIN_LENGTH = 2
export const SEARCH_MAX_LENGTH = 80

/**
 * «Buscar en Aula+»: students, groups, assessments and classes the caller could open,
 * matched by name or title. Role filtering happens server-side.
 */
export async function searchAula(q: string, signal?: AbortSignal): Promise<SearchResults> {
  // A POST with the term in the body: what is typed (maybe a student's name) never
  // travels in the URL, where access logs and history would keep it (Eitán, 26/11).
  const { data } = await api.post<{ data: SearchResults }>("/api/v1/search", { q }, { signal })
  return data.data
}

/**
 * The name an assessment shows in the results: its title (purpose) or, when it has
 * none, «tipo · materia · grupo · fecha», e.g. «Escrita · Matemática · 5.°A · 12/11»
 * (Eitán, 26/11). The backend finds untitled ones by any of those words.
 */
export function assessmentSearchLabel(assessment: SearchAssessmentResult): string {
  if (assessment.title) return assessment.title
  const dayMonth = /^\d{4}-(\d{2})-(\d{2})$/.exec(assessment.date ?? "")
  return [
    assessmentTypeLabels[assessment.type],
    assessment.subject,
    assessment.group.name,
    dayMonth ? `${dayMonth[2]}/${dayMonth[1]}` : null,
  ]
    .filter(Boolean)
    .join(" · ")
}

/**
 * Lowercase and strip the Spanish accents the backend folds too
 * (`GlobalSearch::ACCENTS`), so menu sections match the same way.
 */
export function foldForSearch(value: string): string {
  return value
    .toLocaleLowerCase("es")
    .replace(/[áéíóúüñ]/g, (char) => ({ á: "a", é: "e", í: "i", ó: "o", ú: "u", ü: "u", ñ: "n" })[char] ?? char)
}
