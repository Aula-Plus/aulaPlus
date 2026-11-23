<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A school resource a group does not have at hand («destildado», PA-085). */
#[Fillable(['group_id', 'school_resource_id'])]
class GroupResourceExclusion extends Model
{
    use BelongsToSchool;
}
