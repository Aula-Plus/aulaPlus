<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Creates a real staff user interactively — the supported way to onboard the
 * first user without hardcoding credentials anywhere (security rule #1).
 *
 * Usage: php artisan app:create-user
 */
class CreateUser extends Command
{
    protected $signature = 'app:create-user';

    protected $description = 'Create a staff user (teacher/director/psychopedagogue) in a school';

    public function handle(): int
    {
        $school = $this->resolveSchool();

        $name = text('Full name', required: true);

        $email = text('Email', required: true, validate: function (string $value) use ($school) {
            $exists = Tenancy::forSchool($school, fn () => User::where('email', $value)->exists());

            return $exists ? 'A user with that email already exists.' : null;
        });

        $roleValue = select('Role', options: array_combine(Role::values(), Role::values()));

        $plain = password('Password', validate: fn (string $value) => $this->validatePassword($value));

        $user = Tenancy::forSchool($school, function () use ($school, $name, $email, $roleValue, $plain) {
            $user = User::create([
                'school_id' => $school->id,
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($plain),
            ]);

            $user->assignRole($roleValue);

            return $user;
        });

        $this->info("Created user #{$user->id} ({$email}) as {$roleValue} in \"{$school->name}\".");

        return self::SUCCESS;
    }

    protected function resolveSchool(): School
    {
        $schools = School::orderBy('name')->get();

        if ($schools->isEmpty()) {
            $this->info('No schools yet — creating one.');
            $name = text('School name', required: true);

            return School::create([
                'name' => $name,
                'slug' => str($name)->slug()->append('-'.random_int(1000, 9999)),
            ]);
        }

        $id = select(
            'School',
            options: $schools->pluck('name', 'id')->all(),
        );

        return $schools->firstWhere('id', $id);
    }

    protected function validatePassword(string $value): ?string
    {
        try {
            validator(
                ['password' => $value],
                ['password' => ['required', Password::min(8)->letters()->numbers()]],
            )->validate();
        } catch (ValidationException $e) {
            return $e->validator->errors()->first('password');
        }

        return null;
    }
}
