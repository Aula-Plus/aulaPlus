<?php

namespace App\Models;

use App\Enums\ResourceVisibility;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A resource added to one group (e.g. a TV in its classroom, PA-085): for the
 * whole group or only for the teacher who added it (`visibility`). Not to be
 * confused with `App\Http\Resources\GroupResource` (the group's API resource).
 */
#[Fillable(['group_id', 'name', 'visibility', 'created_by_id'])]
class GroupAddedResource extends Model
{
    use Auditable, BelongsToSchool;

    protected function casts(): array
    {
        return ['visibility' => ResourceVisibility::class];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
