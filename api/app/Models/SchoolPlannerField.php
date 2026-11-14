<?php

namespace App\Models;

use App\Enums\PlannerFamily;
use App\Enums\PlannerFieldType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A school's planner configuration (screen 23 › Planificadores). With
 * `planner_field_definition_id`: the school's name for a catalog field and
 * whether it is shown. Without it: a field of the school's own, with its
 * `name`, `type`, `options`, `family` (null = every framework), a short
 * `description` of what it means for the school, and its `purpose`: `ai`
 * (the AI reads it) or `record` (only kept). How the AI uses them is decided
 * in the AI task (ClickUp 86e3jujh0); here the school only records them.
 */
#[Fillable([
    'planner_field_definition_id',
    'family',
    'name',
    'description',
    'purpose',
    'type',
    'options',
    'shown',
    'required_by_school',
    'position',
])]
class SchoolPlannerField extends Model
{
    public const PURPOSES = ['ai', 'record'];

    use Auditable, BelongsToSchool;

    protected function casts(): array
    {
        return [
            'family' => PlannerFamily::class,
            'type' => PlannerFieldType::class,
            'options' => 'array',
            'shown' => 'boolean',
            'required_by_school' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(PlannerFieldDefinition::class, 'planner_field_definition_id');
    }

    public function isCustom(): bool
    {
        return $this->planner_field_definition_id === null;
    }
}
