import type { ReactNode } from "react"
import { ProtectedRoute } from "@/components/ProtectedRoute"
import { AppLayout } from "@/components/AppLayout"

export function ProtectedLayout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute>
      <AppLayout>{children}</AppLayout>
    </ProtectedRoute>
  )
}
