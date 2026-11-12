<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Oferta curricular» (screen 23): one program a group-subject follows. A
 * group-subject may follow several at once — one row each — and always
 * includes an ANEP one (P23-DEC-02). Director-managed; tenant-scoped.
 */
#[Fillable(['group_id', 'subject_id', 'curricular_framework_id'])]
class CurricularOffering extends Model
{
    use Auditable, BelongsToSchool;

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function curricularFramework(): BelongsTo
    {
        return $this->belongsTo(CurricularFramework::class);
    }

    public function scopeForGroupSubject(Builder $query, int $groupId, int $subjectId): Builder
    {
        return $query->where('group_id', $groupId)->where('subject_id', $subjectId);
    }
}
