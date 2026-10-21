<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\NoStorePrivateApiResponses;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function ($response, \Throwable $error, Request $request) {
            if (in_array(NoStorePrivateApiResponses::class, $request->route()?->gatherMiddleware() ?? [], true)) {
                $response->headers->set('Cache-Control', 'private, no-store');
            }

            return $response;
        });
        $exceptions->render(function (HttpExceptionInterface $error, Request $request) {
            if ($request->routeIs('user.email.verify') && $request->prefers(['application/json', 'text/html']) === 'text/html'
                && in_array($error->getStatusCode(), [403, 404], true)) {
                return response()->view('auth.verification-result', ['verified' => false], $error->getStatusCode())
                    ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
            }
        });
    })->create();
