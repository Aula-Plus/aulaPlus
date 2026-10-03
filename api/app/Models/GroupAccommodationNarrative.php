<?php

namespace App\Models;

use App\Enums\AccommodationNarrativeStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI text summary of a group's active accommodations. Never names a student:
 * it is generated from the aggregated summary and discarded if it does.
 */
#[Fillable([
    'group_id',
    'status',
    'content',
    'fingerprint',
    'error_message',
    'generated_by_id',
    'published_by_id',
    'published_at',
])]
class GroupAccommodationNarrative extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['content'];

    protected function casts(): array
    {
        return [
            'status' => AccommodationNarrativeStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }
}
