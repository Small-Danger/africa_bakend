<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PosMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Authentification requise',
            ], 401);
        }

        if (!$request->user()->canAccessPos()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé. Droits caisse ou administrateur requis.',
            ], 403);
        }

        if (!$request->user()->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Compte désactivé',
            ], 403);
        }

        return $next($request);
    }
}
