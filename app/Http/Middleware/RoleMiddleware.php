<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Super Admin has full access
        if ($user->user_type === 'super_admin') {
            return $next($request);
        }

        // Check if user's user_type matches any of the required roles
        $allowedTypes = array_map(fn($r) => strtolower(str_replace(' ', '_', $r)), $roles);
        if (in_array($user->user_type, $allowedTypes)) {
            return $next($request);
        }

        return response()->json(['message' => 'Access Denied: Admin or Super Admin role required.'], 403);
    }
}
