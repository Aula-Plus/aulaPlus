<?php

namespace App\Models;

use App\Enums\AccommodationCategory;
use App\Enums\ProposalBasis;
use App\Enums\ProposalStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accommodation suggested from a student report (ClickUp 86e3jujgx), by the
 * AI or by hand. It is not in force: only approving it (as is, or modified)
 * creates the Accommodation. The page and fragment let psychopedagogy check it
 * against the source (the page only when it is a real page, see
 * ReportDocument::hasReliablePages); the fragment is anonymized text. The
 * subjects the report points to are pre-loaded for the review, marked as
 * stated (`explicit`) or deduced (`inferred`); none = every subject. Content is clinical
 * (rule 11) and excluded from the audit diff.
 */
#[Fillable([
    'report_document_id',
    'student_id',
    'type',
    'category',
    'description',
    'focus_area',
    'basis',
    'source_page',
    'source_fragment',
    'subject_ids',
    'subjects_basis',
    'status',
    'accommodation_id',
    'reviewed_by_id',
    'reviewed_at',
])]
class AccommodationProposal extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['type', 'description', 'focus_area', 'source_fragment'];

    protected function casts(): array
    {
        return [
            'category' => AccommodationCategory::class,
            'basis' => ProposalBasis::class,
            'status' => ProposalStatus::class,
            'subjects_basis' => ProposalBasis::class,
            'subject_ids' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reportDocument(): BelongsTo
    {
        return $this->belongsTo(ReportDocument::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }
}
