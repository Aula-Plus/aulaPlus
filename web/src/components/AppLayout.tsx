import type { ReactNode } from "react"
import { Sidebar } from "@/components/Sidebar"

export function AppLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-svh">
      <Sidebar />
      {/* pt-14 clears the fixed mobile top bar; on desktop the sidebar is in flow. */}
      <main className="flex-1 pt-14 lg:pt-0">
        <div className="mx-auto max-w-5xl p-6">{children}</div>
      </main>
    </div>
  )
}
