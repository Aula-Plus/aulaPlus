<?php

namespace App\Models;

use App\Enums\StudentStatus;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student record. NOTE: students are NOT platform users — they never log in.
 * They are records managed by the school staff (rosters, profiles, tracking).
 * Skeleton only for now; pedagogical tracking fields come later.
 */
#[Fillable([
    'first_name',
    'last_name',
    'birth_date',
    'group_id',
    'status',
    'family_contact_name',
    'family_contact_phone',
    'family_contact_email',
    'pedagogical_notes',
])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use BelongsToSchool, HasFactory;

    protected $table = 'students';

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'status' => StudentStatus::class,
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }
}
