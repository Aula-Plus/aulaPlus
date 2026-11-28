import { isAxiosError } from "axios"

import { api } from "@/lib/api"
import type { ObjectiveCoverage } from "@/features/annual-plans/annualPlansApi"

/**
 * Data layer for screens 14 «Nueva clase» and 15 «Propuesta de clase»
 * (ClickUp 86e3jujh0). The teacher asks the AI for a class of a group and
 * subject they teach (from a unit of their annual plan, or directly), decides
 * on each section of the proposal, and confirms it as a real class — which
 * is then closed in one tap. Who may do what is decided by the server
 * (AIProposalPolicy, ClassSessionPolicy); `can_decide` / `can_close` only
 * mirror it.
 */

export type ClassSectionKey = "opening" | "central_activity" | "closing" | "materials" | "assessment"

export const classSectionKeys: ClassSectionKey[] = [
  "opening",
  "central_activity",
  "closing",
  "materials",
  "assessment",
]

export const classSectionLabels: Record<ClassSectionKey, string> = {
  opening: "Apertura",
  central_activity: "Actividad central",
  closing: "Cierre",
  materials: "Materiales",
  assessment: "Evaluación de la clase",
}

export interface ProposedSection {
  content: string
  duration_minutes?: number
}

export interface DataUsed {
  data: string
  source: string | null
  reason: string | null
}

export interface PlannerNote {
  read: boolean
  reason: "class_without_unit" | string | null
}

/** The class draft as the server stores it (GenerateAIProposalJob). */
export interface ClassDraft {
  title: string
  duration_minutes: number | null
  sections: Record<ClassSectionKey, ProposedSection>
  rationale: string
  data_used: DataUsed[]
  planner: PlannerNote | null
  regenerated_sections: ClassSectionKey[]
}

export type ProposalStatus = "pending" | "completed" | "error" | "discarded" | "applied"

interface NamedRef {
  id: number
  name: string
}

export interface ClassRequestSummary {
  group: NamedRef | null
  subject: NamedRef | null
  unit: (NamedRef & { position: number; annual_plan_id: number }) | null
  unit_objectives: { id: number; description: string }[]
}

export interface ClassRequestParameters {
  unit_id?: number
  subject_id?: number
  focus: string
  objectives?: string
  unit_objective_ids?: number[]
  date?: string
  duration_minutes?: number
  requirements?: string
  reference_proposal_id?: number
  regenerate_sections?: ClassSectionKey[]
  change_requests?: Partial<Record<ClassSectionKey, string>>
}

export interface ClassProposal {
  id: number
  group_id: number
  journey_id: string | null
  type: string
  status: ProposalStatus
  input_parameters: ClassRequestParameters
  raw_response: ClassDraft | null
  error_message: string | null
  applied_to_id: number | null
  class_request: ClassRequestSummary | null
  can_decide: boolean
}

/** Path of screen 14, optionally from a unit (and one of its objectives). */
export function newClassPath(planId?: number, unitId?: number, objectiveId?: number): string {
  const params = new URLSearchParams()
  if (planId) params.set("programa", String(planId))
  if (unitId) params.set("unidad", String(unitId))
  if (objectiveId) params.set("objetivo", String(objectiveId))
  const query = params.toString()
  return query ? `/nueva-clase?${query}` : "/nueva-clase"
}

export function proposalPath(proposalId: number): string {
  return `/propuesta-de-clase/${proposalId}`
}

export function classPath(classId: number): string {
  return `/clase/${classId}`
}

export function newJourneyId(): string {
  return globalThis.crypto.randomUUID()
}

export async function requestClassProposal(
  groupId: number,
  parameters: ClassRequestParameters,
  journeyId: string | null,
): Promise<ClassProposal> {
  const { data } = await api.post<{ data: ClassProposal }>(`/api/v1/groups/${groupId}/assistant/generate`, {
    type: "class_session",
    parameters,
    ...(journeyId ? { journey_id: journeyId } : {}),
  })
  return data.data
}

export async function fetchProposal(id: number): Promise<ClassProposal> {
  const { data } = await api.get<{ data: ClassProposal }>(`/api/v1/ai-proposals/${id}`)
  return data.data
}

export type SectionDecision =
  | { decision: "approved" }
  | { decision: "rejected" }
  | { decision: "edited"; content: string; duration_minutes?: number }

