import { render, screen } from "@testing-library/react"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { StudentTrackingPage } from "./StudentTrackingPage"
import * as trackingApi from "./trackingApi"
import type { StudentTracking } from "@/types"

function baseTracking(): StudentTracking {
  return {
    student: {
      id: 3,
      full_name: "Juan Pérez",
      photo_url: null,
      birth_date: "2015-03-01",
      enrollment_year: 2024,
      has_therapeutic_companion: true,
      groups: [{ id: 1, name: "3° A", school_year: 2026 }],
    },
    recent_assessments: [
      { id: 10, group_id: 1, type: "written", variant_number: 2, created_at: "2026-08-01T10:00:00+00:00" },
    ],
    accommodations_count: 1,
    barriers_count: 1,
    recent_comments: [],
    open_alerts_count: 1,
  }
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={["/alumnos/3/seguimiento"]}>
      <Routes>
        <Route path="/alumnos/:id/seguimiento" element={<StudentTrackingPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe("StudentTrackingPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("shows the clinical detail (accommodations, barriers, alerts) for a viewer with access", async () => {
    const tracking = baseTracking()
    tracking.accommodations = [
      {
        id: 1,
        student_id: 3,
        type: "Tiempo extra",
        active: true,
        description: "30 minutos adicionales en evaluaciones",
        focus_area: "Evaluación",
        requires_external_approval: false,
        approved: true,
        is_effective: true,
        created_by_id: 9,
        created_at: "2026-07-01T10:00:00+00:00",
        updated_at: "2026-07-01T10:00:00+00:00",
      },
    ]
    tracking.barriers = [
      { id: 2, description: "Dificultad de atención sostenida", coping_strategy: "Pausas activas", active: true },
    ]
    tracking.alerts = [
      {
        id: 5,
        student_id: 3,
        type: "behavior",
        severity: "medium",
        description: "Acumuló observaciones preocupantes.",
        resolved: false,
        resolved_by_id: null,
        resolved_at: null,
        created_at: "2026-08-10T10:00:00+00:00",
      },
    ]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage()

    expect(await screen.findByText("Seguimiento — Juan Pérez")).toBeInTheDocument()
    expect(screen.getByText("Tiempo extra")).toBeInTheDocument()
    expect(screen.getByText("30 minutos adicionales en evaluaciones")).toBeInTheDocument()
    expect(screen.getByText("Dificultad de atención sostenida")).toBeInTheDocument()
    expect(screen.getByText("Acumuló observaciones preocupantes.")).toBeInTheDocument()
    expect(screen.getByText("Media")).toBeInTheDocument()
    // No "Resolver" button in Session 8 (deferred to Session 9).
    expect(screen.queryByRole("button", { name: /resolver/i })).not.toBeInTheDocument()
  })

  it("shows counts only (no clinical detail) when those fields are absent", async () => {
    // A viewer without clinical access gets a payload with the arrays omitted.
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage()

    expect(await screen.findByText("Seguimiento — Juan Pérez")).toBeInTheDocument()
    expect(screen.getByText(/1 medida\(s\) de apoyo registrada\(s\)/i)).toBeInTheDocument()
    expect(screen.getByText(/1 barrera\(s\) registrada\(s\)/i)).toBeInTheDocument()
    expect(screen.getByText(/1 alerta\(s\) abierta\(s\)/i)).toBeInTheDocument()
  })
})
