<?php

namespace App\Models;

use App\Enums\ScreeningColor;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\ScreeningTestResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's slot in a screening-test application (docs/prompts/
 * 11-pruebas-de-sondeo.md §§2-3). `score`/`color` start null and are filled in
 * by psychopedagogy; `color` is persisted at load time, not derived on read.
 *
 * `student_id` links the anonymous `code` back to a real minor — sensitive
 * (CLAUDE.md security rule 11). It is only ever surfaced through the roster
 * endpoint; the API resource for this model deliberately omits it.
 */
#[Fillable([
    'screening_test_application_id',
    'student_id',
    'code',
    'score',
    'color',
    'loaded_by_id',
    'loaded_at',
])]
class ScreeningTestResult extends Model
{
    /** @use HasFactory<ScreeningTestResultFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    /**
     * `student_id` is the code -> minor mapping; keep it out of the audit diff
     * so the timeline never records which anonymous code belongs to whom
     * (CLAUDE.md security rule 11).
     */
    public static array $auditableExcludeFromDiff = ['student_id'];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'color' => ScreeningColor::class,
            'loaded_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScreeningTestApplication::class, 'screening_test_application_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function loadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loaded_by_id');
    }
}
