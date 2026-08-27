<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Restrict a route to one or more user roles.
     * SuperAdmin satisfies any route that allows `admin`.
     * Usage: ->middleware('role:admin') or ->middleware('role:admin,barangay_official')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        if (in_array($user->role, $roles, true)) {
            return $next($request);
        }

        if ($user->role === User::ROLE_SUPER_ADMIN && in_array(User::ROLE_ADMIN, $roles, true)) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }
}
