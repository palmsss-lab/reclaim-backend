<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $user->person_id && ! $user->staff_id && $user->tokenCan('user'), 403, 'A user account linked to a person record is required.');

        return $next($request);
    }
}
