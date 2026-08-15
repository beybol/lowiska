<?php

use App\Http\Middleware\TwoFactorMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\LaravelFlare\Facades\Flare;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'two_factor' => TwoFactorMiddleware::class,
        ]);

        // Cloud Run terminuje TLS na froncie Google; do kontenera trafia zwykły HTTP.
        // at: '*' jest bezpieczne WYŁĄCZNIE dlatego, że do kontenera nie da się dostać
        // z pominięciem tego frontu — i wyłącznie razem z jawną maską poniżej (ADR-003).
        //
        // ⚠️ Maska celowo NIE zawiera HEADER_X_FORWARDED_HOST ani _PREFIX. Domyślna maska
        // Laravela je zawiera, a przy at: '*' oznacza to, że dowolny klient dyktuje host,
        // z którego budowane są adresy absolutne i podpisy URL-i (zatrucie hosta, CWE-644).
        // Nigdy nie wywołuj trustProxies() bez argumentu headers:.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Flare::handles($exceptions);
    })->create();
