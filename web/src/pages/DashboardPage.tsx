import { useAuth } from "@/features/auth/AuthContext"
import { roleLabels } from "@/types"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"

export function DashboardPage() {
  const { user } = useAuth()

  if (!user) return null

  return (
    <div className="grid gap-6">
      <PageHeader
        title={`Hola, ${user.name}`}
        description={user.school?.name ?? "Sin escuela asignada"}
      />

      <SectionCard title="Tu cuenta">
        <div className="grid gap-2 text-sm">
          <Row label="Email" value={user.email} />
          <Row label="Escuela" value={user.school?.name ?? "—"} />
          <Row
            label="Roles"
            value={user.roles.map((role) => roleLabels[role]).join(", ") || "—"}
          />
        </div>
      </SectionCard>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between border-b py-1 last:border-b-0">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  )
}
