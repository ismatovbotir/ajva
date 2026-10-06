<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Response headers for the public (no-login) monitor: keep the secret link out
 * of Referer headers, search indexes and any cache.
 */
class PublicMonitorHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