export async function confirmClassProposal(
  id: number,
  sections: Record<ClassSectionKey, SectionDecision>,
): Promise<ClassProposal> {
  const { data } = await api.post<{ data: ClassProposal }>(`/api/v1/ai-proposals/${id}/apply`, { sections })
  return data.data
}

export async function discardProposal(id: number): Promise<void> {
  await api.post(`/api/v1/ai-proposals/${id}/discard`)
}

export interface MyClasses {
  drafts: {
    id: number
    title: string | null
    group: NamedRef | null
    subject: NamedRef | null
    date: string | null
    status: ProposalStatus
  }[]
  classes: {
    id: number
    title: string
    group: NamedRef | null
    subject: NamedRef | null
    date: string
    status: "planned" | "delivered" | "cancelled"
    closing_result: ObjectiveCoverage | null
  }[]
}

export async function fetchMyClasses(): Promise<MyClasses> {
  const { data } = await api.get<{ data: MyClasses }>("/api/v1/my-classes")
  return data.data
}

export interface ConfirmedSection {
  key: ClassSectionKey
  content: string
  duration_minutes?: number
  edited?: boolean
}

export interface ClassDetail {
  id: number
  title: string
  date: string
  start_time: string | null
  room: string | null
  duration_minutes: number
  status: "planned" | "delivered" | "cancelled"
  group: NamedRef | null
  subject: NamedRef | null
  unit: (NamedRef & { position: number; annual_plan_id: number }) | null
  objective: string | null
  description: string | null
  sections: ConfirmedSection[]
  objectives: { id: number; description: string; coverage: ObjectiveCoverage | null }[]
  closing_result: ObjectiveCoverage | null
  closed_at: string | null
  can_close: boolean
  closable_now: boolean
  proposal: { id: number; rationale: string | null; data_used: DataUsed[]; planner: PlannerNote | null } | null
}

export async function fetchClass(id: number): Promise<ClassDetail> {
  const { data } = await api.get<{ data: ClassDetail }>(`/api/v1/class-sessions/${id}`)
  return data.data
}

/**
 * With «Una parte»: the objectives that «Se dio» and the ones that «Se
 * empezó». Any other objective of the class stays planned, with no record.
 */
export interface PartialClosing {
  given: number[]
  started: number[]
}

export async function closeClass(
  id: number,
  result: ObjectiveCoverage,
  partial: PartialClosing = { given: [], started: [] },
): Promise<ClassDetail> {
  const { data } = await api.post<{ data: ClassDetail }>(`/api/v1/class-sessions/${id}/close`, {
    result,
    ...(result === "partial"
      ? { given_objective_ids: partial.given, started_objective_ids: partial.started }
      : {}),
  })
  return data.data
}

/** «¿Se dio lo que planificaste?» answers (P15-PRO-05). */
export const closingLabels: Record<ObjectiveCoverage, string> = {
  full: "Todo",
  partial: "Una parte",
  none: "Nada",
}

/** Whether the proposal read the unit planner, and why not (Q3 of ClickUp 86e3jujh0). */
export function plannerNote(planner: PlannerNote | null | undefined): string | null {
  if (!planner) return null
  if (planner.read) return "La IA leyó el planificador de la unidad: los campos completos y los recursos tildados."
  return planner.reason === "class_without_unit"
    ? "Clase libre: no cuelga de una unidad, así que la IA no leyó ningún planificador y esta clase no suma a la cobertura del programa."
    : "La IA no leyó el planificador de la unidad: todavía no está disponible. La propuesta se armó con el programa, los objetivos y los datos del grupo."
}

/** Spanish message for a failed generation request. */
export function requestErrorMessage(err: unknown): string {
  if (isAxiosError(err)) {
    switch (err.response?.status) {
      case 403:
        return "No podés pedir una clase para este grupo y materia."
      case 422:
        return "Revisá los datos: la materia es obligatoria y tiene que ser una que dictás en el grupo."
      case 429:
        return "El colegio alcanzó el límite de pedidos a la IA por ahora. Probá de nuevo en un rato."
      case 503:
        return "La IA no está disponible en este momento."
    }
  }
  return "No pudimos pedir la propuesta. Probá de nuevo."
}
