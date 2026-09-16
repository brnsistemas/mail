<?php

use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['mfa' => RequireMfa::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->validateCsrfTokens(except: ['webhooks/resend']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'code', 'token', 'api_key', 'webhook_secret', 'body_text', 'subject', 'to', 'cc', 'bcc']);
        $exceptions->report(function (Throwable $e) {
            // Never log request bodies, SQL bindings, headers or provider exception text.
            Log::warning('brnmail.error', ['exception_class' => get_class($e)]);

            return false;
        });
    })->create();
