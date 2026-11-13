<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale priority: signed-in user → session → `locale` cookie → English (APP_LOCALE).
 * The browser's language is deliberately ignored: English is the default and Spanish is chosen with the EN/ES pill.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('apq.locales'));

        $locale = collect([
            $request->user()?->locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->cookie('locale'),
        ])->first(fn ($l) => in_array($l, $supported, true)) ?? config('app.locale');

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
