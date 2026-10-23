import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SingleSelect } from "@/components/ui/single-select"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import type { FollowUpMember, ScheduledFollowUpDetail, StaffMember } from "@/types"
import * as detailApi from "./followUpDetailApi"
import { personLabel, type RunAction } from "./followUpDetailUtils"

interface FollowUpPeopleSectionProps {
  detail: ScheduledFollowUpDetail
  candidates: StaffMember[]
  run: RunAction
}

/**
 * People on a follow-up inside FollowUpDetailDialog (ClickUp 86e3qkwna):
 * general responsibles, participants and who left (with the reason), plus
 * adding/removing people and withdrawing. Buttons follow the server's `can`
 * flags; the backend re-checks every action.
 */
export function FollowUpPeopleSection({ detail, candidates, run }: FollowUpPeopleSectionProps) {
  const me = detail.viewer.user_id
  const active = detail.members.filter((member) => member.active)
  const generals = active.filter((member) => member.role === "general_responsible")
  const participants = active.filter((member) => member.role === "participant")
  const left = detail.members.filter((member) => !member.active)
  const activeIds = new Set(active.map((member) => member.user?.id))
  const generalIds = new Set(generals.map((member) => member.user?.id))

  return (
    <section className="grid gap-3" aria-labelledby="follow-up-people-heading">
      <h3 id="follow-up-people-heading" className="font-semibold">
        Personas
      </h3>

      <div className="grid gap-1 text-sm">
        <p className="text-xs font-medium">Responsables generales</p>
        <ul className="grid gap-1">
          {generals.map((member) => (
            <li key={member.user?.id} className="flex items-center justify-between gap-2">
              <span>{personLabel(member.user)}</span>
              {detail.can.manage_general_responsibles && generals.length > 1 && member.user && (
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() => run(() => detailApi.removeGeneralResponsible(detail.id, member.user!.id))}
                >
                  Quitar como responsable general
                </Button>
              )}
            </li>
          ))}
        </ul>
      </div>

      <div className="grid gap-1 text-sm">
        <p className="text-xs font-medium">Participantes</p>
        {participants.length === 0 ? (
          <p className="text-xs text-muted-foreground">Nadie más.</p>
        ) : (
          <ul className="grid gap-1">
            {participants.map((member) => (
              <ParticipantRow key={member.user?.id} member={member} detail={detail} me={me} run={run} />
            ))}
          </ul>
        )}
      </div>

      {left.length > 0 && (
        <div className="grid gap-1 text-xs text-muted-foreground">
          <p className="font-medium">Ya no participan</p>
          <ul className="grid gap-1">
            {left.map((member) => (
              <li key={member.user?.id}>
                {personLabel(member.user)} —{" "}
                {member.withdrawn_at
                  ? `se retiró el ${formatShortDate(member.withdrawn_at)}: ${member.withdrawal_reason ?? ""}`
                  : `quitado el ${formatShortDate(member.removed_at)}${member.removal_reason ? `: ${member.removal_reason}` : ""}`}
              </li>
            ))}
          </ul>
        </div>
      )}

      {detail.can.add_participant && (
        <AddPersonForm
          id="follow-up-add-participant"
          label="Agregar participante"
          options={candidates.filter((person) => !activeIds.has(person.id))}
          onAdd={(userId) => run(() => detailApi.addParticipant(detail.id, userId))}
        />
      )}
      {detail.can.manage_general_responsibles && (
        <AddPersonForm
          id="follow-up-add-general"
          label="Agregar responsable general"
          options={candidates.filter((person) => !generalIds.has(person.id))}
          onAdd={(userId) => run(() => detailApi.addGeneralResponsible(detail.id, userId))}
        />
      )}

      {detail.can.withdraw && (
        <WithdrawForm detail={detail} candidates={candidates.filter((person) => person.id !== me)} run={run} />
      )}
      {detail.viewer.is_member && detail.viewer.active && !detail.can.withdraw && (
        <p className="text-xs text-muted-foreground">
          Para retirarte primero cerrá o reasigná tus etapas pendientes.
        </p>
      )}
    </section>
  )
}

