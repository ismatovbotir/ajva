<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Local-network-only endpoints (the 1C items sync and the 1C receipts history migration): no token, the caller's IP must
 * be loopback, a private range, or in the admin's own list (env LOCAL_API_ALLOWED_IPS, comma separated IPs / CIDR ranges,
 * IPv4 and IPv6, for example "203.0.113.5, 100.64.0.0/10"). Use that list when 1C reaches the server through a VPN or a
 * public address that is not a private range. The 403 names the IP the server saw, so it is easy to add.
 *
 * Behind a reverse proxy the client IP only arrives when the proxy is trusted (TrustProxies); otherwise every call
 * looks like it comes from the proxy itself.
 */
class EnsureRequestIsLocal
{
    /**
     * Loopback and private ranges always allowed (IPv4 and IPv6).
     *
     * @var array<int, string>
     */
    protected array $allowedRanges = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    public function handle(Request $request, Closure $next)
    {
        $ip = $request->ip();

        if (! $ip || ! $this->isAllowed($ip)) {
            throw new HttpException(403, 'This endpoint only accepts requests from the local network. The server saw the address '.($ip ?: 'unknown').'; to allow it add it to LOCAL_API_ALLOWED_IPS in .env.');
        }

        return $next($request);
    }

    protected function isAllowed(string $ip): bool
    {
        foreach ([...$this->allowedRanges, ...$this->configured()] as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> the admin's extra IPs / ranges from config('services.local_api.allowed') */
    protected function configured(): array
    {
        $raw = config('services.local_api.allowed');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map('trim', $list), fn ($v) => $v !== ''));
    }

    /** Binary form of an address; an IPv4-mapped IPv6 address (::ffff:a.b.c.d) is treated as its IPv4. */
    protected function packed(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\x00", 10)."\xff\xff")) {
            return substr($bin, 12);
        }

        return $bin;
    }

    protected function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, null);

        $addr = $this->packed($ip);
        $net = $this->packed($subnet);
        if ($addr === null || $net === null || strlen($addr) !== strlen($net)) {
            return false;
        }

        $max = strlen($net) * 8;
        $bits = $bits === null || $bits === '' ? $max : max(0, min($max, (int) $bits));

        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($addr, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($addr[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
