<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Place the user in an existing school (instead of creating a new one).
     */
    public function forSchool(School $school): static
    {
        return $this->state(['school_id' => $school->id]);
    }

    /**
     * Assign a role once the user has been created.
     */
    public function role(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }

    public function teacher(): static
    {
        return $this->role(Role::Teacher);
    }

    public function director(): static
    {
        return $this->role(Role::Director);
    }

    public function psychopedagogue(): static
    {
        return $this->role(Role::Psychopedagogue);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
