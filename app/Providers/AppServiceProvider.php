<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //[]
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {

            return "http://localhost:5173/reset-password?token={$token}&email={$user->email}";

        });
    }
}