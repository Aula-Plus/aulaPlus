import { useCallback, useEffect, useMemo, useState, type ReactNode } from "react"
import type { User } from "@/types"
import * as authApi from "./authApi"
import { AuthContext, type AuthContextValue } from "./AuthContext"

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  // On boot, ask the API who we are (via the session cookie, if any).
  useEffect(() => {
    let active = true
    authApi.fetchCurrentUser().then((u) => {
      if (active) {
        setUser(u)
        setLoading(false)
      }
    })
    return () => {
      active = false
    }
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const u = await authApi.login({ email, password })
    setUser(u)
  }, [])

  const logout = useCallback(async () => {
    await authApi.logout()
    setUser(null)
  }, [])

  const value = useMemo<AuthContextValue>(
    () => ({ user, loading, login, logout }),
    [user, loading, login, logout],
  )

  return <AuthContext value={value}>{children}</AuthContext>
}
