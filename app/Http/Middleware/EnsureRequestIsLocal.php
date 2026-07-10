<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureRequestIsLocal
{
    /**
     * Private/loopback IPv4 ranges allowed to call local-only endpoints.
     *
     * @var array<int, string>
     */
    protected array $allowedRanges = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $ip = $request->ip();

        if (! $ip || ! $this->isAllowed($ip)) {
            throw new HttpException(403, 'This endpoint only accepts requests from the local network.');
        }

        return $next($request);
    }

    protected function isAllowed(string $ip): bool
    {
        foreach ($this->allowedRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    protected function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $range), 2, '32');

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $bits = (int) $bits;
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
