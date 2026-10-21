<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerifiedUserAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $user->person_id && $user->tokenCan('user'), 403, 'A user account linked to a person record is required.');
        abort_unless($user->hasVerifiedEmail(), 403, 'Verify your email address before using this feature.');

        return $next($request);
    }
}