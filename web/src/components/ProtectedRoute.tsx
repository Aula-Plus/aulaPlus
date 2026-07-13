import type { ReactNode } from "react"
import { Navigate } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"

/**
 * Gates routes behind authentication. This is a UX convenience only — the real
 * security boundary is the server (auth:sanctum + Policies). Never rely on this
 * to protect data.
 */
export function ProtectedRoute({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()

  if (loading) {
    return (
      <div className="flex min-h-svh items-center justify-center text-muted-foreground">
        Cargando…
      </div>
    )
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  return <>{children}</>
}
