<?php

namespace App\Services\Alerts;

use App\Contracts\StatusChangeNotifier;
use App\Enums\AlertCondition;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AssessmentResult;
use App\Models\Subject;
use App\Support\AlertRouting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generates "sustained low performance" alerts for the current school
 * (ClickUp 86e3jpzcv; documento vivo screen 11) from the conditions the
 * school configured ({@see AlertRule}). Aula+ never decides the condition or
 * the threshold: with no active rule, nothing is generated.
 *
 * Evaluated per (student, subject) over the student's assessment results in
 * chronological order (assessment `administered_at`, falling back to its
 * creation date). One open alert per student and subject at most: while one
 * is unresolved, a second rule met in the same subject adds nothing. Once it
 * is resolved, a new alert is only generated on new evidence — a condition
 * met on a later date than the last alert for that student and subject — so
 * re-running the command never re-raises an alert someone already closed.
 * Soft-deleted students are skipped.
 *
 * The caller must have set the tenant (Tenancy::forSchool) — this runs from a
 * console command with no authenticated user.
 */
class PerformanceAlertGenerator
{
    public function __construct(protected StatusChangeNotifier $notifier) {}

    public function generate(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::parse($today ?? now())->startOfDay();

        $rules = AlertRule::query()->where('active', true)->orderBy('id')->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $series = $this->scoreSeries();
        $recipients = AlertRouting::recipientsFor(AlertType::Performance);
        $subjectNames = Subject::withTrashed()->pluck('name', 'id');

        $open = Alert::query()
            ->where('type', AlertType::Performance->value)
            ->where('resolved', false)
            ->whereNotNull('subject_id')
            ->get(['student_id', 'subject_id'])
            ->mapWithKeys(fn (Alert $alert) => ["{$alert->student_id}:{$alert->subject_id}" => true])
            ->all();

        // Latest date a condition was already reported per (student, subject),
        // resolved or not: the same scores must not raise a second alert.
        $lastMetOn = Alert::query()
            ->where('type', AlertType::Performance->value)
            ->whereNotNull('subject_id')
            ->whereNotNull('condition_met_on')
            ->groupBy('student_id', 'subject_id')
            ->select(['student_id', 'subject_id', DB::raw('MAX(condition_met_on) as last_met_on')])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [
                "{$row->student_id}:{$row->subject_id}" => substr((string) $row->last_met_on, 0, 10),
            ])
            ->all();

        $generated = 0;

        foreach ($rules as $rule) {
            foreach ($series as $studentId => $subjects) {
                foreach ($subjects as $subjectId => $scores) {
                    if ($rule->subject_id !== null && $rule->subject_id !== $subjectId) {
                        continue;
                    }

                    $key = "{$studentId}:{$subjectId}";

                    if (isset($open[$key])) {
                        continue;
                    }

                    $met = $this->evaluate($rule, $scores, $today);

                    if ($met === null || (isset($lastMetOn[$key]) && $met['on'] <= $lastMetOn[$key])) {
                        continue;
                    }

                    $alert = Alert::create([
                        'student_id' => $studentId,
                        'subject_id' => $subjectId,
                        'alert_rule_id' => $rule->id,
                        'type' => AlertType::Performance,
                        'severity' => AlertSeverity::Medium,
                        'description' => sprintf(
                            'Condición configurada por el colegio: %s. Se cumplió en %s — %s.',
                            $rule->describe(),
                            $subjectNames[$subjectId] ?? 'la materia',
                            $met['detail'],
                        ),
                        'condition_met_on' => $met['on'],
                        'recipients' => $recipients,
                        'resolved' => false,
                    ]);

                    // Ids and enum values only — never the description or any
                    // score (CLAUDE.md security rules 2 and 11).
                    $this->notifier->notify('alert.generated', [
                        'alert_id' => $alert->id,
                        'student_id' => $alert->student_id,
                        'subject_id' => $alert->subject_id,
                        'type' => $alert->type->value,
                        'severity' => $alert->severity->value,
                    ]);

                    $open[$key] = true;
                    $generated++;
                }
            }
        }

        return $generated;
    }

    /**
     * @param  list<array{score: float, on: string}>  $scores  chronological
     * @return array{on: string, detail: string}|null
     */
    protected function evaluate(AlertRule $rule, array $scores, CarbonImmutable $today): ?array
    {
        $threshold = (float) $rule->threshold;

        if ($rule->condition === AlertCondition::ConsecutiveBelow) {
            $count = (int) $rule->consecutive_count;

            if ($count < 1 || count($scores) < $count) {
                return null;
            }

            $last = array_slice($scores, -$count);

            foreach ($last as $entry) {
                if ($entry['score'] >= $threshold) {
                    return null;
                }
            }

            return [
                'on' => end($last)['on'],
                'detail' => 'notas: '.$this->joinList(array_map(
                    fn (array $entry) => $this->formatNumber($entry['score']),
                    $last,
                )),
            ];
        }

        $since = $today->subDays((int) $rule->period_days)->toDateString();
        $window = array_values(array_filter($scores, fn (array $entry) => $entry['on'] >= $since));

        if ($window === []) {
            return null;
        }

        $average = array_sum(array_column($window, 'score')) / count($window);

        if ($average >= $threshold) {
            return null;
        }

        return [
            'on' => end($window)['on'],
            'detail' => sprintf(
                'promedio %s en %d %s',
                $this->formatNumber(round($average, 2)),
                count($window),
                count($window) === 1 ? 'nota' : 'notas',
            ),
        ];
    }

    /**
     * Every scored result in the school, grouped by student and subject, in
     * chronological order.
     *
     * @return array<int, array<int, list<array{score: float, on: string}>>>
     */
    protected function scoreSeries(): array
    {
        $takenOn = 'COALESCE(assessments.administered_at, assessments.created_at)';

        $series = [];

        AssessmentResult::query()
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->join('students', 'students.id', '=', 'assessment_results.student_id')
            ->whereNull('students.deleted_at')
            ->whereNotNull('assessments.subject_id')
            ->whereNotNull('assessment_results.score')
            ->orderByRaw($takenOn)
            ->orderBy('assessments.id')
            ->select([
                'assessment_results.student_id',
                'assessments.subject_id',
                'assessment_results.score',
                DB::raw("{$takenOn} as taken_on"),
            ])
            ->toBase()
            ->cursor()
            ->each(function (object $row) use (&$series): void {
                $series[(int) $row->student_id][(int) $row->subject_id][] = [
                    'score' => (float) $row->score,
                    'on' => substr((string) $row->taken_on, 0, 10),
                ];
            });

        return $series;
    }

    /**
     * @param  list<string>  $items
     */
    protected function joinList(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }

    protected function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}