function ParticipantRow({
  member,
  detail,
  me,
  run,
}: {
  member: FollowUpMember
  detail: ScheduledFollowUpDetail
  me: number
  run: RunAction
}) {
  const [removing, setRemoving] = useState(false)
  const [reason, setReason] = useState("")
  const canRemove = detail.can.add_participant && member.user && member.user.id !== me && !member.has_pending_stage
  const userId = member.user?.id ?? 0

  return (
    <li className="grid gap-1">
      <div className="flex items-center justify-between gap-2">
        <span>{personLabel(member.user)}</span>
        {canRemove && !removing && (
          <Button type="button" size="sm" variant="ghost" onClick={() => setRemoving(true)}>
            Quitar
          </Button>
        )}
      </div>
      {removing && (
        <div className="flex flex-wrap items-end gap-2">
          <div className="grid gap-1">
            <Label htmlFor={`remove-reason-${userId}`} className="text-xs">
              Motivo (opcional)
            </Label>
            <Input id={`remove-reason-${userId}`} value={reason} onChange={(event) => setReason(event.target.value)} />
          </div>
          <Button
            type="button"
            size="sm"
            onClick={() => run(() => detailApi.removeParticipant(detail.id, userId, reason.trim() || undefined))}
          >
            Confirmar
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => setRemoving(false)}>
            Cancelar
          </Button>
        </div>
      )}
    </li>
  )
}

function AddPersonForm({
  id,
  label,
  options,
  onAdd,
}: {
  id: string
  label: string
  options: StaffMember[]
  onAdd: (userId: number) => Promise<boolean>
}) {
  const [selected, setSelected] = useState("")

  return (
    <div className="flex flex-wrap items-end gap-2">
      <div className="grid gap-1.5">
        <Label htmlFor={id} className="text-xs">
          {label}
        </Label>
        <SingleSelect
          id={id}
          options={options.map((person) => ({ value: String(person.id), label: personLabel(person) }))}
          value={selected}
          onChange={setSelected}
          placeholder="Elegí una persona"
          className="w-64"
        />
      </div>
      <Button
        type="button"
        size="sm"
        variant="outline"
        disabled={!selected}
        onClick={async () => {
          if (await onAdd(Number(selected))) setSelected("")
        }}
      >
        Agregar
      </Button>
    </div>
  )
}

function WithdrawForm({
  detail,
  candidates,
  run,
}: {
  detail: ScheduledFollowUpDetail
  candidates: StaffMember[]
  run: RunAction
}) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState("")
  const [successor, setSuccessor] = useState("")
  const needsSuccessor = detail.can.withdraw_requires_successor
  const valid = reason.trim() && (!needsSuccessor || successor)

  if (!open) {
    return (
      <div>
        <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>
          Retirarme del seguimiento
        </Button>
      </div>
    )
  }

  return (
    <div className="grid gap-2 rounded-md border p-3">
      <Label htmlFor="follow-up-withdraw-reason" className="text-xs">
        Motivo del retiro
      </Label>
      <Textarea id="follow-up-withdraw-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
      {needsSuccessor && (
        <div className="grid gap-1.5">
          <Label htmlFor="follow-up-successor" className="text-xs">
            Sos la única persona responsable general: elegí quién te reemplaza
          </Label>
          <SingleSelect
            id="follow-up-successor"
            options={candidates.map((person) => ({ value: String(person.id), label: personLabel(person) }))}
            value={successor}
            onChange={setSuccessor}
            placeholder="Elegí una persona"
            className="w-64"
          />
        </div>
      )}
      <div className="flex gap-2">
        <Button
          type="button"
          size="sm"
          disabled={!valid}
          onClick={() =>
            run(() => detailApi.withdraw(detail.id, reason.trim(), needsSuccessor ? Number(successor) : undefined))
          }
        >
          Confirmar retiro
        </Button>
        <Button type="button" size="sm" variant="outline" onClick={() => setOpen(false)}>
          Cancelar
        </Button>
      </div>
    </div>
  )
}
