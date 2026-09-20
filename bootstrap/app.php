<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Jamais route('login') : cette route n'existe pas et fait planter l'API (500 HTML).
        $middleware->redirectGuestsTo(fn () => '/');

        // Enregistrer les middlewares
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'large.upload' => \App\Http\Middleware\LargeFileUpload::class,
            'pos' => \App\Http\Middleware\PosMiddleware::class,
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);

        // Désactiver CSRF pour toutes les routes API (solution simple)
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'sanctum/*',
        ]);

        // Ajouter la session aux routes API pour l'authentification admin
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);

        $middleware->web(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
