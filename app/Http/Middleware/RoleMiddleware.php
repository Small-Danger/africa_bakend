<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentification requise',
            ], 401);
        }

        if (! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Compte désactivé',
            ], 403);
        }

        if ($user->hasAnyRole($roles)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Accès non autorisé',
        ], 403);
    }
}
