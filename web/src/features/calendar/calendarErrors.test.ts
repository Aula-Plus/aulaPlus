import { AxiosError, AxiosHeaders } from "axios"
import { describe, expect, it } from "vitest"
import { calendarErrorMessage } from "./calendarErrors"

function unprocessable(errors: Record<string, string[]>): AxiosError {
  const config = { headers: new AxiosHeaders() }
  return new AxiosError("422", "ERR_BAD_REQUEST", config, null, {
    status: 422,
    statusText: "Unprocessable",
    headers: {},
    config,
    data: { errors },
  })
}

describe("calendarErrorMessage", () => {
  it("explains a rejected audience target instead of a generic failure", () => {
    expect(calendarErrorMessage(unprocessable({ "audience.0.framework_id": ["x"] }), "fallback")).toMatch(
      /a quién le corresponde/,
    )
    expect(calendarErrorMessage(unprocessable({ "audience.1": ["x"] }), "fallback")).toMatch(/a quién le corresponde/)
  })

  it("keeps the fallback for unknown fields and non-422 errors", () => {
    expect(calendarErrorMessage(unprocessable({ other: ["x"] }), "fallback")).toBe("fallback")
    expect(calendarErrorMessage(new Error("network"), "fallback")).toBe("fallback")
  })
})
