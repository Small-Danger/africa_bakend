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
        // Désactiver CSRF pour les domaines personnalisés
        if (in_array($request->getHost(), [
            'api.afrikraga.com',
            'afrikraga.com', 
            'www.afrikraga.com',
            'africafrontend-production.up.railway.app',
            'web-production-7228.up.railway.app'
        ])) {
            return $next($request);
        }
        
        // Utiliser la vérification CSRF normale pour les autres domaines
        return parent::handle($request, $next);
    }
}
