<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\TrackUserPresence;
use Illuminate\Http\Request;

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

        // ngrok terminates TLS and forwards a plain HTTP connection to Apache
        // on the loopback interface, adding X-Forwarded-* headers. Without
        // trusting that loopback proxy, Symfony's Request::isSecure() only
        // checks $_SERVER['HTTPS'] (unset) and Laravel generates http://
        // asset/route URLs even though the browser is on https://, causing
        // mixed-content errors for @vite() assets.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Staff invitation and password reset links both submit their one-time
        // secret as a 'token' field. Without this it is flashed back as old
        // input on any validation failure, persisting the live token in the
        // session. Joins the framework defaults rather than replacing them.
        $exceptions->dontFlash(['token']);
    })->create();
