<?php

namespace App\Models;

use App\Enums\PlannerFamily;
use App\Enums\PlannerFieldType;
use Illuminate\Database\Eloquent\Model;

/**
 * A unit-planner field from the global catalog (screen 23 › Planificadores,
 * screen 13): the common base or one an external program asks for
 * (`required_by_program` — a school renames it but never hides it). Global,
 * NOT tenant-scoped, seeded by migration; read-only for the app.
 */
class PlannerFieldDefinition extends Model
{
    protected function casts(): array
    {
        return [
            'family' => PlannerFamily::class,
            'type' => PlannerFieldType::class,
            'options' => 'array',
            'required_by_program' => 'boolean',
            'position' => 'integer',
        ];
    }
}
