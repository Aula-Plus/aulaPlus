<?php

namespace App\Models;

use App\Enums\ReportDocumentStatus;
use App\Enums\ReportVisibility;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's report (PDF or Word) uploaded by psychopedagogy (ClickUp
 * 86e3jujgx). Holds clinical data about a minor — CLAUDE.md rule 11:
 *
 * - The file lives on the private reports disk (ReportStorage) under a random
 *   name; the original name is metadata only.
 * - The extracted and anonymized page texts are stored encrypted and are
 *   excluded from the audit diff and from every log line.
 * - Visibility is per file ({@see ReportVisibility}); the text is never shown
 *   to teachers whatever the visibility.
 *
 * `pages` is the extracted text per page (a Word file has no pages, so it is
 * split at explicit page breaks); `anonymized_pages` is the same list after
 * automatic anonymization and psychopedagogy's edits — the only text that may
 * ever leave the system, and only after `send_confirmed_at`.
 */
#[Fillable([
    'student_id',
    'uploaded_by_id',
    'path',
    'original_name',
    'format',
    'size',
    'visibility',
    'status',
    'pages',
    'anonymized_pages',
    'use_ai',
    'extracted_at',
    'send_confirmed_at',
    'send_confirmed_by_id',
    'error_message',
])]
class ReportDocument extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['pages', 'anonymized_pages', 'path', 'original_name', 'error_message'];

    protected function casts(): array
    {
        return [
            'visibility' => ReportVisibility::class,
            'status' => ReportDocumentStatus::class,
            'pages' => 'encrypted:array',
            'anonymized_pages' => 'encrypted:array',
            'use_ai' => 'boolean',
            'extracted_at' => 'datetime',
            'send_confirmed_at' => 'datetime',
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

    public function proposals(): HasMany
    {
        return $this->hasMany(AccommodationProposal::class);
    }
}
