import { renderHook } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { api } from "@/lib/api"
import { useCreationJourney } from "./creationJourney"

describe("useCreationJourney", () => {
  beforeEach(() => {
    vi.spyOn(api, "post").mockResolvedValue({ data: null })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("starts a new journey when the form switches to another group", () => {
    const post = vi.mocked(api.post)
    const { result, rerender } = renderHook(
      ({ groupId }) => useCreationJourney("assessment", groupId),
      { initialProps: { groupId: 1 } },
    )

    result.current.formOpened()
    const first = result.current.journeyId()

    rerender({ groupId: 2 })
    result.current.formOpened()

    expect(result.current.journeyId()).not.toBe(first)
    expect(post.mock.calls.map(([, body]) => body)).toEqual([
      expect.objectContaining({ journey_id: first, step: "form_opened", group_id: 1 }),
      expect.objectContaining({ step: "form_opened", group_id: 2 }),
    ])
  })
})
