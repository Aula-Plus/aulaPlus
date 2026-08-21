<?php

namespace App\Console\Commands;

use App\Contracts\StatusChangeNotifier;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\CommentTone;
use App\Models\Alert;
use App\Models\Comment;
use App\Models\School;
use App\Models\Student;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Early-alert generation (docs/prompts/04-seguimiento-institucional.md §4).
 * Meant to run daily via the scheduler (see bootstrap/app.php withSchedule).
 *
 * Iterates every school explicitly and scopes the tenant via
 * Tenancy::forSchool() for each one: this is a console context with no
 * authenticated user, so BelongsToSchool can't infer school_id on its own
 * (see Tenancy's class docblock) and SchoolScope would otherwise leave every
 * tenant-scoped query unfiltered.
 */
class GenerateAlerts extends Command
{
    protected $signature = 'alerts:generate';

    protected $description = 'Generate early alerts from recent comment activity (v1 threshold rule)';

    /**
     * Regla simplificada para el piloto. Ajustar umbrales con feedback real de los 3 colegios piloto antes de escalar.
     */
    protected const LOOKBACK_DAYS = 15;

    protected const CONCERNING_COMMENT_THRESHOLD = 3;

    public function handle(StatusChangeNotifier $notifier): int
    {
        $generated = 0;

        School::query()->orderBy('id')->each(function (School $school) use ($notifier, &$generated): void {
            $generated += Tenancy::forSchool($school, fn () => $this->generateForCurrentSchool($notifier));
        });

        $this->info("Generated {$generated} alert(s).");

        return self::SUCCESS;
    }

    protected function generateForCurrentSchool(StatusChangeNotifier $notifier): int
    {
        $since = now()->subDays(self::LOOKBACK_DAYS);

        $studentIdsWithConcerningComments = Comment::query()
            ->where('commentable_type', Student::class)
            ->where('tone', CommentTone::Concerning->value)
            ->where('created_at', '>=', $since)
            ->selectRaw('commentable_id, COUNT(*) as concerning_count')
            ->groupBy('commentable_id')
            ->havingRaw('COUNT(*) >= ?', [self::CONCERNING_COMMENT_THRESHOLD])
            ->pluck('commentable_id');

        $generated = 0;

        foreach ($studentIdsWithConcerningComments as $studentId) {
            $alreadyOpen = Alert::query()
                ->where('student_id', $studentId)
                ->where('type', AlertType::Behavior->value)
                ->where('resolved', false)
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            $alert = Alert::create([
                'student_id' => $studentId,
                'type' => AlertType::Behavior,
                'severity' => AlertSeverity::Medium,
                'description' => sprintf(
                    'Se detectaron %d o más comentarios de tono preocupante en los últimos %d días.',
                    self::CONCERNING_COMMENT_THRESHOLD,
                    self::LOOKBACK_DAYS,
                ),
                'resolved' => false,
            ]);

            $notifier->notify('alert.generated', [
                'alert_id' => $alert->id,
                'student_id' => $alert->student_id,
                'type' => $alert->type->value,
                'severity' => $alert->severity->value,
            ]);

            $generated++;
        }

        return $generated;
    }
}
