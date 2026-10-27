<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // The demo developer's own site is read by the feed importer, so it needs no sessions or cookies.
            Route::prefix('demo')->name('demo.')->group(base_path('routes/demo.php'));

            // Messengers post buyers' messages here; each call carries the channel's secret instead of a session.
            Route::prefix('webhooks')->name('webhooks.')->middleware(SubstituteBindings::class)->group(base_path('routes/webhooks.php'));

            // The chat widget on developers' sites: other origins, no cookies, the visitor's token instead of a session.
            Route::prefix('api/v1/widget')->name('widget.')->middleware(SubstituteBindings::class)->group(base_path('routes/widget.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // The home page is the product page now; an employee who opens the login form again goes to work.
        $middleware->redirectUsersTo(fn (): string => route('catalog'));

        // The tenant must be current before route model binding looks models up.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
