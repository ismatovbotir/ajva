<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsurePosTokenIsValid
{
    /**
     * POS terminals share a single token (env POS_API_TOKEN) — it
     * authenticates the request only; the terminal itself is identified
     * by the `pos` id in the payload.
     */
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('services.pos.token');
        $token = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $token)) {
            throw new HttpException(401, 'Invalid or missing API token.');
        }

        return $next($request);
    }
}
