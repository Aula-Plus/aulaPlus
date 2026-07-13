<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

/**
 * Returns the currently authenticated user (with school + roles) — used by the
 * SPA to bootstrap its auth state.
 */
class CurrentUserController extends Controller
{
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user()->loadMissing('school'));
    }
}
