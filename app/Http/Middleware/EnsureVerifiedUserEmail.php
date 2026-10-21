<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerifiedUserEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasVerifiedEmail(), 403, 'Verify your email address before using this feature.');
        return $next($request);
    }
}