<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * Checks that the authenticated user has the specified permission.
     * Super admin users bypass all permission checks.
     * Returns 403 if the user does not have the required permission.
     *
     * Usage in routes: ->middleware('permission:manage_roles')
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission  The permission name to check
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Super admin bypasses all permission checks (Property 2: Super Admin Permission Bypass)
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Check if the permission is in the user's effective permissions
        $effectivePermissions = $user->getEffectivePermissions();

        if (!in_array($permission, $effectivePermissions)) {
            return response()->json([
                'message' => "Access denied. Required permission: {$permission}",
            ], 403);
        }

        return $next($request);
    }
}
