import { afterEach, describe, expect, it, vi } from "vitest"
import { api } from "@/lib/api"
import * as trackingApi from "./trackingApi"

describe("trackingApi", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("fetchStudentTracking GETs the tracking endpoint and unwraps { data }", async () => {
    const get = vi.spyOn(api, "get").mockResolvedValue({ data: { data: { open_alerts_count: 2 } } })

    const result = await trackingApi.fetchStudentTracking(7)

    expect(get).toHaveBeenCalledWith("/api/v1/students/7/tracking")
    expect(result).toEqual({ open_alerts_count: 2 })
  })

  it("fetchGroupTracking GETs the group tracking endpoint", async () => {
    const get = vi.spyOn(api, "get").mockResolvedValue({ data: { data: {} } })
    await trackingApi.fetchGroupTracking(3)
    expect(get).toHaveBeenCalledWith("/api/v1/groups/3/tracking")
  })

  it("fetchStudentComments and fetchGroupComments GET their comment endpoints", async () => {
    const get = vi.spyOn(api, "get").mockResolvedValue({ data: { data: [] } })
    await trackingApi.fetchStudentComments(7)
    await trackingApi.fetchGroupComments(3)
    expect(get).toHaveBeenCalledWith("/api/v1/students/7/comments")
    expect(get).toHaveBeenCalledWith("/api/v1/groups/3/comments")
  })

  it("postStudentComment POSTs the body to the student comments endpoint", async () => {
    const post = vi.spyOn(api, "post").mockResolvedValue({ data: { data: { id: 1 } } })

    const result = await trackingApi.postStudentComment(7, { content: "hola", tone: null })

    expect(post).toHaveBeenCalledWith("/api/v1/students/7/comments", { content: "hola", tone: null })
    expect(result).toEqual({ id: 1 })
  })

  it("postGroupComment POSTs the body to the group comments endpoint", async () => {
    const post = vi.spyOn(api, "post").mockResolvedValue({ data: { data: { id: 2 } } })
    await trackingApi.postGroupComment(3, { content: "hey" })
    expect(post).toHaveBeenCalledWith("/api/v1/groups/3/comments", { content: "hey" })
  })

  it("fetchStudentAlerts and fetchGroupAlerts GET their alert endpoints", async () => {
    const get = vi.spyOn(api, "get").mockResolvedValue({ data: { data: [] } })
    await trackingApi.fetchStudentAlerts(7)
    await trackingApi.fetchGroupAlerts(3)
    expect(get).toHaveBeenCalledWith("/api/v1/students/7/alerts")
    expect(get).toHaveBeenCalledWith("/api/v1/groups/3/alerts")
  })

  it("resolveAlert POSTs the resolve endpoint", async () => {
    const post = vi.spyOn(api, "post").mockResolvedValue({ data: { data: { id: 5, resolved: true } } })
    const result = await trackingApi.resolveAlert(5)
    expect(post).toHaveBeenCalledWith("/api/v1/alerts/5/resolve")
    expect(result).toEqual({ id: 5, resolved: true })
  })
})
