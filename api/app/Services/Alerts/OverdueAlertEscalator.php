<?php

namespace App\Services\Alerts;

use App\Contracts\StatusChangeNotifier;
use App\Enums\AlertOutcome;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Support\AlertRouting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * "Alerta escalada con plazo vencido" (ClickUp 86e3jpzdp; documento vivo
 * screen 11): when an alert was handed off and its deadline passed without
 * the receiver choosing a way out, a second alert is born. It reaches the
 * person who handed it off and the person who had to resolve it (plus any
 * role the school added in the alert settings), and it speaks about the
 * circuit — who received what and when — never about the student.
 *
 * Each hand-off escalates at most once (`alerts.escalated_at`); choosing a
 * new way out clears that mark and closes the escalated alert.
 *
 * The caller must have set the tenant (Tenancy::forSchool).
 */
class OverdueAlertEscalator
{
    public function __construct(protected StatusChangeNotifier $notifier) {}

    public function escalate(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::parse($today ?? now())->startOfDay();

        $overdue = Alert::query()
            ->where('outcome', AlertOutcome::HandedOff->value)
            ->where('resolved', false)
            ->whereNull('escalated_at')
            ->whereDate('due_on', '<', $today->toDateString())
            ->with(['assignee:id,name', 'outcomeBy:id,name'])
            ->get();

        $recipients = AlertRouting::recipientsFor(AlertType::EscalatedOverdue);
        $escalated = 0;

        foreach ($overdue as $alert) {
            DB::transaction(function () use ($alert, $recipients, $today): void {
                $escalation = Alert::create([
                    'student_id' => $alert->student_id,
                    'subject_id' => $alert->subject_id,
                    'source_alert_id' => $alert->id,
                    'type' => AlertType::EscalatedOverdue,
                    'severity' => AlertSeverity::High,
                    'description' => sprintf(
                        '%s le pasó el caso a %s el %s con plazo al %s. Todavía no eligió una salida — %d %s de atraso.',
                        $alert->outcomeBy?->name ?? 'Alguien del equipo',
                        $alert->assignee?->name ?? 'otra persona',
                        $alert->outcome_at?->format('d/m') ?? '—',
                        $alert->due_on->format('d/m'),
                        $days = (int) $alert->due_on->diffInDays($today),
                        $days === 1 ? 'día' : 'días',
                    ),
                    'condition_met_on' => $today->toDateString(),
                    'recipients' => $recipients,
                    'resolved' => false,
                ]);

                $escalation->people()->sync(array_values(array_unique(array_filter([
                    $alert->outcome_by_id,
                    $alert->assignee_id,
                ]))));

                $alert->update(['escalated_at' => now()]);

                $this->notifier->notify('alert.escalated', [
                    'alert_id' => $escalation->id,
                    'source_alert_id' => $alert->id,
                    'student_id' => $alert->student_id,
                    'type' => $escalation->type->value,
                ]);
            });

            $escalated++;
        }

        return $escalated;
    }
}
