import { api } from "@/lib/api"
import type {
  AIInstructionSettings,
  AIInstructionType,
  AIRequestType,
  JudgmentPreviewGroupOption,
  JudgmentStylePreview,
  SchoolAIInstruction,
} from "@/types"

/**
 * Screen 24 · «Juicios» and «Evaluaciones» (ClickUp 1dfd3mc4bh6): the
 * school's layer of AI instructions. Direction only (the server enforces it).
 */
export async function fetchAIInstructions(): Promise<AIInstructionType[]> {
  const { data } = await api.get<{ data: AIInstructionType[] }>("/api/v1/ai-instructions")
  return data.data
}

export interface NewAIInstructionInput {
  request_type: AIRequestType
  education_level_id: number | null
  school_grade_ids: number[]
  settings?: AIInstructionSettings
}

export async function createAIInstruction(input: NewAIInstructionInput): Promise<SchoolAIInstruction> {
  const { data } = await api.post<{ data: SchoolAIInstruction }>("/api/v1/ai-instructions", input)
  return data.data
}

export async function updateAIInstruction(
  id: number,
  settings: AIInstructionSettings,
): Promise<SchoolAIInstruction> {
  const { data } = await api.put<{ data: SchoolAIInstruction }>(`/api/v1/ai-instructions/${id}`, {
    settings,
  })
  return data.data
}

export async function deleteAIInstruction(id: number): Promise<void> {
  await api.delete(`/api/v1/ai-instructions/${id}`)
}

export async function fetchJudgmentPreviewOptions(): Promise<JudgmentPreviewGroupOption[]> {
  const { data } = await api.get<{ data: JudgmentPreviewGroupOption[] }>(
    "/api/v1/judgment-style-previews/options",
  )
  return data.data
}

export async function requestJudgmentPreview(input: {
  group_id: number
  subject_id: number
}): Promise<JudgmentStylePreview> {
  const { data } = await api.post<{ data: JudgmentStylePreview }>("/api/v1/judgment-style-previews", input)
  return data.data
}

export async function fetchJudgmentPreview(id: number): Promise<JudgmentStylePreview> {
  const { data } = await api.get<{ data: JudgmentStylePreview }>(`/api/v1/judgment-style-previews/${id}`)
  return data.data
}
