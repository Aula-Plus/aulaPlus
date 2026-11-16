<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\CrossingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A «cruce» (screen 30, P30-CRU-02): one or two conditions that must all hold
 * and the reading the school writes for when they do. Never opened to
 * teachers, never written by the AI (P30-DEC-03/04).
 *
 * `conditions` is a list of `{source: {type, id}, operator, value}`
 * (CrossingOperator).
 */
#[Fillable(['name', 'conditions', 'reading', 'created_by_id', 'updated_by_id'])]
class Crossing extends Model
{
    /** @use HasFactory<CrossingFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
        ];
    }
}
