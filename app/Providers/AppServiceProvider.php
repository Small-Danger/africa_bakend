<?php

namespace App\Providers;

use App\Mail\BrevoTransport;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('brevo', function () {
            return new BrevoTransport((string) config('services.brevo.key'));
        });

        Gate::before(function ($user, string $ability) {
            if ($user instanceof User && $user->hasPermissionTo($ability)) {
                return true;
            }

            return null;
        });
    }
}
