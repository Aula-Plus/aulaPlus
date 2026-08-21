<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\TechnicalReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * An uploaded technical/psychopedagogical report for a student. Holds
 * sensitive data about a minor — see CLAUDE.md security rule 11: `summary`
 * and `document_url` are excluded from the audit diff, never logged in full.
 * document_url points at a private storage disk; access it only via a
 * temporary signed URL (security rule 7), never a public path.
 *
 * No soft delete / delete endpoint by design (see docs/prompts/03-flujos-
 * aprobacion-trazabilidad.md §2): removing a report requires manual
 * intervention, not an API call.
 */
#[Fillable(['student_id', 'document_url', 'summary', 'attachments', 'uploaded_by_id'])]
class TechnicalReport extends Model
{
    /** @use HasFactory<TechnicalReportFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    public static array $auditableExcludeFromDiff = ['summary', 'document_url'];

    protected static function booted(): void
    {
        // Mirrors TracksAuthorship's creating() hook (Accommodation/Barrier),
        // but under this model's own `uploaded_by_id` column name — there is
        // no deleter field here (no delete endpoint), so the shared trait
        // (which also wires up a deleting() hook) doesn't apply as-is.
        static::creating(function (self $report): void {
            if ($report->getAttribute('uploaded_by_id') === null) {
                $report->setAttribute('uploaded_by_id', (Auth::user() ?? Auth::guard('sanctum')->user())?->id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
