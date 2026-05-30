<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'matrix.token' => \App\Http\Middleware\MatrixAccessToken::class,
            'admin'        => \App\Http\Middleware\AdminMiddleware::class,
            'check.blocked'=> \App\Http\Middleware\CheckBlocked::class,
            'auth.api'     => \App\Http\Middleware\AuthenticateApiRequest::class,
        ]);

        $middleware->appendToGroup('api', [
            \App\Http\Middleware\CheckBlocked::class,
        ]);

        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        });
    })->create();