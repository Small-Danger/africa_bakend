<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

class DisableCsrfForCustomDomains extends VerifyCsrfToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // Vérifier si c'est un domaine personnalisé
        $isCustomDomain = in_array($request->getHost(), [
            'api.afrikraga.com',
            'afrikraga.com', 
            'www.afrikraga.com'
        ]);

        // Pour les domaines personnalisés, vérifier CSRF seulement pour les routes sensibles
        if ($isCustomDomain) {
            // Routes qui nécessitent CSRF même sur les domaines personnalisés
            $csrfRequiredRoutes = [
                'api/admin/*',
                'api/products/*',
                'api/categories/*',
                'api/banners/*',
                'api/orders/*'
            ];

            $currentPath = $request->path();
            $needsCsrf = false;

            foreach ($csrfRequiredRoutes as $route) {
                if (fnmatch($route, $currentPath)) {
                    $needsCsrf = true;
                    break;
                }
            }

            // Si CSRF n'est pas requis, passer directement
            if (!$needsCsrf) {
                return $next($request);
            }
        }
        
        // Utiliser la vérification CSRF normale pour les autres cas
        return parent::handle($request, $next);
    }
}
