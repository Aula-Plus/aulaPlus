import { useCallback, useEffect, useState } from "react"
import { Link, Outlet } from "react-router-dom"
import { isAxiosError } from "axios"
import { UserCog } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageUsers } from "@/lib/permissions"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { PageHeader } from "@/components/ui/page-header"
import { RowLink } from "@/components/ui/row-link"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { roleLabels, userStatusLabels, type ManagedUser } from "@/types"
import type { UserFormOutletContext } from "./UserFormPage"
import * as usersApi from "./usersApi"

const statusTone: Record<ManagedUser["status"], BadgeTone> = {
  active: "success",
  pending: "warning",
  disabled: "neutral",
}

/**
 * Surfaces a server-provided guardrail message (e.g. "no podés desactivar al
 * único director activo") for 422s, falling back to a generic message for
 * anything else — mirrors the pattern in `LoginPage`/`UserFormPage`.
 */
function extractErrorMessage(error: unknown, fallback: string): string {
  if (isAxiosError(error) && error.response?.status === 422) {
    const data = error.response.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined
    const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined
    return data?.message ?? firstFieldError ?? fallback
  }
  return fallback
}

/**
 * Director-only "Usuarios" screen (route `/usuarios`). Lists staff with their
 * lifecycle status and per-row actions. Role gating is UX only; the backend
 * enforces access on every request (mirror of `UserPolicy` via
 * `canManageUsers`, see CLAUDE.md → "Non-negotiable").
 */
export function UsersListPage() {
  const { user } = useAuth()
  const canManage = canManageUsers(user)

  const [users, setUsers] = useState<ManagedUser[] | null>(null)
  const [search, setSearch] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)

  const load = useCallback((term?: string) => {
    return usersApi
      .fetchUsers(term)
      .then((data) => setUsers(data))
      .catch(() => setError("No pudimos cargar los usuarios."))
  }, [])

  useEffect(() => {
    load()
  }, [load])

  async function onToggleDisabled(target: ManagedUser) {
    setBusyId(target.id)
    setError(null)
    try {
      if (target.status === "disabled") {
        await usersApi.enableUser(target.id)
      } else {
        await usersApi.disableUser(target.id)
      }
      await load(search)
    } catch (error) {
      setError(extractErrorMessage(error, "No pudimos actualizar el usuario."))
    } finally {
      setBusyId(null)
    }
  }

  async function onResend(target: ManagedUser) {
    setBusyId(target.id)
    setError(null)
    try {
      await usersApi.resendInvitation(target.id)
    } catch (error) {
      setError(extractErrorMessage(error, "No pudimos reenviar la invitación."))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="grid gap-6">
      <PageHeader title="Usuarios">
        {canManage && (
          <Button asChild>
            <Link to="/usuarios/nueva">Invitar usuario</Link>
          </Button>
        )}
      </PageHeader>

      <form
        onSubmit={(event) => {
          event.preventDefault()
          load(search)
        }}
        className="flex max-w-sm gap-2"
      >
        <Input
          placeholder="Buscar por nombre o email"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          aria-label="Buscar usuarios por nombre o email"
        />
        <Button type="submit" variant="secondary">
          Buscar
        </Button>
      </form>

      {error && <p className="text-sm text-destructive">{error}</p>}

      {!users && !error && <p className="text-muted-foreground">Cargando…</p>}

      {users && users.length === 0 && (
        <EmptyState icon={UserCog} message="Todavía no hay usuarios." />
      )}

      {users && users.length > 0 && (
        <Card className="overflow-hidden py-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Nombre</TableHead>
                <TableHead>Email</TableHead>
                <TableHead>Rol</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="pr-6 text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {users.map((staff) => {
                const isSelf = staff.id === user?.id

                return (
                  <TableRow key={staff.id}>
                    <TableCell className="pl-6 font-medium">{staff.name}</TableCell>
                    <TableCell>{staff.email}</TableCell>
                    <TableCell>{staff.roles.map((role) => roleLabels[role]).join(", ")}</TableCell>
                    <TableCell>
                      <Badge tone={statusTone[staff.status]}>{userStatusLabels[staff.status]}</Badge>
                    </TableCell>
                    <TableCell className="pr-6 text-right">
                      {canManage && (
                        <div className="flex items-center justify-end gap-2">
                          {staff.status === "pending" && (
                            <Button
                              variant="ghost"
                              size="sm"
                              disabled={busyId === staff.id}
                              onClick={() => onResend(staff)}
                            >
                              Reenviar invitación
                            </Button>
                          )}
                          {!isSelf && (
                            <Button
                              variant="ghost"
                              size="sm"
                              disabled={busyId === staff.id}
                              onClick={() => onToggleDisabled(staff)}
                            >
                              {staff.status === "disabled" ? "Reactivar" : "Desactivar"}
                            </Button>
                          )}
                          <RowLink to={`/usuarios/${staff.id}`}>Editar</RowLink>
                        </div>
                      )}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </Card>
      )}

      {/* Nested create/edit form route (Task 14) renders here. */}
      <Outlet context={{ onSaved: () => load(search) } satisfies UserFormOutletContext} />
    </div>
  )
}
