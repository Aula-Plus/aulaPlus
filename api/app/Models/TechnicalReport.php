<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Database\Factories\TechnicalReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded technical/psychopedagogical report for a student. Holds
 * sensitive data about a minor — see CLAUDE.md security rule 11.
 * document_url points at a private storage disk; access it only via a
 * temporary signed URL (security rule 7), never a public path.
 */
#[Fillable(['student_id', 'document_url', 'summary', 'attachments', 'uploaded_by_id'])]
class TechnicalReport extends Model
{
    /** @use HasFactory<TechnicalReportFactory> */
    use BelongsToSchool, HasFactory;

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
