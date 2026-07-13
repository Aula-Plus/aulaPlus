import { useAuth } from "@/features/auth/AuthContext"
import { roleLabels } from "@/types"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"

export function DashboardPage() {
  const { user, logout } = useAuth()

  if (!user) return null

  return (
    <div className="mx-auto max-w-3xl p-6">
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Hola, {user.name}</h1>
          <p className="text-muted-foreground">
            {user.school?.name ?? "Sin escuela asignada"}
          </p>
        </div>
        <Button variant="outline" onClick={() => logout()}>
          Cerrar sesión
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Tu cuenta</CardTitle>
          <CardDescription>
            Datos resueltos por el backend (Sanctum + Spatie roles).
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-2 text-sm">
          <Row label="Email" value={user.email} />
          <Row label="Escuela" value={user.school?.name ?? "—"} />
          <Row
            label="Roles"
            value={user.roles.map((role) => roleLabels[role]).join(", ") || "—"}
          />
        </CardContent>
      </Card>
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
