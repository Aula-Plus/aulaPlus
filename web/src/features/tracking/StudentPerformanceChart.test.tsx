import React from "react"
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { PerformanceMark, StudentPerformanceTimeline } from "@/types"
import { performanceMarkTypeLabels } from "@/types"

// Recharts' ResponsiveContainer measures the DOM, which is 0×0 in jsdom, so the
// chart (and its ReferenceDot marks) would never lay out. Replace it with a
// pass-through that injects a fixed size — everything else in recharts stays
// real, so we assert against the actual rendered SVG, not a stub.
vi.mock("recharts", async (importOriginal) => {
  const actual = await importOriginal<typeof import("recharts")>()
  return {
    ...actual,
    ResponsiveContainer: ({
      children,
    }: {
      children: React.ReactElement<{ width?: number; height?: number }>
    }) => React.cloneElement(children, { width: 800, height: 320 }),
  }
})

import { StudentPerformanceChart } from "./StudentPerformanceChart"
import * as performanceApi from "./performanceApi"

function timeline(overrides: Partial<StudentPerformanceTimeline> = {}): StudentPerformanceTimeline {
  return {
    results: [
      { assessment_id: 1, assessment_type: "written", administered_at: "2026-03-10", score: 7 },
      { assessment_id: 2, assessment_type: "oral", administered_at: "2026-05-20", score: 9 },
    ],
    marks: [],
    ...overrides,
  }
}

// Recharts' <Surface> emits its own (empty) <title>; keep only the non-empty
// ones, which are the mark tooltips this component renders.
function titleTexts(container: HTMLElement): string[] {
  return Array.from(container.querySelectorAll("title"))
    .map((t) => t.textContent ?? "")
    .filter((text) => text.length > 0)
}

describe("StudentPerformanceChart", () => {
  beforeEach(() => {
    vi.spyOn(performanceApi, "fetchStudentPerformanceTimeline").mockResolvedValue(timeline())
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("fetches the current school year on mount, then refetches when the range changes", async () => {
    const fetchSpy = performanceApi.fetchStudentPerformanceTimeline as ReturnType<typeof vi.fn>

    render(<StudentPerformanceChart studentId={3} />)

    // Mount fetch: the default window is the whole current school year (2026).
    await waitFor(() =>
      expect(fetchSpy).toHaveBeenCalledWith(3, { from: "2026-01-01", to: "2026-12-31" }),
    )

    fireEvent.change(screen.getByLabelText("Desde"), { target: { value: "2026-03-01" } })
    await waitFor(() =>
      expect(fetchSpy).toHaveBeenLastCalledWith(3, { from: "2026-03-01", to: "2026-12-31" }),
    )

    fireEvent.change(screen.getByLabelText("Hasta"), { target: { value: "2026-06-30" } })
    await waitFor(() =>
      expect(fetchSpy).toHaveBeenLastCalledWith(3, { from: "2026-03-01", to: "2026-06-30" }),
    )
  })

  // One case per PerformanceMarkType (discriminated by `type`, not position):
  // each mark must surface its own label in its hover tooltip.
  const markCases: { mark: PerformanceMark; detail?: string }[] = [
    { mark: { type: "accommodation_activated", date: "2026-03-01T10:00:00+00:00", accommodation_id: 1 } },
    { mark: { type: "accommodation_deactivated", date: "2026-03-02T10:00:00+00:00", accommodation_id: 1 } },
    {
      mark: {
        type: "accommodation_instance_override",
        date: "2026-03-03T10:00:00+00:00",
        accommodation_id: 1,
        assessment_id: 2,
        reason: "Evaluación diagnóstica sin adaptación",
      },
      detail: "Evaluación diagnóstica sin adaptación",
    },
    { mark: { type: "barrier_registered", date: "2026-03-04T10:00:00+00:00", barrier_id: 5 } },
    { mark: { type: "concerning_comment", date: "2026-03-05T10:00:00+00:00", comment_id: 8 } },
    {
      mark: {
        type: "calendar_event",
        date: "2026-03-06T10:00:00+00:00",
        calendar_event_id: 2,
        title: "Reunión de padres",
      },
      detail: "Reunión de padres",
    },
  ]

  it.each(markCases)(
    "renders the $mark.type mark with its label in the tooltip",
    async ({ mark, detail }) => {
      vi.spyOn(performanceApi, "fetchStudentPerformanceTimeline").mockResolvedValue(
        timeline({ marks: [mark] }),
      )

      const { container } = render(<StudentPerformanceChart studentId={3} />)

      const label = performanceMarkTypeLabels[mark.type]
      await waitFor(() => {
        expect(titleTexts(container).some((text) => text.includes(label))).toBe(true)
      })

      if (detail) {
        expect(titleTexts(container).some((text) => text.includes(detail))).toBe(true)
      }
    },
  )

  it("refetches with subjectId when a subject is selected", async () => {
    const fetchSpy = performanceApi.fetchStudentPerformanceTimeline as ReturnType<typeof vi.fn>

    render(
      <StudentPerformanceChart
        studentId={3}
        subjects={[{ id: 1, name: "Matemática" }]}
      />,
    )

    // Mount fetch carries no subject filter ("Todas las materias").
    await waitFor(() =>
      expect(fetchSpy).toHaveBeenCalledWith(3, { from: "2026-01-01", to: "2026-12-31" }),
    )

    fireEvent.click(screen.getByLabelText("Materia"))
    fireEvent.click(await screen.findByRole("button", { name: "Matemática" }))

    await waitFor(() =>
      expect(fetchSpy).toHaveBeenLastCalledWith(
        3,
        expect.objectContaining({ subjectId: 1 }),
      ),
    )
  })

  it("shows a message instead of a broken chart when there are no results", async () => {
    vi.spyOn(performanceApi, "fetchStudentPerformanceTimeline").mockResolvedValue(
      timeline({
        results: [],
        // Even a lone calendar_event does not force an empty chart — without a
        // score line there is nothing to place it against.
        marks: [{ type: "calendar_event", date: "2026-03-06T10:00:00+00:00", calendar_event_id: 2, title: "x" }],
      }),
    )

    const { container } = render(<StudentPerformanceChart studentId={3} />)

    expect(await screen.findByText("Sin evaluaciones en este rango.")).toBeInTheDocument()
    // No chart is drawn — only the empty message. (The subject selector's own
    // chevron icon is an svg, so we assert specifically that no recharts chart
    // rendered, not the mere absence of any svg.)
    expect(container.querySelector("[class*='recharts']")).toBeNull()
  })

  it("renders exactly the marks the response brings, without filtering any by role", async () => {
    // All six mark types at once — the client must draw one mark per entry and
    // never drop any: which types a viewer may see was already decided by the
    // backend.
    const marks = markCases.map((c) => c.mark)
    vi.spyOn(performanceApi, "fetchStudentPerformanceTimeline").mockResolvedValue(
      timeline({ marks }),
    )

    const { container } = render(<StudentPerformanceChart studentId={3} />)

    await waitFor(() => {
      const texts = titleTexts(container)
      for (const { mark } of markCases) {
        expect(texts.some((text) => text.includes(performanceMarkTypeLabels[mark.type]))).toBe(true)
      }
    })

    // One <title> (one mark) per response entry — no more, no fewer.
    expect(titleTexts(container)).toHaveLength(marks.length)

    // The legend lists every present mark type, too.
    const legend = screen.getByRole("list")
    for (const { mark } of markCases) {
      expect(
        within(legend).getByText(performanceMarkTypeLabels[mark.type]),
      ).toBeInTheDocument()
    }
  })
})
