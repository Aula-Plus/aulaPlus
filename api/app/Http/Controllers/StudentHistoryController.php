<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditLogResource;
use App\Models\Accommodation;
use App\Models\AuditLog;
use App\Models\Barrier;
use App\Models\Student;
use App\Models\TechnicalReport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only audit timeline for everything tied to a student — accommodations,
 * barriers and technical reports (docs/prompts/03-flujos-aprobacion-
 * trazabilidad.md §5). Same authorization as viewing the student's full
 * clinical profile: director/psychopedagogue only.
 *
 * Paginated with Laravel's standard page-based paginator (?page=N): the
 * response includes `links`/`meta` alongside `data`, same as any other
 * paginated Laravel API endpoint — no cursor pagination here since the
 * timeline is a bounded, page-browsable list rather than an infinite feed.
 */
class StudentHistoryController extends Controller
{
    public function show(Student $student): AnonymousResourceCollection
    {
        $this->authorize('view-clinical-profile', $student);

        $accommodationIds = $student->accommodations()->withTrashed()->pluck('id');
        $barrierIds = $student->barriers()->withTrashed()->pluck('id');
        $reportIds = $student->technicalReports()->pluck('id');

        $logs = AuditLog::query()
            ->where(function ($query) use ($accommodationIds, $barrierIds, $reportIds) {
                $query->where(fn ($q) => $q->where('auditable_type', Accommodation::class)->whereIn('auditable_id', $accommodationIds))
                    ->orWhere(fn ($q) => $q->where('auditable_type', Barrier::class)->whereIn('auditable_id', $barrierIds))
                    ->orWhere(fn ($q) => $q->where('auditable_type', TechnicalReport::class)->whereIn('auditable_id', $reportIds));
            })
            // Order by id, not created_at: audit_logs' timestamp column has
            // only second precision, so two entries written within the same
            // second would otherwise sort arbitrarily. id is monotonically
            // increasing with insertion order on this insert-only table, so
            // it's an exact, tie-free proxy for "most recent first".
            ->orderByDesc('id')
            ->paginate(20);

        return AuditLogResource::collection($logs);
    }
}
