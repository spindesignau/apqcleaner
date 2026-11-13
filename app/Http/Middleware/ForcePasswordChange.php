<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users given a temporary password by an admin must set their own before using the app.
 */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password
            && ! $request->routeIs('password.change', 'password.change.update', 'logout', 'locale.switch')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
