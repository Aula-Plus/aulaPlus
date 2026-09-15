<?php

// api/app/Http/Controllers/InvitationController.php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptInvitationRequest;
use App\Models\UserInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Public, token-gated endpoints for accepting a staff invitation. The invited
 * user has no session yet, so these routes are deliberately unauthenticated
 * (security rule #4): the single-use, hashed, expiring token IS the gate. They
 * are rate-limited in the route definition.
 */
class InvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = UserInvitation::findPending($token);

        if ($invitation === null) {
            // 410 Gone: the link was never valid, already used, or expired.
            throw new HttpException(410, 'Esta invitación ya no es válida.');
        }

        $user = $invitation->user()->with('school')->first();

        return response()->json(['data' => [
            'email' => $user->email,
            'school_name' => $user->school?->name,
        ]]);
    }

    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $invitation = UserInvitation::findPending($token);

        if ($invitation === null) {
            throw new HttpException(410, 'Esta invitación ya no es válida.');
        }

        $invitation->user->update([
            'password' => Hash::make($request->validated('password')),
            'email_verified_at' => now(),
        ]);

        $invitation->markAccepted();

        return response()->json(['message' => 'Tu cuenta fue activada. Ya podés iniciar sesión.']);
    }
}
