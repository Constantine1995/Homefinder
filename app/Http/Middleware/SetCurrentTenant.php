<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the employee's developer the current tenant, so that every query of the request sees that developer's rows only.
 */
class SetCurrentTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->user()?->loadMissing('tenant')->tenant->makeCurrent();

        return $next($request);
    }
}
