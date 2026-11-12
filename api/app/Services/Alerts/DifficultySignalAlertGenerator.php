<?php

namespace App\Services\Alerts;

use App\Contracts\StatusChangeNotifier;
use App\Enums\AlertCondition;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\CommentSignal;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Comment;
use App\Models\CommentCategory;
use App\Models\Student;
use App\Support\AlertRouting;
use App\Support\SchoolTime;
use App\Support\StudentLevels;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generates «Señal de dificultad» alerts for the current school (ClickUp
 * 86e3qkwpn) from the conditions the school configured
 * ({@see AlertRule} with `AlertCondition::DifficultySignals`): at least
 * `signal_count` difficulty signals in one same comment category on a
 * student's observations within the last `period_days` days. With no active
 * rule, nothing is generated — Aula+ never proposes the numbers.
 *
 * Counting: every difficulty signal on the student's observations counts,
 * whatever the observation's scope — «Solo Psicopedagogía», «Elegir
 * personas» included — EXCEPT «Solo quien escribe» notes (`author_only`,
 * 21/10 answer): choosing that scope promises the author that nobody else
 * reads it nor adds it to anyone else's count, so those only feed the
 * author's private mark. Counting reserved notes is safe because the alert
 * never exposes its evidence: it carries only
 * the number and the category («3 señales de dificultad en Social») — never
 * any observation text, author or id — and each recipient then opens only the
 * observations they may read (Comment visibility rules, unchanged).
 * Psychopedagogy's «Compartir con» extracts are separate rows (CommentShare)
 * and never count. Group comments never count (they are not about one
 * student). «Señal de mejora», «Sin cambio observado» and legacy rows without
 * a signal never count.
 *
 * Dedupe mirrors {@see PerformanceAlertGenerator}: one open alert per
 * (student, category) at most; once it is resolved, a new alert is only
 * generated on new evidence — the condition met on a later date (the date of
 * the latest counted signal) than the last alert for that student and
 * category. Soft-deleted students are skipped.
 *
 * Each rule only applies to students whose group this school year is at one of
 * its levels (ClickUp 1dfd3mc2wx0); students with no levelled group are in no
 * alert ({@see StudentLevels}).
 *
 * Days are the school's calendar days ({@see SchoolTime}): an observation
 * written at 22:00 in Montevideo counts on that day, not on the next UTC one.
 *
 * The caller must have set the tenant (Tenancy::forSchool) — this runs from a
 * console command with no authenticated user.
 */
class DifficultySignalAlertGenerator
{
    public function __construct(protected StatusChangeNotifier $notifier) {}

    public function generate(?CarbonInterface $today = null): int
    {
        $today = $today === null
            ? SchoolTime::today()
            : CarbonImmutable::parse($today->format('Y-m-d'), SchoolTime::timezone())->startOfDay();

        $rules = AlertRule::query()
            ->where('active', true)
            ->where('condition', AlertCondition::DifficultySignals->value)
            ->whereNotNull('signal_count')
            ->whereNotNull('period_days')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $since = $today->subDays((int) $rules->max('period_days'))->toDateString();
        $series = $this->signalSeries($since);

        if ($series === []) {
            return 0;
        }

        $recipients = AlertRouting::recipientsFor(AlertType::DifficultySignal);
        $categoryNames = CommentCategory::query()->pluck('name', 'id');
        $levels = StudentLevels::forYear($today->year);

        $open = Alert::query()
            ->where('type', AlertType::DifficultySignal->value)
            ->where('resolved', false)
            ->whereNotNull('comment_category_id')
            ->get(['student_id', 'comment_category_id'])
            ->mapWithKeys(fn (Alert $alert) => ["{$alert->student_id}:{$alert->comment_category_id}" => true])
            ->all();

        // Latest date a condition was already reported per (student,
        // category), resolved or not: the same signals must not raise a
        // second alert.
        $lastMetOn = Alert::query()
            ->where('type', AlertType::DifficultySignal->value)
            ->whereNotNull('comment_category_id')
            ->whereNotNull('condition_met_on')
            ->groupBy('student_id', 'comment_category_id')
            ->select(['student_id', 'comment_category_id', DB::raw('MAX(condition_met_on) as last_met_on')])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [
                "{$row->student_id}:{$row->comment_category_id}" => substr((string) $row->last_met_on, 0, 10),
            ])
            ->all();

        $generated = 0;

        foreach ($rules as $rule) {
            $windowStart = $today->subDays((int) $rule->period_days)->toDateString();

            foreach ($series as $studentId => $categories) {
                if (! $rule->appliesToLevels($levels[$studentId] ?? [])) {
                    continue;
                }

                foreach ($categories as $categoryId => $dates) {
                    $key = "{$studentId}:{$categoryId}";

                    if (isset($open[$key])) {
                        continue;
                    }

                    $window = array_values(array_filter($dates, fn (string $on) => $on >= $windowStart));

                    if (count($window) < (int) $rule->signal_count) {
                        continue;
                    }

                    $metOn = end($window);

                    if (isset($lastMetOn[$key]) && $metOn <= $lastMetOn[$key]) {
                        continue;
                    }

                    $alert = Alert::create([
                        'student_id' => $studentId,
                        'comment_category_id' => $categoryId,
                        'alert_rule_id' => $rule->id,
                        'type' => AlertType::DifficultySignal,
                        'severity' => AlertSeverity::Medium,
                        // Only the number and the category — never any
                        // observation text, author or id (CLAUDE.md rule 11).
                        'description' => sprintf(
                            '%d %s en %s',
                            count($window),
                            count($window) === 1 ? 'señal de dificultad' : 'señales de dificultad',
                            $categoryNames[$categoryId] ?? 'una categoría',
                        ),
                        'condition_met_on' => $metOn,
                        'recipients' => $recipients,
                        'resolved' => false,
                    ]);

                    // Ids and enum values only — never the description or any
                    // observation (CLAUDE.md security rules 2 and 11).
                    $this->notifier->notify('alert.generated', [
                        'alert_id' => $alert->id,
                        'student_id' => $alert->student_id,
                        'comment_category_id' => $alert->comment_category_id,
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
     * The school-local dates (`Y-m-d`, chronological) of every difficulty
     * signal on a student observation since the local day `$since`, grouped
     * by student and category.
     * Reads only the pivot and the comment's date — never its content.
     *
     * @return array<int, array<int, list<string>>>
     */
    protected function signalSeries(string $since): array
    {
        $series = [];

        Comment::query()
            ->join('comment_comment_category', 'comment_comment_category.comment_id', '=', 'comments.id')
            ->join('students', 'students.id', '=', 'comments.commentable_id')
            ->where('comments.commentable_type', Student::class)
            ->whereNull('students.deleted_at')
            ->where('comment_comment_category.signal', CommentSignal::Difficulty->value)
            // «Solo quien escribe» never adds to anyone else's alert.
            ->where('comments.author_only', false)
            ->where('comments.created_at', '>=', SchoolTime::startOfDay($since))
            ->orderBy('comments.created_at')
            ->orderBy('comments.id')
            ->select([
                'comments.commentable_id as student_id',
                'comment_comment_category.comment_category_id',
                'comments.created_at as signaled_at',
            ])
            ->toBase()
            ->cursor()
            ->each(function (object $row) use (&$series): void {
                // Stored in the app timezone (UTC); counted on the school's day.
                $series[(int) $row->student_id][(int) $row->comment_category_id][] = CarbonImmutable::parse(
                    (string) $row->signaled_at,
                    (string) config('app.timezone'),
                )->setTimezone(SchoolTime::timezone())->toDateString();
            });

        return $series;
    }
}
