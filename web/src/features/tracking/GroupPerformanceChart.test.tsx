import React from "react"
import { render, screen, waitFor } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { GroupPerformanceMark, GroupPerformanceTimeline } from "@/types"
import { groupPerformanceMarkTypeLabels } from "@/types"

// Recharts' ResponsiveContainer measures the DOM, which is 0×0 in jsdom, so the
// chart (and its ReferenceDot marks) would never lay out. Replace it with a
// pass-through that injects a fixed size — everything else in recharts stays
// real, so we assert against the actual rendered SVG.
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

import { GroupPerformanceChart, ResultTooltip, type ResultDatum } from "./GroupPerformanceChart"
import * as performanceApi from "./performanceApi"

function point(overrides: Partial<ResultDatum> = {}): ResultDatum {
  return {
    x: new Date("2026-03-10").getTime(),
    average_score: 7.4,
    results_count: 22,
    administered_at: "2026-03-10",
    assessment_type: "written",
    ...overrides,
  }
}

function timeline(overrides: Partial<GroupPerformanceTimeline> = {}): GroupPerformanceTimeline {
  return {
    results: [
      {
        assessment_id: 9,
        assessment_type: "written",
        administered_at: "2026-03-10",
        average_score: 7.4,
        results_count: 22,
      },
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

// Every GroupPerformanceMark variant (five, NOT six — there is no
// accommodation_instance_override at group level, spec §2 note).
const allMarks: GroupPerformanceMark[] = [
  { type: "accommodation_activated", date: "2026-03-01", accommodation_type: "tiempo extra", count: 2 },
  { type: "accommodation_deactivated", date: "2026-03-02", accommodation_type: "tiempo extra", count: 1 },
  { type: "barrier_registered", date: "2026-03-03", count: 1 },
  { type: "concerning_comment", date: "2026-03-04", count: 3 },
  { type: "calendar_event", date: "2026-03-05", calendar_event_id: 4, title: "Reunión de padres" },
]

describe("GroupPerformanceChart", () => {
  beforeEach(() => {
    vi.spyOn(performanceApi, "fetchGroupPerformanceTimeline").mockResolvedValue(timeline())
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("fetches the current school year on mount", async () => {
    const fetchSpy = performanceApi.fetchGroupPerformanceTimeline as ReturnType<typeof vi.fn>
    render(<GroupPerformanceChart groupId={1} studentCount={25} />)
    await waitFor(() =>
      expect(fetchSpy).toHaveBeenCalledWith(1, { from: "2026-01-01", to: "2026-12-31" }),
    )
  })

  // Spec §7: the point tooltip surfaces results_count. Recharts renders the
  // hover tooltip content only when active (never in jsdom's 0×0 layout), so we
  // render its content component directly with an active payload — the same
  // component the chart wires into <Tooltip>. With a known group size it reads
  // "7.4 (22 de 25 alumnos)".
  it("shows average_score and results_count in a point's tooltip", () => {
    render(<ResultTooltip active payload={[{ payload: point() }]} studentCount={25} />)
    expect(screen.getByText("7.4 (22 de 25 alumnos)")).toBeInTheDocument()
    expect(screen.getByText("Escrita")).toBeInTheDocument()
  })

  it("falls back to the numerator alone when the group size is unknown", () => {
    render(<ResultTooltip active payload={[{ payload: point() }]} />)
    expect(screen.getByText("7.4 (22 alumnos)")).toBeInTheDocument()
  })

  it("renders nothing when the tooltip is not active", () => {
    const { container } = render(<ResultTooltip active={false} payload={[{ payload: point() }]} />)
    expect(container).toBeEmptyDOMElement()
  })

  // Spec §7: group marks never expose a student_id or individual-student data —
  // they are aggregates by (type, date). Walk all five variants and confirm
  // none carries a per-student identifier and each renders its aggregate label.
  it("renders every mark variant as an aggregate, never exposing a student identifier", async () => {
    vi.spyOn(performanceApi, "fetchGroupPerformanceTimeline").mockResolvedValue(
      timeline({ marks: allMarks }),
    )

    const { container } = render(<GroupPerformanceChart groupId={1} studentCount={25} />)

    await waitFor(() => {
      const texts = titleTexts(container)
      for (const mark of allMarks) {
        expect(
          texts.some((text) => text.includes(groupPerformanceMarkTypeLabels[mark.type])),
        ).toBe(true)
      }
    })

    // No mark object carries a student identifier of any shape …
    for (const mark of allMarks) {
      expect("student_id" in mark).toBe(false)
      expect("student_ids" in mark).toBe(false)
      expect("student" in mark).toBe(false)
    }

    // … and none leaks one into its rendered tooltip text.
    for (const text of titleTexts(container)) {
      expect(text).not.toMatch(/alumno\s*#|student[_ ]?id/i)
    }

    // One <title> per mark — no more, no fewer.
    expect(titleTexts(container)).toHaveLength(allMarks.length)
  })

  it("shows a message instead of a broken chart when there are no results", async () => {
    vi.spyOn(performanceApi, "fetchGroupPerformanceTimeline").mockResolvedValue(
      timeline({
        results: [],
        marks: [{ type: "barrier_registered", date: "2026-03-03", count: 1 }],
      }),
    )

    const { container } = render(<GroupPerformanceChart groupId={1} studentCount={25} />)

    expect(await screen.findByText("Sin evaluaciones en este rango.")).toBeInTheDocument()
    expect(container.querySelector("svg")).toBeNull()
  })
})
