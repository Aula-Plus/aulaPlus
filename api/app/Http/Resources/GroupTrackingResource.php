<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The group tracking view (docs/prompts/04-seguimiento-institucional.md §3).
 * Wraps a plain array assembled by GroupTrackingController.
 *
 * Per-student indicators are deliberately COUNTS/BOOLEANS ONLY — never the
 * underlying clinical detail (accommodation/barrier description, alert
 * description). This is the concrete place CLAUDE.md security rule 11 is at
 * stake for this session:
 *
 *     {"id": ..., "full_name": ..., "open_alerts_count": N,
 *      "has_active_accommodations": bool}
 *
 * `has_active_accommodations` goes to psychopedagogue/director only: for a
 * teacher a yes/no per name is a roster of who has accommodations ("agregado
 * por tipo, nunca nómina", pantallas 02 y 03), so the key is omitted for them
 * and they read the aggregate table (GET /groups/{group}/accommodations-summary).
 * Full clinical content is only ever reachable through
 * /students/{id}/tracking, which re-applies StudentPolicy::viewClinicalProfile
 * per viewer (see StudentTrackingResource).
 *
 * The other role-gated field is the trend `comments_count` (docs/prompts/
 * 19-comentarios-alcance.md §2): "el número ancla, el contenido no" — a
 * teacher may read the *content* of other teachers' comments, but the raw
 * *count* over the period (private comments included) belongs to
 * psychopedagogue/director only. It is omitted entirely (not 0, not null) for
 * anyone outside those school-wide roles, the same "drop the key, never return
 * a falsy value" pattern StudentTrackingResource uses for clinical detail.
 *
 * @mixin array{
 *     group: Group,
 *     students: iterable,
 *     period_days: int,
 *     comments_count: int,
 * }
 */
class GroupTrackingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $schoolWide = $user->hasAnyRole(Role::schoolWideValues());

        return [
            'group' => new GroupResource($this->resource['group']),
            'students' => collect($this->resource['students'])->map(fn (array $summary) => [
                'id' => $summary['id'],
                'full_name' => $summary['full_name'],
                'open_alerts_count' => $summary['open_alerts_count'],
            ] + ($schoolWide ? ['has_active_accommodations' => $summary['has_active_accommodations']] : []))->values(),
            'trend' => [
                'period_days' => $this->resource['period_days'],
                'comments_count' => $this->when(
                    $schoolWide,
                    fn () => $this->resource['comments_count']
                ),
            ],
        ];
    }
}
