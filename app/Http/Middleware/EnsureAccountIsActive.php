<?php

namespace App\Http\Middleware;

use App\Actions\Accounts\BrowserSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isActive()) {
            app(BrowserSession::class)->end($request);
            abort(403, 'This account is inactive.');
        }

        return $next($request);
    }
}
