<?php

namespace App\Providers;

use App\Mail\BrevoTransport;
use App\Models\Category;
use App\Models\Person;
use App\Models\StorageLocation;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Policies\ReferenceDataPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Mail::extend('brevo', fn () => new BrevoTransport((string) config('services.brevo.key')));

        foreach (['username-update' => 5, 'google-start' => 10, 'google-callback' => 30, 'google-exchange' => 10,
            'google-link-start' => 5, 'google-link-exchange' => 5, 'password-setup-email' => 3, 'password-setup' => 5] as $name => $limit) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($limit)->by((string) ($request->user()?->id ?? $request->ip())));
        }
        Gate::policy(Person::class, ReferenceDataPolicy::class);
        Gate::policy(Category::class, ReferenceDataPolicy::class);
        Gate::policy(StorageLocation::class, ReferenceDataPolicy::class);
        Gate::policy(User::class, AccountPolicy::class);
    }
}
