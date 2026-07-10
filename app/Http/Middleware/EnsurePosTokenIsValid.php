<?php

namespace App\Http\Middleware;

use App\Models\Pos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsurePosTokenIsValid
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        $pos = $token
            ? Pos::query()->where('api_token_hash', hash('sha256', $token))->first()
            : null;

        if (! $pos) {
            throw new HttpException(401, 'Invalid or missing API token.');
        }

        $request->attributes->set('pos', $pos);

        return $next($request);
    }
}
