<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Handles first-party SPA session login/logout via Sanctum (cookie-based).
 * No shared/hardcoded accounts — every session belongs to one real user
 * (security rule #1).
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Authenticate and start a session for the SPA.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return response()->json([
            'user' => new UserResource($request->user()->loadMissing('school')),
        ]);
    }

    /**
     * Destroy the authenticated session.
     */
    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        // A pure bearer-token client authenticates without a session; only tear
        // down session state when the request actually carries one (the SPA does).
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Sesión cerrada.']);
    }
}
