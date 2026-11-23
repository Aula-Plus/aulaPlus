import { api } from "@/lib/api"
import type { MaterialDetail, MaterialOptions, MaterialProgramItem, MaterialRow, MaterialType } from "@/types"

/**
 * Data layer for screen 19 «Materiales» (ClickUp 1dfd3mc4bgx). Every listing
 * needs the subject × grade prefilter (P19-DEC-02); `gradeId` null is the
 * «Sin grado» folder.
 */

export type MaterialsTab = "generated" | "catalog"

export async function fetchMaterialOptions(): Promise<MaterialOptions> {
  const { data } = await api.get<{ data: MaterialOptions }>("/api/v1/materials/options")
  return data.data
}

export interface MaterialsPage {
  data: MaterialRow[]
  meta: { current_page: number; last_page: number; total: number }
}

/** One page (50 rows) of a tab; «Ver más» asks for the next one. */
export async function fetchMaterials(
  tab: MaterialsTab,
  subjectId: number,
  gradeId: number | null,
  page = 1,
): Promise<MaterialsPage> {
  const { data } = await api.get<MaterialsPage>("/api/v1/materials", {
    params: { tab, subject_id: subjectId, grade_id: gradeId ?? "none", page },
  })
  return data
}

export async function fetchProgramItems(groupId: number, subjectId: number): Promise<MaterialProgramItem[]> {
  const { data } = await api.get<{ data: MaterialProgramItem[] }>("/api/v1/materials/curricular-items", {
    params: { group_id: groupId, subject_id: subjectId },
  })
  return data.data
}

export interface MaterialUpload {
  file: File
  groupId: number
  subjectId: number
  title: string
  type: MaterialType
  tags: string[]
  curricularItemIds: number[]
  publish: boolean
  confirmNoStudentData: boolean
}

export async function uploadMaterial(input: MaterialUpload): Promise<MaterialRow> {
  const form = new FormData()
  form.append("file", input.file)
  form.append("group_id", String(input.groupId))
  form.append("subject_id", String(input.subjectId))
  form.append("title", input.title)
  form.append("type", input.type)
  input.tags.forEach((tag) => form.append("tags[]", tag))
  input.curricularItemIds.forEach((id) => form.append("curricular_item_ids[]", String(id)))
  form.append("publish", input.publish ? "1" : "0")
  if (input.publish) form.append("confirm_no_student_data", input.confirmNoStudentData ? "1" : "0")
  const { data } = await api.post<{ data: MaterialRow }>("/api/v1/materials", form)
  return data.data
}

export async function fetchMaterial(id: number): Promise<MaterialDetail> {
  const { data } = await api.get<{ data: MaterialDetail }>(`/api/v1/materials/${id}`)
  return data.data
}

/** A short-lived URL to open an uploaded file (never stored). */
export async function fetchMaterialUrl(id: number): Promise<string> {
  const { data } = await api.get<{ data: { url: string } }>(`/api/v1/materials/${id}/download`)
  return data.data.url
}

export async function publishMaterial(id: number): Promise<MaterialRow> {
  const { data } = await api.post<{ data: MaterialRow }>(`/api/v1/materials/${id}/publish`, {
    confirm_no_student_data: true,
  })
  return data.data
}

export async function publishAssessment(assessmentId: number): Promise<MaterialRow> {
  const { data } = await api.post<{ data: MaterialRow }>(`/api/v1/assessments/${assessmentId}/material`)
  return data.data
}

/** «Sacar del catálogo», with the optional reason the owner's notice carries. */
export async function removeFromCatalog(id: number, reason: string | null = null): Promise<MaterialRow> {
  const { data } = await api.delete<{ data: MaterialRow }>(`/api/v1/materials/${id}/catalog`, { data: { reason } })
  return data.data
}

export async function deleteMaterial(id: number): Promise<void> {
  await api.delete(`/api/v1/materials/${id}`)
}
