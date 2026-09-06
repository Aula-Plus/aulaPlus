import { afterEach, describe, expect, it, vi } from "vitest"
import { api } from "@/lib/api"
import { fetchAdoptionDashboard } from "./adoptionApi"

describe("adoptionApi", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("fetchAdoptionDashboard GETs the per-school endpoint and unwraps { data }", async () => {
    const get = vi.spyOn(api, "get").mockResolvedValue({
      data: { data: { teacher_login_rate_30d: 75 } },
    })

    const result = await fetchAdoptionDashboard(42)

    expect(get).toHaveBeenCalledWith("/api/v1/schools/42/adoption-dashboard")
    expect(result).toEqual({ teacher_login_rate_30d: 75 })
  })
})
