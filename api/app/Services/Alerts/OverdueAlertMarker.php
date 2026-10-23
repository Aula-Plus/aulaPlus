<?php

namespace App\Services\Alerts;

use App\Contracts\StatusChangeNotifier;
use App\Enums\AlertActionKind;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\User;
use App\Notifications\AlertOverdueNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «Llegó a su fecha sin salida» (ClickUp 86e3jpzdp, product review of 14/10).
 *
 * When the deadline of a way out — any of the three: «me ocupo yo», «se la
 * paso a otro rol», «la dejo en observación» — passed and the alert is still
 * pending, the SAME alert is marked (`alerts.overdue_at`) and a step is added
 * to its thread. No second alert is created and nothing else changes: the
 * person who has it, the deadline, the movements and the notes stay as they
 * are. It is a pending item of the circuit, not a worsening of the student.
 *
 * Who is told: whoever has the alert now (`assignee`), only while they still
 * see it (active, and still with access to the student per
 * {@see Alert::scopeVisibleTo()}). Whoever handed it off keeps read access but
 * gets no new notices; Direction is never added because of a deadline (it is
 * there only if the school's settings put it there from the start, or if the
 * alert was explicitly handed to someone in Direction).
 *
 * Each deadline is marked at most once: choosing a new way out sets a new
 * deadline and clears the mark. The caller must have set the tenant
 * (Tenancy::forSchool).
 */
class OverdueAlertMarker
{
    public function __construct(protected StatusChangeNotifier $notifier) {}

    public function mark(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::parse($today ?? now())->startOfDay()->toDateString();

        $ids = $this->pending(Alert::query(), $today)->orderBy('id')->pluck('id');
        $marked = 0;

        foreach ($ids as $id) {
            $alert = DB::transaction(function () use ($id, $today): ?Alert {
                // Lock the row and re-check under the lock: someone may have
                // just chosen a new way out or closed it.
                $alert = $this->pending(Alert::query()->whereKey($id), $today)->lockForUpdate()->first();
                if ($alert === null) {
                    return null;
                }

                $alert->update(['overdue_at' => now()]);
                $alert->actions()->create([
                    'actor_id' => null,
                    'kind' => AlertActionKind::Overdue,
                    'assignee_id' => $alert->assignee_id,
                    'due_on' => $alert->due_on,
                ]);

                return $alert;
            });

            if ($alert === null) {
                continue;
            }

            $marked++;
            $this->notifyHolder($alert);
        }

        return $marked;
    }

    /**
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    protected function pending(Builder $query, string $today): Builder
    {
        return $query
            ->whereNotNull('outcome')
            ->where('resolved', false)
            ->whereNull('overdue_at')
            ->where('type', '!=', AlertType::EscalatedOverdue->value)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', $today);
    }

    protected function notifyHolder(Alert $alert): void
    {
        $holder = $alert->assignee_id !== null ? User::query()->find($alert->assignee_id) : null;

        if ($holder === null || $holder->disabled_at !== null || ! $alert->isVisibleTo($holder)) {
            return;
        }

        $holder->notify(new AlertOverdueNotification($alert));

        // Ids only — never the description or the student's name (CLAUDE.md
        // security rules 2 and 11).
        $this->notifier->notify('alert.overdue', [
            'alert_id' => $alert->id,
            'student_id' => $alert->student_id,
            'assignee_id' => $holder->id,
        ]);
    }
}
