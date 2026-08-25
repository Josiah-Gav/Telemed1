<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\TrackUserPresence;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            TrackUserPresence::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'presence/heartbeat',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Staff invitation and password reset links both submit their one-time
        // secret as a 'token' field. Without this it is flashed back as old
        // input on any validation failure, persisting the live token in the
        // session. Joins the framework defaults rather than replacing them.
        $exceptions->dontFlash(['token']);
    })->create();
