import axios from "axios"

/**
 * Shared axios client for the Laravel API.
 *
 * Sanctum SPA auth is cookie-based, so:
 *  - `withCredentials` sends/receives the session + XSRF cookies cross-origin.
 *  - `withXSRFToken` makes axios echo the XSRF-TOKEN cookie back as the
 *    X-XSRF-TOKEN header on every request (required for cross-site requests).
 *
 * Call {@link ensureCsrfCookie} before the first state-changing request so the
 * XSRF-TOKEN cookie exists.
 */
const baseURL = import.meta.env.VITE_API_URL ?? "http://localhost"

export const api = axios.create({
  baseURL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: "application/json",
  },
})

let csrfPromise: Promise<unknown> | null = null

/**
 * Fetch the CSRF cookie once. Subsequent calls reuse the same in-flight/settled
 * promise so we don't hit the endpoint repeatedly.
 */
export function ensureCsrfCookie(): Promise<unknown> {
  if (!csrfPromise) {
    csrfPromise = api.get("/sanctum/csrf-cookie")
  }
  return csrfPromise
}

/** Reset the cached CSRF state (e.g. after logout). */
export function resetCsrfCookie(): void {
  csrfPromise = null
}
