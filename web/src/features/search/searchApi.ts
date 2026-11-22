import { api } from "@/lib/api"
import type { SearchResults } from "@/types"

/** Mirrors `GlobalSearchRequest::MIN_LENGTH` / `MAX_LENGTH`. */
export const SEARCH_MIN_LENGTH = 2
export const SEARCH_MAX_LENGTH = 80

/**
 * «Buscar en Aula+»: students, groups and assessments the caller could open,
 * matched by name or title. Role filtering happens server-side.
 */
export async function searchAula(q: string, signal?: AbortSignal): Promise<SearchResults> {
  const { data } = await api.get<{ data: SearchResults }>("/api/v1/search", { params: { q }, signal })
  return data.data
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
