<?php

// api/app/Models/UserInvitation.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use, expiring invitation for a not-yet-active user to set their
 * password. The plaintext token lives only in the email; the row stores its
 * SHA-256 hash so a database read never yields a usable token.
 */
class UserInvitation extends Model
{
    protected $fillable = ['user_id', 'token', 'expires_at', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issue a fresh invitation for the user, invalidating any prior pending
     * one, and return the plaintext token (the only place it ever exists
     * outside the email).
     */
    public static function issueFor(User $user, int $days = 7): string
    {
        static::query()
            ->where('user_id', $user->id)
            ->whereNull('accepted_at')
            ->delete();

        $plain = Str::random(64);

        static::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $plain),
            'expires_at' => now()->addDays($days),
        ]);

        return $plain;
    }

    /**
     * Resolve a still-usable invitation from a plaintext token: hash matches,
     * not yet accepted, not expired.
     */
    public static function findPending(string $plain): ?self
    {
        return static::query()
            ->where('token', hash('sha256', $plain))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function markAccepted(): void
    {
        $this->update(['accepted_at' => now()]);
    }
}
