<?php

use App\Http\Middleware\EnsureActiveAdmin;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentBranch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetCurrentBranch::class,
            EnsureActiveAdmin::class,
            HandleInertiaRequests::class,
        ]);

        // The current branch must be known before route model binding, so
        // {employee} etc. resolve through the BelongsToBranch scope (404 for other branches).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentBranch::class);

        $middleware->alias([
            'permission' => EnsurePermission::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
