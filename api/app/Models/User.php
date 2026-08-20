<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A staff member (teacher, director or psychopedagogue). Every user belongs to
 * exactly one school. Roles are assigned via Spatie (see {@see Role}).
 *
 * Unlike business models, User does NOT use the SchoolScope global scope: the
 * auth guard resolves the current user before a tenant exists, and scoping User
 * by the current school would recurse. Cross-school leakage of user rows is
 * instead prevented explicitly in queries/policies.
 */
#[Fillable(['name', 'email', 'password', 'school_id', 'photo_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function calendar(): HasOne
    {
        return $this->hasOne(Calendar::class);
    }

    /**
     * Groups this user teaches (M:N via group_teacher).
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_teacher', 'teacher_id', 'group_id')
            ->withPivot('details')
            ->withTimestamps();
    }

    public function annualPlans(): HasMany
    {
        return $this->hasMany(AnnualPlan::class, 'teacher_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'teacher_id');
    }
}
